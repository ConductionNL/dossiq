<?php

/**
 * Dossiq stalled-case detector.
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
 * @package  OCA\Dossiq\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Service\WorkingDayCalculator;

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
		'closed',
		'handled',
		'refused',
		'withdrawn',
		'archived',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param MilestoneRepository $repository Milestone definitions/records reader.
	 * @param WorkingDayCalculator $workingDays Weekend and Dutch-holiday arithmetic.
	 * @param MilestoneSchedule $schedule Projects the timeline, reading `dependsOn`.
	 * @param DayCount $days Counts the days between two moments.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneRepository $repository,
		private readonly WorkingDayCalculator $workingDays,
		private readonly MilestoneSchedule $schedule,
		private readonly DayCount $days = new DayCount(),
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

		return $this->stallRows(
			cases: $cases,
			window: new StallWindow(today: new DateTimeImmutable('today'), thresholdDays: $thresholdDays)
		);
	}//end findStalledCases()

	/**
	 * The stall rows of the cases that have stalled.
	 *
	 * @param array<int, array<string, mixed>> $cases  The case objects.
	 * @param StallWindow                      $window Today and the grace days.
	 *
	 * @return array<int, array<string, mixed>> One row per stalled case.
	 */
	private function stallRows(array $cases, StallWindow $window): array {
		$stalled = [];
		foreach ($cases as $case) {
			$stall = $this->getStallRow(row: $case, window: $window);
			if ($stall !== null) {
				$stalled[] = $stall;
			}
		}

		return $stalled;
	}//end stallRows()

	/**
	 * The milestone one case waits on now, whether or not it is late.
	 *
	 * The first unreached milestone with the deadline the schedule gives it,
	 * the same row {@see self::findStalledCases()} reports, without the
	 * lateness filter. The milestone timer arms on this deadline, so the
	 * timer and the stalled list can never disagree about which milestone a
	 * case waits on or when it is due.
	 *
	 * @param array<string, mixed> $case The case object.
	 *
	 * @return array<string, mixed>|null The row (caseId, milestoneIdentifier,
	 *                                   milestoneLabel, deadline Y-m-d,
	 *                                   daysOverdue, assignee), or null when the
	 *                                   case is closed, unscheduled or complete.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function waitingOn(array $case): ?array {
		return $this->getStallRow(row: $case, window: new StallWindow(today: new DateTimeImmutable('today'), thresholdDays: PHP_INT_MIN));
	}//end waitingOn()

	/**
	 * Build the stall report row for a single case, or null when it is not stalled.
	 *
	 * Cases without an id, closed cases, cases without a case type and cases
	 * without a parsable start date are skipped (null).
	 *
	 * @param array<string, mixed> $row    The case object.
	 * @param StallWindow          $window Today and the grace days.
	 *
	 * @return array<string, mixed>|null Stall row, or null when on track or skipped.
	 */
	private function getStallRow(array $row, StallWindow $window): ?array {
		$candidate = $this->candidateOf(row: $row);
		if ($candidate === null) {
			return null;
		}

		$stall = $this->evaluateStall(candidate: $candidate, window: $window);
		if ($stall === null) {
			return null;
		}

		$stall['caseTitle'] = (string)($row['title'] ?? '');
		$stall['caseType'] = $candidate->caseTypeId;
		$stall['assignee'] = (string)($row['assignee'] ?? '');

		return $stall;
	}//end getStallRow()

	/**
	 * The case as a stall candidate, or null when it is closed or lacks an id, a case type or a start.
	 *
	 * @param array<string, mixed> $row The case object.
	 *
	 * @return StallCandidate|null The candidate.
	 */
	private function candidateOf(array $row): ?StallCandidate {
		$caseId = $this->openCaseId(row: $row);
		$caseTypeId = (string)($row['caseType'] ?? '');
		if ($caseId === '' || $caseTypeId === '') {
			return null;
		}

		$startDate = $this->parseCaseStart(row: $row);
		if ($startDate === null) {
			return null;
		}

		return new StallCandidate(caseId: $caseId, caseTypeId: $caseTypeId, startDate: $startDate);
	}//end candidateOf()

	/**
	 * The id of a case that is still open, or '' when it has no id or is closed.
	 *
	 * @param array<string, mixed> $row The case object.
	 *
	 * @return string The case id, or ''.
	 */
	private function openCaseId(array $row): string {
		$caseId = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($caseId === '' || $this->isClosedStatus(status: strtolower((string)($row['status'] ?? ''))) === true) {
			return '';
		}

		return $caseId;
	}//end openCaseId()

	/**
	 * Evaluate whether a single case has stalled on its earliest unreached
	 * milestone and, if so, build the report row.
	 *
	 * @param StallCandidate $candidate The case, its type and its start.
	 * @param StallWindow    $window    Today and the grace days.
	 *
	 * @return array<string, mixed>|null Stall row, or null when on track.
	 */
	private function evaluateStall(StallCandidate $candidate, StallWindow $window): ?array {
		$definitions = $this->orderedDefinitions(caseTypeId: $candidate->caseTypeId);
		if (count($definitions) === 0) {
			return null;
		}

		[$reachedBy, $reachedOn] = $this->reached(caseId: $candidate->caseId);

		$projected = $this->schedule->project(
			definitions: $definitions,
			caseStart: $candidate->startDate,
			reached: $reachedOn
		);

		// The first unreached milestone is what the case waits on; when every
		// milestone is reached the case is complete, not stalled.
		$definition = $this->firstUnreached(definitions: $definitions, reachedBy: $reachedBy);
		if ($definition === null) {
			return null;
		}

		// Its deadline comes from the SCHEDULE, which reads `dependsOn`,
		// rather than from this milestone's own duration counted off the
		// case start.
		$identifier = $this->identifierOf(definition: $definition);
		$deadline = ($projected[$identifier] ?? $this->ownDeadline(definition: $definition, candidate: $candidate));
		$daysOverdue = $this->days->signed(from: $deadline, to: $window->today);
		if ($daysOverdue <= $window->thresholdDays) {
			return null;
		}

		return [
			'caseId' => $candidate->caseId,
			'milestoneIdentifier' => $identifier,
			'milestoneLabel' => $this->labelOf(definition: $definition),
			'deadline' => $deadline->format('Y-m-d'),
			'daysOverdue' => $daysOverdue,
		];
	}//end evaluateStall()

	/**
	 * A case type's milestone definitions, ordered by their numeric `order`.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 */
	private function orderedDefinitions(string $caseTypeId): array {
		$definitions = $this->repository->findDefinitions(caseTypeId: $caseTypeId);
		usort(
			$definitions,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		return $definitions;
	}//end orderedDefinitions()

	/**
	 * The first definition this case has not reached, or null when it reached them all.
	 *
	 * @param array<int, array<string, mixed>> $definitions The ordered definitions.
	 * @param array<string, bool>              $reachedBy   Reached, by definition id.
	 *
	 * @return array<string, mixed>|null The definition.
	 */
	private function firstUnreached(array $definitions, array $reachedBy): ?array {
		foreach ($definitions as $definition) {
			$defId = (string)($definition['id'] ?? ($definition['uuid'] ?? ''));
			if (isset($reachedBy[$defId]) === false) {
				return $definition;
			}
		}

		return null;
	}//end firstUnreached()

	/**
	 * The deadline of a milestone counted from the case start by its own duration.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param StallCandidate       $candidate  The case and its start.
	 *
	 * @return DateTimeImmutable The deadline.
	 */
	private function ownDeadline(array $definition, StallCandidate $candidate): DateTimeImmutable {
		return $this->addWorkingDays(
			start: $candidate->startDate,
			workingDays: (int)($definition['expectedDurationWorkingDays'] ?? 0)
		);
	}//end ownDeadline()

	/**
	 * A definition's identifier, or ''.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return string The identifier.
	 */
	private function identifierOf(array $definition): string {
		return (string)($definition['identifier'] ?? '');
	}//end identifierOf()

	/**
	 * A definition's label, falling back to its name, or ''.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return string The label.
	 */
	private function labelOf(array $definition): string {
		return (string)($definition['label'] ?? ($definition['name'] ?? ''));
	}//end labelOf()

	/**
	 * Which milestones this case has reached, and on what dates.
	 *
	 * The DATE a milestone was reached is what everything downstream of it is
	 * projected from (row 3.27). Without it the schedule would project every
	 * dependent item from an estimate of a thing that has already happened,
	 * which is the defect this detector is about. An unparseable timestamp
	 * leaves the milestone projected rather than dated, which is the same
	 * answer as not having reached it and is strictly the safer one.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array{0: array<string, bool>, 1: array<string, DateTimeImmutable>} Reached by
	 *         definition id, and reached on by milestone identifier.
	 */
	private function reached(string $caseId): array {
		$reachedBy = [];
		$reachedOn = [];

		foreach ($this->repository->findRecords(caseId: $caseId) as $record) {
			if ((bool)($record['reached'] ?? true) !== true) {
				continue;
			}

			$reachedBy[(string)($record['milestoneDefinition'] ?? '')] = true;

			$identifier = (string)($record['milestoneIdentifier'] ?? '');
			$reachedAt = (string)($record['reachedAt'] ?? '');
			if ($identifier === '' || $reachedAt === '') {
				continue;
			}

			try {
				$reachedOn[$identifier] = new DateTimeImmutable(substr($reachedAt, 0, 10));
			} catch (\Throwable $e) {
				continue;
			}
		}

		return [$reachedBy, $reachedOn];
	}//end reached()

	/**
	 * Parse a case's start date into a date-only immutable value.
	 *
	 * @param array<string, mixed> $row The case object.
	 *
	 * @return DateTimeImmutable|null The start date, or null when absent/invalid.
	 */
	private function parseCaseStart(array $row): ?DateTimeImmutable {
		$raw = (string)($row['startDate'] ?? ($row['created'] ?? ''));
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
	 * Add a number of working days to a start date.
	 *
	 * This used to skip weekends only, on the stated grounds that the milestone
	 * layer's deadlines are advisory "per the proposal's out-of-scope note on
	 * contractual SLA enforcement". That reading was wrong. The out-of-scope
	 * note rules out *enforcing* a milestone deadline as a contractual SLA; it
	 * says nothing about how a working day is counted. The same change's
	 * design.md is explicit in the other direction: "Working days exclude
	 * weekends and Dutch holidays (via WorkingDayCalculator helper)". The
	 * helper it names was never built, so five callers each grew their own
	 * rule and this one lost the holidays.
	 *
	 * Counting holidays moves each projected deadline later, so the detector
	 * now flags strictly fewer cases as stalled. That is the safe direction for
	 * an advisory signal: it removes cases that were only "overdue" because the
	 * projection spent the deadline on Tweede Paasdag.
	 *
	 * @param DateTimeImmutable $start The start date.
	 * @param int $workingDays Working days to add (>= 0).
	 *
	 * @return DateTimeImmutable The resulting deadline date.
	 */
	private function addWorkingDays(DateTimeImmutable $start, int $workingDays): DateTimeImmutable {
		return $this->workingDays->addWorkingDays(start: $start, days: $workingDays);
	}//end addWorkingDays()
}//end class
