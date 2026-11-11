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
use OCA\Dossiq\Service\Support\LanguageMapText;
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
	use LoadsCaseOrRefuses;


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
	 * @param CaseIncompleteness $incompleteness Refuses a finish on a case missing required data.
	 * @param CaseJournal $journal The case's own record of what was done to it.
	 * @param LoggerInterface $logger Records what was ended and by whom.
	 * @param CaseArchiveState $archiveState The platform's archive marker, which
	 *                                       is the state the lists read.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly CaseResultWriter $results,
		private readonly StatusTypeLookup $statuses,
		private readonly LifecycleActorGate $gate,
		private readonly CaseIncompleteness $incompleteness,
		private readonly CaseJournal $journal,
		private readonly LoggerInterface $logger,
		private readonly CaseArchiveState $archiveState,
		private readonly LanguageMapText $text = new LanguageMapText(),
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
			request: ['caseId' => $caseId, 'reason' => $reason, 'resultTypeId' => $resultTypeId, 'toStatus' => $toStatus],
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
			request: ['caseId' => $caseId, 'reason' => $reason, 'resultTypeId' => $resultTypeId, 'toStatus' => $toStatus],
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
		$case = $this->archivableCase(caseId: $caseId, reason: $reason);
		$future = $this->archivalFutureOf(case: $case);
		$nomination = $this->nominationOf(future: $future);
		$entry = ['type' => 'archive', 'reason' => $reason] + array_map('strval', $nomination);
		$case = $this->saveWithEntry(case: $this->archivedCase(case: $case, future: $future), entry: $entry);

		// The ZGW field is written first and the marker goes on last, because
		// an archived object refuses every write to its data. Reversed, the
		// save above would be refused by the archive this act had just put on,
		// and the case would sit out of the lists with `archiveStatus` still
		// reading `nog_te_archiveren`.
		$marker = $this->archiveState->mark(caseId: $caseId, reason: $reason);

		$this->logger->info(
			'CaseEndingActs: case archived',
			['caseId' => $caseId, 'nomination' => $nomination['archiveNomination']],
		);

		return ['caseId' => $caseId, 'act' => 'archive', 'archiveStatus' => $case['archiveStatus']]
			+ $nomination
			+ ['archived' => ($marker['archived'] ?? null)];
	}//end archive()

	/**
	 * Load a case for the archive act and run its guards: a reason, the role, an ended case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being archived.
	 *
	 * @return array<string, mixed> The loaded case.
	 *
	 * @throws RefusedException When the caller may not archive, or the case is still open.
	 *
	 * @spec exclude the guards of archive(), which carries the requirement
	 */
	private function archivableCase(string $caseId, string $reason): array {
		$case = $this->load(caseId: $caseId);
		$this->requireReason(reason: $reason);
		$this->gate->require(act: 'archive', case: $case);
		$this->requireEnded(case: $case);

		return $case;
	}//end archivableCase()

	/**
	 * The archival future the case's result type gives it from its end date.
	 *
	 * @param array<string, mixed> $case The ended case.
	 *
	 * @return array<string, mixed> The nomination and action date from the result type.
	 *
	 * @spec exclude one step of archive(), which carries the requirement
	 */
	private function archivalFutureOf(array $case): array {
		return $this->results->archivalFuture(
			case: $case,
			resultTypeId: $this->resultTypeOn(case: $case),
			endDate: $this->archiveEndDate(case: $case),
		);
	}//end archivalFutureOf()

	/**
	 * The nomination and action date the archive act records and answers.
	 *
	 * @param array<string, mixed> $future The archival future.
	 *
	 * @return array{archiveNomination: mixed, archiveActionDate: mixed} The two fields, null when absent.
	 *
	 * @spec exclude one step of archive(), which carries the requirement
	 */
	private function nominationOf(array $future): array {
		return [
			'archiveNomination' => ($future['archiveNomination'] ?? null),
			'archiveActionDate' => ($future['archiveActionDate'] ?? null),
		];
	}//end nominationOf()

	/**
	 * Journal an act on the case and save it.
	 *
	 * @param array<string, mixed> $case  The case to save.
	 * @param array<string, mixed> $entry The journal entry.
	 *
	 * @return array<string, mixed> The case as saved, journal included.
	 *
	 * @spec exclude one step of archive(), which carries the requirement
	 */
	private function saveWithEntry(array $case, array $entry): array {
		$case = $this->journal->append(case: $case, entry: $entry);
		$this->store->saveCase(case: $case);

		return $case;
	}//end saveWithEntry()

	/**
	 * Only an ended case can be archived.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the case is still open.
	 *
	 * @spec exclude one guard of archive(), which carries the requirement
	 */
	private function requireEnded(array $case): void {
		if ($this->isEnded(case: $case) === false) {
			throw new RefusedException(
				rule: 'case-not-ended',
				sentence: 'Finish or abort this case before archiving it.',
				status: RefusedException::STATUS_REFUSED,
			);
		}
	}//end requireEnded()

	/**
	 * The end date the archive act derives the retention from.
	 *
	 * @param array<string, mixed> $case The ended case.
	 *
	 * @return string The case's end date, today when it carries none.
	 *
	 * @spec exclude one field read behind archive(), which carries the requirement
	 */
	private function archiveEndDate(array $case): string {
		$endDate = (string)($case['endDate'] ?? '');
		if ($endDate === '') {
			return (new DateTimeImmutable())->format('Y-m-d');
		}

		return $endDate;
	}//end archiveEndDate()

	/**
	 * The case with its archival future and the archive status that follows from it.
	 *
	 * @param array<string, mixed> $case   The ended case.
	 * @param array<string, mixed> $future The nomination and action date from the result type.
	 *
	 * @return array<string, mixed> The case to save.
	 *
	 * @spec exclude one step of archive(), which carries the requirement
	 */
	private function archivedCase(array $case, array $future): array {
		$case = array_merge($case, $future);
		$case['archiveStatus'] = 'archived';
		if (($future['archiveActionDate'] ?? '') === '') {
			// ZGW rule zrc-021 leaves the date underivable when the result
			// type states no period. The case is archived either way and an archivist can
			// see which ones carry no date, which is a better answer than
			// refusing the act or inventing a destruction date.
			$case['archiveStatus'] = 'archived_retention_period_unknown';
		}

		return $case;
	}//end archivedCase()

	/**
	 * Take a case back out of the archive.
	 *
	 * One act and no wizard (D-5): a case archived by mistake is one press
	 * away from being worked again. The marker comes off first, because the
	 * ZGW field cannot be written while it is on, and `archiveStatus` goes
	 * back to `nog_te_archiveren` rather than to whatever it held before: the
	 * case is once again a case that still has to be archived, which is what
	 * that value means.
	 *
	 * The nomination and the action date are deliberately KEPT. They were
	 * derived from the result type and they are still true; clearing them
	 * would make a restore look like an appraisal that had been undone.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is coming back.
	 *
	 * @return array<string, mixed> The archive status it carries afterwards.
	 *
	 * @throws RefusedException When the caller may not archive, the case is not
	 *                          archived, or the platform refuses.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function unarchive(string $caseId, string $reason): array {
		$case = $this->restorableCase(caseId: $caseId, reason: $reason);
		$this->archiveState->clear(caseId: $caseId, reason: $reason);
		$case = $this->journalRestore(case: $case, reason: $reason);

		$this->logger->info('CaseEndingActs: case restored from the archive', ['caseId' => $caseId]);

		return [
			'caseId' => $caseId,
			'act' => 'unarchive',
			'archiveStatus' => $case['archiveStatus'],
			'archived' => null,
		];
	}//end unarchive()

	/**
	 * Load a case for the restore act and run its guards: a reason, the role, an archived case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is coming back.
	 *
	 * @return array<string, mixed> The loaded case.
	 *
	 * @throws RefusedException When the caller may not archive, or the case is not archived.
	 *
	 * @spec exclude the guards of unarchive(), which carries the requirement
	 */
	private function restorableCase(string $caseId, string $reason): array {
		$case = $this->load(caseId: $caseId);
		$this->requireReason(reason: $reason);

		// Restoring is the same act as archiving read backwards, so it asks
		// for the same role. A second, looser gate would let somebody who may
		// not file a case take one back out of the file.
		$this->gate->require(act: 'archive', case: $case);

		if ($this->archiveState->isArchived(case: $case) === false) {
			throw new RefusedException(
				rule: 'case-not-archived',
				sentence: 'This case is not in the archive.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $case;
	}//end restorableCase()

	/**
	 * Put the restored case back to "still to be archived", journal the restore and save it.
	 *
	 * @param array<string, mixed> $case   The case, its marker already cleared.
	 * @param string               $reason Why it is coming back.
	 *
	 * @return array<string, mixed> The case as saved.
	 *
	 * @spec exclude one step of unarchive(), which carries the requirement
	 */
	private function journalRestore(array $case, string $reason): array {
		$case['archiveStatus'] = 'nog_te_archiveren';
		$case = $this->journal->append(
			case: $case,
			entry: [
				'type' => 'unarchive',
				'reason' => $reason,
			],
		);
		$this->store->saveCase(case: $case);

		return $case;
	}//end journalRestore()

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
	 * The results a finish or an abort of this case may name, as a picker reads them.
	 *
	 * The same list the close refuses against, so the form cannot offer a
	 * result the act then refuses, nor miss one a parent case type contributes.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array{id: string, name: string}> The choices, empty when none.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-finishing-or-aborting-a-case-picks-its-result-from-the-case-type-req-cm-80
	 */
	public function resultChoicesOn(array $case): array {
		return $this->results->resultTypeChoices(caseTypeId: (string)($case['caseType'] ?? ''));
	}//end resultChoicesOn()

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
	 * @param array<string, string> $request caseId, reason, resultTypeId and toStatus (resolved when empty).
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @throws RefusedException When refused.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function end(string $act, array $request): array {
		$case = $this->endableCase(act: $act, request: $request);

		$ending = ['act' => $act] + $request + $this->landing(case: $case, request: $request);
		$this->writeEnding(case: $this->endedCase(case: $case, ending: $ending), ending: $ending);

		$this->logger->info(
			'CaseEndingActs: case ended',
			['caseId' => $request['caseId'], 'act' => $act, 'skipped' => count($ending['skippedPhases'])],
		);

		return $this->endingAnswer(ending: $ending);
	}//end end()

	/**
	 * Load a case for finish or abort and run its guards: a reason, the role, and for finish complete data.
	 *
	 * @param string                $act     `finish` or `abort`.
	 * @param array<string, string> $request caseId, reason, resultTypeId and toStatus.
	 *
	 * @return array<string, mixed> The loaded case.
	 *
	 * @throws RefusedException When refused.
	 *
	 * @spec exclude the guards of end(), which carries the requirement
	 */
	private function endableCase(string $act, array $request): array {
		$case = $this->load(caseId: $request['caseId']);
		$this->requireReason(reason: $request['reason']);
		$this->gate->require(act: $act, case: $case);

		if ($act === 'finish') {
			// REQ-LIFE-13: an act that needs the missing data is refused,
			// naming the field. Finishing says the case reached its result,
			// and a case that never got its required data did not reach
			// anything. Aborting is deliberately not gated: an intrekking is
			// exactly what happens to a case whose data never arrived.
			$this->incompleteness->requireComplete(case: $case);
		}

		return $case;
	}//end endableCase()

	/**
	 * What finish and abort answer about the ending they recorded.
	 *
	 * @param array<string, mixed> $ending The act, its request and its landing.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @spec exclude one step of end(), which carries the requirement
	 */
	private function endingAnswer(array $ending): array {
		return [
			'caseId' => $ending['caseId'],
			'act' => $ending['act'],
			'status' => $ending['status'],
			'endDate' => $ending['endDate'],
			'result' => $ending['result'],
			'skippedPhases' => $ending['skippedPhases'],
		];
	}//end endingAnswer()

	/**
	 * Where the case lands: its terminal status, its result, its end date and the phases it skips.
	 *
	 * The result is resolved BEFORE anything is written. `resolveClosingResult`
	 * refuses a close with no result on a case type that offers some, and a
	 * refusal after the status had moved would leave a case closed with no
	 * outcome, which is the state nobody can read afterwards.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param array<string, string> $request The act's caseId, reason, resultTypeId and toStatus.
	 *
	 * @return array{from: string, status: string, result: ?string, endDate: string, skippedPhases: list<string>} The landing.
	 *
	 * @throws RefusedException When the case type has no terminal status, or no result can be named.
	 *
	 * @spec exclude one step of end(), which carries the requirement
	 */
	private function landing(array $case, array $request): array {
		$caseTypeId = (string)($case['caseType'] ?? '');
		$fromStatus = (string)($case['status'] ?? '');
		$target = $this->terminalStatus(caseTypeId: $caseTypeId, named: $request['toStatus']);

		$chosenResult = null;
		if ($request['resultTypeId'] !== '') {
			$chosenResult = $request['resultTypeId'];
		}

		$resultId = $this->results->resolveClosingResult(
			caseId: $request['caseId'],
			caseTypeId: $caseTypeId,
			resultTypeId: $chosenResult,
		);

		return [
			'from' => $fromStatus,
			'status' => $target,
			'result' => $resultId,
			'endDate' => (new DateTimeImmutable())->format('Y-m-d'),
			'skippedPhases' => $this->skippedPhases(caseTypeId: $caseTypeId, fromStatus: $fromStatus, toStatus: $target),
		];
	}//end landing()

	/**
	 * The case as the ending leaves it.
	 *
	 * @param array<string, mixed> $case   The loaded case.
	 * @param array<string, mixed> $ending The act, its request and its landing.
	 *
	 * @return array<string, mixed> The case to save.
	 *
	 * @spec exclude one step of end(), which carries the requirement
	 */
	private function endedCase(array $case, array $ending): array {
		$case['status'] = $ending['status'];
		$case['endDate'] = $ending['endDate'];
		$case[self::ENDING_FIELD] = $ending['act'];
		if ($ending['result'] !== null) {
			$case['result'] = $ending['result'];
		}

		if ($ending['skippedPhases'] !== []) {
			$case['skippedPhases'] = json_encode($ending['skippedPhases']);
		}

		return $this->withFinishNomination(case: $case, ending: $ending);
	}//end endedCase()

	/**
	 * The ended case with the nomination its result type gives, when it was finished with a chosen result.
	 *
	 * @param array<string, mixed> $case   The ended case.
	 * @param array<string, mixed> $ending The act, its request and its landing.
	 *
	 * @return array<string, mixed> The case to save.
	 *
	 * @spec exclude one step of end(), which carries the requirement
	 */
	private function withFinishNomination(array $case, array $ending): array {
		if ($ending['act'] !== 'finish' || $ending['resultTypeId'] === '') {
			return $case;
		}

		// Only finishing takes the nomination from the result type here.
		// An abort has a result and no besluit, and its retention is
		// committed by the archive act, which is where an archivist is.
		return array_merge($case, $this->results->archivalFuture(
			case: $case,
			resultTypeId: $ending['resultTypeId'],
			endDate: $ending['endDate'],
		));
	}//end withFinishNomination()

	/**
	 * Journal the ending on the case, save it and write the status record.
	 *
	 * @param array<string, mixed> $case   The ended case.
	 * @param array<string, mixed> $ending The act, its request and its landing.
	 *
	 * @return void
	 *
	 * @spec exclude one step of end(), which carries the requirement
	 */
	private function writeEnding(array $case, array $ending): void {
		$case = $this->journal->append(
			case: $case,
			entry: [
				'type' => $ending['act'],
				'reason' => $ending['reason'],
				'resultType' => $ending['resultTypeId'],
				'skippedPhases' => $ending['skippedPhases'],
				'besluit' => ($ending['act'] === 'finish'),
			],
		);
		$this->store->saveCase(case: $case);

		$this->store->writeStatusRecord(
			caseId: $ending['caseId'],
			toStatus: $ending['status'],
			fromStatus: $ending['from'],
			label: ucfirst($ending['act']),
			comment: $ending['reason'],
			evaluatedGuards: [],
			noWorkflowTemplate: true,
		);
	}//end writeEnding()

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
		$terminal = $this->terminalRows(caseTypeId: $caseTypeId);
		foreach ($terminal as $row) {
			if ($this->idOf(row: $row) === $named && $named !== '') {
				return $named;
			}
		}

		usort($terminal, fn (array $left, array $right): int => $this->orderOf(row: $left) <=> $this->orderOf(row: $right));

		return $this->idOf(row: $terminal[0]);
	}//end terminalStatus()

	/**
	 * The case type's terminal status rows, in the order the case type lists them.
	 *
	 * @param string $caseTypeId The case type UUID.
	 *
	 * @return list<array<string, mixed>> The terminal rows.
	 *
	 * @throws RefusedException When the case type declares no terminal status.
	 *
	 * @spec exclude one step of terminalStatus(), which carries the requirement
	 */
	private function terminalRows(string $caseTypeId): array {
		$terminal = array_values(array_filter(
			$this->statuses->rowsOf(caseTypeId: $caseTypeId),
			fn (array $row): bool => $this->isFinalRow(row: $row),
		));
		if ($terminal === []) {
			throw new RefusedException(
				rule: 'no-terminal-status',
				sentence: 'This case type has no status to end on.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		return $terminal;
	}//end terminalRows()

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
				$skipped[] = $this->text->textOr(value: ($row['name'] ?? ($row['title'] ?? null)), fallback: $id);
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
	public function isEnded(array $case): bool {
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
