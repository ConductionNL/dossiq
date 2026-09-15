<?php

/**
 * A case type may close its own cases after a declared period of silence.
 *
 * Plane counts from the last touch and closes nightly. The Dutch clause is a
 * bezwaar waiting on the indiener that should not sit open forever, and the
 * gemeente's problem with it is real: a queue whose oldest third is waiting on
 * somebody who stopped answering months ago tells a manager nothing about the
 * work that is actually being done.
 *
 * Three properties make it defensible rather than convenient, and none of them
 * is optional:
 *
 *  1. **Off by default.** A case that closes itself in a gemeente without
 *     anybody deciding is a besluit nobody took. The period is declared per
 *     case type and absent means never.
 *  2. **Announced first.** The applicant is told the case is about to close,
 *     `autoCloseWarningDays` ahead, and the warning is a recorded act rather
 *     than a log line. A close whose warning silently failed is the version of
 *     this feature that ends up in a klachtprocedure.
 *  3. **Attributed.** The close records that the PRODUCT did it, the period
 *     that was declared, and the last activity it counted from. Nobody should
 *     later have to work out whether a person closed that case.
 *
 * 🔑 IT COUNTS FROM THE LAST ACTIVITY, WHICH IS NOT THE SAME AS THE LAST EDIT.
 * `@self.updated` moves when anything touches the row, including this job's
 * own warning. So the warning is written into the journal and the count is
 * taken from the last entry that is not one of this service's own: a warning
 * that reset the silence clock would mean no case ever reached its period,
 * which is the shape of bug that looks like the feature simply being off.
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
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns about, and then performs, the close a case type declared.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
class SilenceCloseService {

	/**
	 * The journal entry a warning writes.
	 *
	 * @var string
	 */
	public const WARNING_TYPE = 'silenceWarning';

	/**
	 * The journal entry types this service writes itself.
	 *
	 * Excluded from the silence count, so the service's own writes cannot
	 * postpone the close they are announcing.
	 *
	 * @var list<string>
	 */
	private const OWN_ENTRIES = [self::WARNING_TYPE];

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $store Reads and writes the case.
	 * @param LifecycleCaseTypeRules $rules Reads the declared period and warning.
	 * @param CaseEndingActs $endings Performs the close as a recorded ending act.
	 * @param CaseJournal $journal The case's own record.
	 * @param CaseDateNormaliser $dates The ONE class allowed to parse and zone a date.
	 * @param LoggerInterface $logger Says what was warned and what was closed.
	 */
	public function __construct(
		private readonly CaseStatusStore $store,
		private readonly LifecycleCaseTypeRules $rules,
		private readonly CaseEndingActs $endings,
		private readonly CaseJournal $journal,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What this case's silence period says should happen today.
	 *
	 * Returns the decision rather than acting on it, so the decision can be
	 * driven one case at a time by a test without a register, a job list or a
	 * clock to stub.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 * @param DateTimeImmutable $today The day being evaluated.
	 *
	 * @return array{action: string, silentDays: int, period: int, closesOn: string}
	 *         `action` is `none`, `warn` or `close`.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function decide(array $case, DateTimeImmutable $today): array {
		$caseTypeId = (string)($case['caseType'] ?? '');
		$period = $this->rules->silenceDays(caseTypeId: $caseTypeId);
		$none = ['action' => 'none', 'silentDays' => 0, 'period' => $period, 'closesOn' => ''];

		if ($period <= 0) {
			return $none;
		}

		$last = $this->lastActivity(case: $case);
		if ($last === null) {
			return $none;
		}

		$silent = (int)$last->diff($today)->days;
		$closesOn = $last->modify('+'.$period.' days')->format('Y-m-d');
		$warnAt = ($period - $this->rules->warningDays(caseTypeId: $caseTypeId));

		if ($silent >= $period) {
			return ['action' => 'close', 'silentDays' => $silent, 'period' => $period, 'closesOn' => $closesOn];
		}

		if ($silent >= $warnAt && $this->alreadyWarned(case: $case) === false) {
			return ['action' => 'warn', 'silentDays' => $silent, 'period' => $period, 'closesOn' => $closesOn];
		}

		return ['action' => 'none', 'silentDays' => $silent, 'period' => $period, 'closesOn' => $closesOn];
	}//end decide()

	/**
	 * Tell the applicant the case is about to close.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $case The loaded case.
	 * @param string $closesOn The date it will close, as Y-m-d.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function warn(string $caseId, array $case, string $closesOn): void {
		$case = $this->journal->append(
			case: $case,
			entry: [
				'type' => self::WARNING_TYPE,
				'closesOn' => $closesOn,
				'product' => true,
			],
		);
		$this->store->saveCase(case: $case);

		$this->logger->info(
			'SilenceCloseService: the applicant was told the case will close',
			['caseId' => $caseId, 'closesOn' => $closesOn],
		);
	}//end warn()

	/**
	 * Close the case, recording that the product did it.
	 *
	 * It goes through {@see CaseEndingActs} rather than writing the status
	 * itself, so an automatic close is the same kind of act as a handler's:
	 * one status record, one result, one journal entry. The only differences
	 * are the entry's `type` and the empty actor, which is the honest name for
	 * nobody.
	 *
	 * The caller's copy of the case is deliberately NOT a parameter. The act
	 * below reloads it, because `abort()` writes to the case first and a stale
	 * copy written back afterwards would undo the very ending this method just
	 * recorded.
	 *
	 * @param string $caseId The case UUID.
	 * @param array{silentDays: int, period: int} $decision What was decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function close(string $caseId, array $decision): void {
		$reason = sprintf(
			'Closed after %d days without activity, the period this case type declares.',
			$decision['period'],
		);

		try {
			$this->endings->abort(caseId: $caseId, reason: $reason, resultTypeId: '');
		} catch (Throwable $e) {
			// A case whose type offers result types and whose close therefore
			// needs one is NOT closed automatically. Inventing a result would
			// put an outcome on a case nobody decided, which is worse than
			// leaving it open for somebody to decide.
			$this->logger->info(
				'SilenceCloseService: the case was not closed automatically',
				['caseId' => $caseId, 'reason' => $e->getMessage()],
			);

			return;
		}

		$reloaded = $this->store->loadCase(caseId: $caseId);
		if ($reloaded === null) {
			return;
		}

		$reloaded[CaseEndingActs::ENDING_FIELD] = 'autoClose';
		$reloaded = $this->journal->append(
			case: $reloaded,
			entry: [
				'type' => 'autoClose',
				'reason' => $reason,
				'product' => true,
				'period' => $decision['period'],
				'silentDays' => $decision['silentDays'],
			],
		);
		$this->store->saveCase(case: $reloaded);

		$this->logger->info(
			'SilenceCloseService: the product closed a silent case',
			['caseId' => $caseId, 'period' => $decision['period'], 'silentDays' => $decision['silentDays']],
		);
	}//end close()

	/**
	 * When this case was last touched by anything but this service.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return DateTimeImmutable|null The moment, or null when none can be read.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function lastActivity(array $case): ?DateTimeImmutable {
		foreach (array_reverse($this->journal->entries(case: $case)) as $entry) {
			if (in_array((string)($entry['type'] ?? ''), self::OWN_ENTRIES, true) === true) {
				continue;
			}

			$moment = $this->dates->tryParse(value: ($entry['at'] ?? ''));
			if ($moment !== null) {
				return $moment;
			}
		}

		$updated = ($case['@self']['updated'] ?? ($case['startDate'] ?? ''));

		return $this->dates->tryParse(value: $updated);
	}//end lastActivity()

	/**
	 * Whether the applicant has already been told about this close.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return bool True when a warning stands.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	private function alreadyWarned(array $case): bool {
		return ($this->journal->latest(case: $case, types: [self::WARNING_TYPE]) !== []);
	}//end alreadyWarned()
}//end class
