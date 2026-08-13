<?php

/**
 * Procest stalled-case detector.
 *
 * Owns the "which cases are waiting too long?" question: it scans the open
 * cases, finds each one's earliest unreached milestone, projects that
 * milestone's deadline from the case start date over working days, and reports
 * the case when the deadline is more than the caller's grace period in the
 * past. Split out of MilestoneService so that service keeps milestone CRUD and
 * per-case progress, while the deadline arithmetic and the skip rules that
 * decide what "stalled" means live here.
 *
 * Skips are deliberate and silent: a case with no id, a closed case, a case
 * with no case type and a case with no parsable start date are all
 * un-assessable rather than stalled, and reporting them would flood the signal
 * this report exists to carry.
 *
 * @category Service
 * @package  OCA\Procest\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Milestone;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;

/**
 * Reports the cases that have run past their earliest unreached milestone.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
class StalledCaseDetector {

	use SearchesObjects;

	/**
	 * Status substrings that mark a case as closed and therefore un-assessable.
	 *
	 * @var string[]
	 */
	private const CLOSED_STATUS_NEEDLES = [
		'afgesloten',
		'afgehandeld',
		'geweigerd',
		'withdrawn',
		'gearchiveerd',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param MilestoneRepository $repository Milestone definitions/records reader.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneRepository $repository,
	) {
	}//end __construct()

	/**
	 * Find active cases that have stalled past a milestone deadline.
	 *
	 * @param int $thresholdDays Grace days past the computed deadline before a
	 *                           case is flagged (default 0 = flag on overdue).
	 *
	 * @return array<int, array<string, mixed>> One entry per stalled case:
	 *                                          caseId, caseTitle, caseType,
	 *                                          assignee, milestoneIdentifier,
	 *                                          milestoneLabel, deadline,
	 *                                          daysOverdue.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function findStalledCases(int $thresholdDays = 0): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			return [];
		}

		$cases = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			filters: ['_limit' => 1000],
		);

		$today = new DateTimeImmutable('today');
		$stalled = [];

		foreach ($cases as $case) {
			$stall = $this->getStallRow(case: $case, today: $today, thresholdDays: $thresholdDays);
			if ($stall !== null) {
				$stalled[] = $stall;
			}
		}//end foreach

		return $stalled;
	}//end findStalledCases()

	/**
	 * Build the stall report row for a single case, or null when it is not stalled.
	 *
	 * Cases without an id, closed cases, cases without a case type and cases
	 * without a parsable start date are skipped (null).
	 *
	 * @param array<string, mixed> $case The case object.
	 * @param DateTimeImmutable $today Today (date only).
	 * @param int $thresholdDays Grace days past the deadline.
	 *
	 * @return array<string, mixed>|null Stall row, or null when on track or skipped.
	 */
	private function getStallRow(array $case, DateTimeImmutable $today, int $thresholdDays): ?array {
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		$status = strtolower((string)($case['status'] ?? ''));
		if ($caseId === '' || $this->isClosedStatus(status: $status) === true) {
			return null;
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		if ($caseTypeId === '') {
			return null;
		}

		$startDate = $this->parseCaseStart(case: $case);
		if ($startDate === null) {
			return null;
		}

		$stall = $this->evaluateStall(
			caseId: $caseId,
			caseTypeId: $caseTypeId,
			startDate: $startDate,
			today: $today,
			thresholdDays: $thresholdDays,
		);

		if ($stall === null) {
			return null;
		}

		$stall['caseTitle'] = (string)($case['title'] ?? '');
		$stall['caseType'] = $caseTypeId;
		$stall['assignee'] = (string)($case['assignee'] ?? '');

		return $stall;
	}//end getStallRow()

	/**
	 * Evaluate whether a single case has stalled on its earliest unreached
	 * milestone and, if so, build the report row.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param DateTimeImmutable $startDate The case start date.
	 * @param DateTimeImmutable $today Today (date only).
	 * @param int $thresholdDays Grace days past the deadline.
	 *
	 * @return array<string, mixed>|null Stall row, or null when on track.
	 */
	private function evaluateStall(
		string $caseId,
		string $caseTypeId,
		DateTimeImmutable $startDate,
		DateTimeImmutable $today,
		int $thresholdDays,
	): ?array {
		$definitions = $this->repository->findDefinitions(caseTypeId: $caseTypeId);
		if (count($definitions) === 0) {
			return null;
		}

		// Order definitions by their numeric `order`.
		usort(
			$definitions,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		$records = $this->repository->findRecords(caseId: $caseId);
		$reachedBy = [];
		foreach ($records as $record) {
			if ((bool)($record['reached'] ?? true) === true) {
				$reachedBy[(string)($record['milestoneDefinition'] ?? '')] = true;
			}
		}

		foreach ($definitions as $def) {
			$defId = (string)($def['id'] ?? ($def['uuid'] ?? ''));
			if (isset($reachedBy[$defId]) === true) {
				continue;
			}

			// First unreached milestone — this is what the case waits on.
			$expectedDays = (int)($def['expectedDurationWorkingDays'] ?? 0);
			$deadline = $this->addWorkingDays(start: $startDate, workingDays: $expectedDays);
			$daysOverdue = (int)$deadline->diff($today)->format('%r%a');

			if ($daysOverdue > $thresholdDays) {
				return [
					'caseId' => $caseId,
					'milestoneIdentifier' => (string)($def['identifier'] ?? ''),
					'milestoneLabel' => (string)($def['label'] ?? ($def['name'] ?? '')),
					'deadline' => $deadline->format('Y-m-d'),
					'daysOverdue' => $daysOverdue,
				];
			}

			// Earliest unreached milestone is within deadline -> on track.
			return null;
		}//end foreach

		// All milestones reached -> case complete, not stalled.
		return null;
	}//end evaluateStall()

	/**
	 * Parse a case's start date into a date-only immutable value.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return DateTimeImmutable|null The start date, or null when absent/invalid.
	 */
	private function parseCaseStart(array $case): ?DateTimeImmutable {
		$raw = (string)($case['startDate'] ?? ($case['created'] ?? ''));
		if ($raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr($raw, 0, 10));
		} catch (\Throwable $e) {
			return null;
		}
	}//end parseCaseStart()

	/**
	 * Determine whether a (lower-cased) case status represents a closed case.
	 *
	 * @param string $status Lower-cased status string.
	 *
	 * @return bool True when the case is closed and should be skipped.
	 */
	private function isClosedStatus(string $status): bool {
		foreach (self::CLOSED_STATUS_NEEDLES as $needle) {
			if (str_contains($status, $needle) === true) {
				return true;
			}
		}

		return false;
	}//end isClosedStatus()

	/**
	 * Add a number of working days (Mon-Fri) to a start date.
	 *
	 * Weekends are skipped. Dutch public holidays are not subtracted here; the
	 * milestone layer's deadlines are advisory (per the proposal's out-of-scope
	 * note on contractual SLA enforcement).
	 *
	 * @param DateTimeImmutable $start The start date.
	 * @param int $workingDays Working days to add (>= 0).
	 *
	 * @return DateTimeImmutable The resulting deadline date.
	 */
	private function addWorkingDays(DateTimeImmutable $start, int $workingDays): DateTimeImmutable {
		if ($workingDays <= 0) {
			return $start;
		}

		$date = $start;
		$added = 0;
		while ($added < $workingDays) {
			$date = $date->modify('+1 day');
			$dow = (int)$date->format('N');
			if ($dow < 6) {
				$added++;
			}
		}

		return $date;
	}//end addWorkingDays()
}//end class
