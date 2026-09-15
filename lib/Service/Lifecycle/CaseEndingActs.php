<?php

/**
 * Ending a case is four acts, not one verb with different text.
 *
 * A vergunning that is granted, an aanvraag that is withdrawn, a bezwaar
 * declared niet-ontvankelijk and a case reopened after a beroep differ in what
 * is archived, what the citizen is told, and who is allowed to do it. dossiq
 * had the verbs and no place that told them apart, so a handler found out what
 * they could do by trying.
 *
 * - **Finish**: the case reached its result. The result type decides retention.
 * - **Abort**: an intrekking or a niet-ontvankelijkverklaring. There is a
 *   result and it is NOT a besluit.
 * - **Archive**: the case is done being read and moves to its retention rule,
 *   written from the result type by the one deriver both closing routes use.
 * - **Reopen** stays on {@see \OCA\Dossiq\Service\CaseLifecycleService}, where
 *   it already was. What this class gives it is {@see self::endingOf()}: the
 *   record of which act ended the case, so reopening can keep it.
 *
 * 🔴 COLLAPSING THEM IS HOW A WITHDRAWN AANVRAAG ENDS UP ARCHIVED AS A GRANTED
 * ONE. The archival consequence is the reason the split is not vocabulary:
 * finishing writes the nomination the result type carries, aborting writes a
 * result that is explicitly not a besluit, and archiving is the act that
 * actually commits the retention. One verb with a dropdown would let any of
 * the three be recorded as any other.
 *
 * 🔑 THE GUARDS STILL RUN ON AN EARLY CLOSE. Closing before the phases are
 * complete skips the PHASES, never the checks: the result is still required,
 * the result type is still resolved through {@see CaseResultWriter}, and the
 * phases that were never reached are recorded on the case. A close that cannot
 * name its result is refused whether it is early or not.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use Psr\Log\LoggerInterface;

/**
 * Finish, abort and archive, each with its own permission and its own record.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseEndingActs {

	/**
	 * The journal types this class writes, and the ones reopening looks for.
	 *
	 * @var list<string>
	 */
	public const ENDING_TYPES = ['finish', 'abort', 'archive', 'autoClose'];

	/**
	 * The field recording which act ended the case.
	 *
	 * @var string
	 */
	public const ENDING_FIELD = 'endingAct';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads and writes the case, and the status record.
	 * @param CaseResultWriter $results Resolves the closing result and the archival future.
	 * @param StatusTypeLookup $statuses The case type's statuses, with their flags.
	 * @param LifecycleActorGate $gate Whether this caller may perform this act.
	 * @param CaseJournal $journal The case's own record of what was done to it.
	 * @param LoggerInterface $logger Records what was ended and by whom.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseResultWriter $results,
		private readonly StatusTypeLookup $statuses,
		private readonly LifecycleActorGate $gate,
		private readonly CaseJournal $journal,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Finish the case: it reached its result.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why, recorded on the case.
	 * @param string $resultTypeId The result type the handler picked.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @throws RefusedException When the caller may not finish, the case is already
	 *                          ended, or no result can be named.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function finish(string $caseId, string $reason, string $resultTypeId, string $toStatus = ''): array {
		return $this->end(
			act: 'finish',
			caseId: $caseId,
			reason: $reason,
			resultTypeId: $resultTypeId,
			toStatus: $toStatus,
		);
	}//end finish()

	/**
	 * Abort the case: an intrekking, and no besluit.
	 *
	 * The difference from {@see self::finish()} is what the record says, and
	 * that is the whole difference the citizen ever sees. An abort writes a
	 * result, because an aborted case still has an outcome somebody can read
	 * afterwards, and it writes no besluit and no decision: an intrekking is
	 * not a decision that was taken.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why the case was aborted.
	 * @param string $resultTypeId The result type, e.g. Ingetrokken.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @throws RefusedException When the caller may not abort, or no result can be named.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function abort(string $caseId, string $reason, string $resultTypeId, string $toStatus = ''): array {
		return $this->end(
			act: 'abort',
			caseId: $caseId,
			reason: $reason,
			resultTypeId: $resultTypeId,
			toStatus: $toStatus,
		);
	}//end abort()

	/**
	 * Archive a finished case: commit the retention rule its result type carries.
	 *
	 * A case that is not ended cannot be archived. That refusal is the point:
	 * archiving an open case would put a destruction date on work somebody is
	 * still doing, which is the shape of mistake that gets a record destroyed
	 * early.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being archived.
	 *
	 * @return array<string, mixed> The nomination and action date that were written.
	 *
	 * @throws RefusedException When the caller may not archive, or the case is still open.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function archive(string $caseId, string $reason): array {
		$case = $this->load(caseId: $caseId);
		$this->requireReason(reason: $reason);
		$this->gate->require(act: 'archive', case: $case);

		if ($this->isEnded(case: $case) === false) {
			throw new RefusedException(
				rule: 'case-not-ended',
				sentence: 'Finish or abort this case before archiving it.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$endDate = (string)($case['endDate'] ?? '');
		if ($endDate === '') {
			$endDate = (new DateTimeImmutable())->format('Y-m-d');
		}

		$future = $this->results->archivalFuture(
			case: $case,
			resultTypeId: $this->resultTypeOn(case: $case),
			endDate: $endDate,
		);

		$case = array_merge($case, $future);
		$case['archiveStatus'] = 'archived';
		if (($future['archiveActionDate'] ?? '') === '') {
			// zrc-021 leaves the date underivable when the result type states
			// no period. The case is archived either way and an archivist can
			// see which ones carry no date, which is a better answer than
			// refusing the act or inventing a destruction date.
			$case['archiveStatus'] = 'archived_retention_period_unknown';
		}

		$case = $this->journal->append(
			case: $case,
			entry: [
				'type' => 'archive',
				'reason' => $reason,
				'archiveNomination' => (string)($future['archiveNomination'] ?? ''),
				'archiveActionDate' => (string)($future['archiveActionDate'] ?? ''),
			],
		);
		$this->store->saveCase(case: $case);

		$this->logger->info(
			'CaseEndingActs: case archived',
			['caseId' => $caseId, 'nomination' => ($future['archiveNomination'] ?? null)],
		);

		return [
			'caseId' => $caseId,
			'act' => 'archive',
			'archiveStatus' => $case['archiveStatus'],
			'archiveNomination' => ($future['archiveNomination'] ?? null),
			'archiveActionDate' => ($future['archiveActionDate'] ?? null),
		];
	}//end archive()

	/**
	 * The ending this case carries, for a reopen that has to keep it.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<string, mixed> `act`, `at`, `by` and `reason`, empty when the
	 *                              case was never ended by one of these acts.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function endingOf(array $case): array {
		$entry = $this->journal->latest(case: $case, types: self::ENDING_TYPES);
		if ($entry === []) {
			return [];
		}

		return [
			'act' => (string)($entry['type'] ?? ''),
			'at' => (string)($entry['at'] ?? ''),
			'by' => (string)($entry['by'] ?? ''),
			'reason' => (string)($entry['reason'] ?? ''),
		];
	}//end endingOf()

	/**
	 * The one implementation behind finish and abort.
	 *
	 * The two acts differ in their permission, in what the record says, and in
	 * nothing else mechanical. Writing them twice would let the two drift on
	 * the parts that are supposed to be identical: the result is required for
	 * both, the skipped phases are recorded for both, and the guards run for
	 * both.
	 *
	 * @param string $act `finish` or `abort`.
	 * @param string $caseId The case UUID.
	 * @param string $reason Why.
	 * @param string $resultTypeId The result type the handler picked.
	 * @param string $toStatus The terminal status, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @throws RefusedException When refused.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function end(string $act, string $caseId, string $reason, string $resultTypeId, string $toStatus): array {
		$case = $this->load(caseId: $caseId);
		$this->requireReason(reason: $reason);
		$this->gate->require(act: $act, case: $case);

		$caseTypeId = (string)($case['caseType'] ?? '');
		$fromStatus = (string)($case['status'] ?? '');
		$target = $this->terminalStatus(caseTypeId: $caseTypeId, named: $toStatus);

		// The result is resolved BEFORE anything is written. `resolveClosingResult`
		// refuses a close with no result on a case type that offers some, and a
		// refusal after the status had moved would leave a case closed with no
		// outcome, which is the state nobody can read afterwards.
		$resultId = $this->results->resolveClosingResult(
			caseId: $caseId,
			caseTypeId: $caseTypeId,
			resultTypeId: ($resultTypeId === '' ? null : $resultTypeId),
		);

		$endDate = (new DateTimeImmutable())->format('Y-m-d');
		$skipped = $this->skippedPhases(caseTypeId: $caseTypeId, fromStatus: $fromStatus, toStatus: $target);

		$case['status'] = $target;
		$case['endDate'] = $endDate;
		$case[self::ENDING_FIELD] = $act;
		if ($resultId !== null) {
			$case['result'] = $resultId;
		}

		if ($skipped !== []) {
			$case['skippedPhases'] = json_encode($skipped);
		}

		if ($act === 'finish' && $resultTypeId !== '') {
			// Only finishing takes the nomination from the result type here.
			// An abort has a result and no besluit, and its retention is
			// committed by the archive act, which is where an archivist is.
			$case = array_merge($case, $this->results->archivalFuture(
				case: $case,
				resultTypeId: $resultTypeId,
				endDate: $endDate,
			));
		}

		$case = $this->journal->append(
			case: $case,
			entry: [
				'type' => $act,
				'reason' => $reason,
				'resultType' => $resultTypeId,
				'skippedPhases' => $skipped,
				'besluit' => ($act === 'finish'),
			],
		);
		$this->store->saveCase(case: $case);

		$this->store->writeStatusRecord(
			caseId: $caseId,
			toStatus: $target,
			fromStatus: $fromStatus,
			label: ucfirst($act),
			comment: $reason,
			evaluatedGuards: [],
			noWorkflowTemplate: true,
		);

		$this->logger->info(
			'CaseEndingActs: case ended',
			['caseId' => $caseId, 'act' => $act, 'skipped' => count($skipped)],
		);

		return [
			'caseId' => $caseId,
			'act' => $act,
			'status' => $target,
			'endDate' => $endDate,
			'result' => $resultId,
			'skippedPhases' => $skipped,
		];
	}//end end()

	/**
	 * The terminal status the act lands the case on.
	 *
	 * A named status is honoured when it belongs to the case type and is
	 * terminal. An unnamed one resolves to the case type's lowest-ordered
	 * terminal status, which is the one the process was drawn to end on.
	 *
	 * @param string $caseTypeId The case type UUID.
	 * @param string $named The status the caller asked for, possibly empty.
	 *
	 * @return string The statusType UUID to land on.
	 *
	 * @throws RefusedException When the case type declares no terminal status.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function terminalStatus(string $caseTypeId, string $named): string {
		$terminal = [];
		foreach ($this->statuses->rowsOf(caseTypeId: $caseTypeId) as $row) {
			if ($this->isFinalRow(row: $row) === false) {
				continue;
			}

			$terminal[] = $row;
		}

		if ($terminal === []) {
			throw new RefusedException(
				rule: 'no-terminal-status',
				sentence: 'This case type has no status to end on.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		foreach ($terminal as $row) {
			if ($this->idOf(row: $row) === $named && $named !== '') {
				return $named;
			}
		}

		usort($terminal, fn (array $left, array $right): int => $this->orderOf(row: $left) <=> $this->orderOf(row: $right));

		return $this->idOf(row: $terminal[0]);
	}//end terminalStatus()

	/**
	 * The phases the case never reached, by name.
	 *
	 * Ordered statuses between the one the case is leaving and the terminal one
	 * it lands on. A case closed from its last phase skips nothing and records
	 * nothing, which is why an ordinary close does not gain a field.
	 *
	 * @param string $caseTypeId The case type UUID.
	 * @param string $fromStatus The status the case is leaving.
	 * @param string $toStatus The terminal status it lands on.
	 *
	 * @return list<string> The skipped phase names, in process order.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function skippedPhases(string $caseTypeId, string $fromStatus, string $toStatus): array {
		$rows = $this->statuses->rowsOf(caseTypeId: $caseTypeId);
		usort($rows, fn (array $left, array $right): int => $this->orderOf(row: $left) <=> $this->orderOf(row: $right));

		$seen = false;
		$skipped = [];
		foreach ($rows as $row) {
			$id = $this->idOf(row: $row);
			if ($id === $fromStatus) {
				$seen = true;
				continue;
			}

			if ($id === $toStatus) {
				break;
			}

			if ($seen === true && $this->isFinalRow(row: $row) === false) {
				$skipped[] = (string)($row['name'] ?? ($row['title'] ?? $id));
			}
		}

		return $skipped;
	}//end skippedPhases()

	/**
	 * Whether the case has already been ended by one of these acts.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return bool True when the case carries an ending.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function isEnded(array $case): bool {
		return (trim((string)($case[self::ENDING_FIELD] ?? '')) !== '');
	}//end isEnded()

	/**
	 * The result type on the case, for the archive act.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return string The result type UUID, empty when the case names none.
	 *
	 * @spec exclude one field read behind archive(), which carries the requirement
	 */
	private function resultTypeOn(array $case): string {
		$entry = $this->journal->latest(case: $case, types: ['finish', 'abort', 'autoClose']);

		return trim((string)($entry['resultType'] ?? ''));
	}//end resultTypeOn()

	/**
	 * Load the case, or refuse.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec exclude one read behind every act in this class
	 */
	private function load(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end load()

	/**
	 * Every ending act records why.
	 *
	 * @param string $reason The reason the caller gave.
	 *
	 * @return void
	 *
	 * @throws RefusedException When it is empty.
	 *
	 * @spec exclude one guard shared by every act in this class
	 */
	private function requireReason(string $reason): void {
		if (trim($reason) === '') {
			throw new RefusedException(
				rule: 'reason-required',
				sentence: 'Give a reason first.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end requireReason()

	/**
	 * Whether a statusType row is terminal.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return bool True when it carries isFinal.
	 *
	 * @spec exclude a JSON boolean coercion shared by the two readers above
	 */
	private function isFinalRow(array $row): bool {
		return in_array(($row['isFinal'] ?? false), [true, 1, '1', 'true'], true);
	}//end isFinalRow()

	/**
	 * A statusType row's place in the process.
	 *
	 * An unordered status sorts last rather than first, the same reading the
	 * stages stepper uses: a status nobody placed in the process is not step
	 * one.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return int The order.
	 *
	 * @spec exclude a sort key shared by the two readers above
	 */
	private function orderOf(array $row): int {
		$order = ($row['order'] ?? null);
		if (is_numeric($order) === false) {
			return PHP_INT_MAX;
		}

		return (int)$order;
	}//end orderOf()

	/**
	 * A statusType row's id, whichever shape it came back in.
	 *
	 * @param array<string, mixed> $row The statusType row.
	 *
	 * @return string The id.
	 *
	 * @spec exclude an id read shared by the two readers above
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()
}//end class
