<?php

/**
 * Dossiq Work Queue Service
 *
 * Computes a deterministic urgency score for a case handler's open cases
 * and tasks (deadline proximity, priority, case age), and a per-handler
 * open-case workload summary for coordinators.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service computing the intelligent work-queue urgency score and the
 * coordinator workload summary.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — cohesive unit split into
 * many small, individually-simple, individually-unit-tested methods (case
 * queueing, task queueing, termijn deadline resolution, workload counting,
 * pure scoring); splitting into separate classes would fragment a single
 * well-tested responsibility rather than reduce actual complexity.
 */
class WorkQueueService {
	use SearchesObjects;

	/**
	 * Urgency tier constants.
	 */
	private const TIER_OVERDUE = 'overdue';
	private const TIER_CRITICAL = 'critical';
	private const TIER_WARNING = 'warning';
	private const TIER_NORMAL = 'normal';

	/**
	 * Base score per tier — higher tiers score higher; the deadline
	 * component further differentiates within a tier by exact day count.
	 *
	 * @var array<string, float>
	 */
	private const TIER_BASE_SCORE = [
		self::TIER_OVERDUE => 1000.0,
		self::TIER_CRITICAL => 750.0,
		self::TIER_WARNING => 500.0,
		self::TIER_NORMAL => 250.0,
	];

	/**
	 * Score contribution per priority value.
	 *
	 * @var array<string, float>
	 */
	private const PRIORITY_WEIGHT = [
		'urgent' => 30.0,
		'high' => 20.0,
		'normal' => 10.0,
		'low' => 0.0,
	];

	/**
	 * Fallback priority weight for an unknown/empty priority value.
	 */
	private const DEFAULT_PRIORITY_WEIGHT = 10.0;

	/**
	 * Age component: capped days and weight per day.
	 */
	private const MAX_AGE_DAYS = 60;
	private const AGE_WEIGHT_PER_DAY = 0.5;

	/**
	 * Safety cap on the business-day walk in businessDaysBetween(), so a
	 * corrupt/far-future deadline can never loop unbounded.
	 */
	private const MAX_BUSINESS_DAY_WALK = 3660;

	/**
	 * Maximum number of cases fetched for the workload aggregation.
	 */
	private const WORKLOAD_LIMIT = 1000;

	/**
	 * Task statuses considered terminal (excluded from the queue).
	 *
	 * @var string[]
	 */
	private const TASK_TERMINAL_STATUSES = ['completed', 'terminated', 'disabled'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register/schema config + ObjectService).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the urgency-scored work queue for one user.
	 *
	 * Aggregates the user's open cases (assignee match, endDate empty) and
	 * open tasks (assignee match, non-terminal status), scores every item,
	 * and returns them sorted by score descending (most urgent first).
	 *
	 * @param string $userId The Nextcloud user id to scope to.
	 * @param DateTimeImmutable|null $now Optional "now" override for testing.
	 *
	 * @return array<int, array<string, mixed>> Scored, sorted queue items.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function computeQueue(string $userId, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '' || $userId === '') {
			return [];
		}

		$items = [];
		$caseItems = $this->queueCaseItems(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			userId: $userId,
			now: $now
		);
		foreach ($caseItems as $item) {
			$items[] = $item;
		}

		$taskSchema = (string)$this->settingsService->getConfigValue('task_schema');
		if ($taskSchema !== '') {
			$taskItems = $this->queueTaskItems(
				objectService: $objectService,
				register: $register,
				taskSchema: $taskSchema,
				userId: $userId,
				now: $now
			);
			foreach ($taskItems as $item) {
				$items[] = $item;
			}
		}

		usort(
			$items,
			static function (array $a, array $b): int {
				return ($b['score'] <=> $a['score']);
			}
		);

		return $items;
	}//end computeQueue()

	/**
	 * Compute per-handler open-case counts across all cases.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int}> Handlers sorted by count descending.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function computeWorkload(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return [];
		}

		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['_limit' => self::WORKLOAD_LIMIT]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: workload case search failed', ['error' => $e->getMessage()]);
			return [];
		}

		$result = $this->countOpenCasesByHandler(cases: $cases);

		usort(
			$result,
			static function (array $a, array $b): int {
				return ($b['openCaseCount'] <=> $a['openCaseCount']);
			}
		);

		return $result;
	}//end computeWorkload()

	/**
	 * Tally open (endDate empty) cases per assignee.
	 *
	 * @param array<int, array<string, mixed>> $cases The raw case rows.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int}> Unsorted per-handler counts.
	 */
	private function countOpenCasesByHandler(array $cases): array {
		$counts = [];
		foreach ($cases as $case) {
			$endDate = (string)($case['endDate'] ?? '');
			if ($endDate !== '') {
				// Closed case — not part of the open workload.
				continue;
			}

			$handler = (string)($case['assignee'] ?? '');
			if ($handler === '') {
				continue;
			}

			$counts[$handler] = (($counts[$handler] ?? 0) + 1);
		}

		$result = [];
		foreach ($counts as $handler => $count) {
			$result[] = [
				'handler' => $handler,
				'openCaseCount' => $count,
			];
		}

		return $result;
	}//end countOpenCasesByHandler()

	/**
	 * Score a single item deterministically. Pure function — no I/O.
	 *
	 * @param string|null $deadline Resolved deadline (Y-m-d or parseable date), or null.
	 * @param string $priority Priority value (low/normal/high/urgent), any casing.
	 * @param string|null $referenceDate Reference date for the age component (e.g. case startDate), or null.
	 * @param DateTimeImmutable $now The "now" instant the score is computed against.
	 *
	 * @return array{
	 *     tier: string,
	 *     daysUntilDeadline: int|null,
	 *     score: float,
	 *     scoreBreakdown: array{deadline: float, priority: float, age: float}
	 * } Score result.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function scoreItem(?string $deadline, string $priority, ?string $referenceDate, DateTimeImmutable $now): array {
		$today = new DateTimeImmutable($now->format('Y-m-d'));

		$daysUntilDeadline = null;
		$tier = self::TIER_NORMAL;
		$deadlineComponent = 0.0;

		$deadlineDate = $this->parseDateOnly(value: $deadline);
		if ($deadlineDate !== null) {
			$daysUntilDeadline = $this->businessDaysBetween(today: $today, target: $deadlineDate);
			$tier = $this->tierFor(daysUntilDeadline: $daysUntilDeadline);
			$deadlineComponent = (self::TIER_BASE_SCORE[$tier] - $daysUntilDeadline);
		}

		$priorityKey = strtolower(trim($priority));
		$priorityComponent = (self::PRIORITY_WEIGHT[$priorityKey] ?? self::DEFAULT_PRIORITY_WEIGHT);

		$ageComponent = 0.0;
		$referenceParsed = $this->parseDateOnly(value: $referenceDate);
		if ($referenceParsed !== null && $referenceParsed <= $today) {
			$ageDays = (int)$today->diff($referenceParsed)->days;
			$ageComponent = (min($ageDays, self::MAX_AGE_DAYS) * self::AGE_WEIGHT_PER_DAY);
		}

		$score = ($deadlineComponent + $priorityComponent + $ageComponent);

		return [
			'tier' => $tier,
			'daysUntilDeadline' => $daysUntilDeadline,
			'score' => round($score, 2),
			'scoreBreakdown' => [
				'deadline' => round($deadlineComponent, 2),
				'priority' => round($priorityComponent, 2),
				'age' => round($ageComponent, 2),
			],
		];
	}//end scoreItem()

	/**
	 * Build scored case queue items for one user.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseSchema Case schema slug/id.
	 * @param string $userId User id to scope to.
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queueCaseItems(object $objectService, string $register, string $caseSchema, string $userId, DateTimeImmutable $now): array {
		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['assignee' => $userId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: case search failed', ['error' => $e->getMessage()]);
			return [];
		}

		$items = [];
		foreach ($cases as $case) {
			$endDate = (string)($case['endDate'] ?? '');
			if ($endDate !== '') {
				// Closed case — not part of the open queue.
				continue;
			}

			$caseId = (string)($case['id'] ?? '');
			$fallbackDate = (string)($case['deadline'] ?? '');
			$deadline = $this->resolveCaseDeadline(objectService: $objectService, register: $register, caseId: $caseId, fallback: $fallbackDate);
			$priority = (string)($case['priority'] ?? 'normal');
			$startDate = (string)($case['startDate'] ?? '');

			$scoring = $this->scoreItem(deadline: $deadline, priority: $priority, referenceDate: $startDate, now: $now);

			$items[] = array_merge(
				[
					'itemType' => 'case',
					'id' => $caseId,
					'title' => (string)($case['title'] ?? ($case['identifier'] ?? $caseId)),
					'identifier' => (string)($case['identifier'] ?? ''),
					'caseType' => ($case['caseType'] ?? null),
					'status' => ($case['status'] ?? null),
					'priority' => $priority,
					'deadline' => $deadline,
				],
				$scoring
			);
		}//end foreach

		return $items;
	}//end queueCaseItems()

	/**
	 * Build scored task queue items for one user.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $taskSchema Task schema slug/id.
	 * @param string $userId User id to scope to.
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queueTaskItems(object $objectService, string $register, string $taskSchema, string $userId, DateTimeImmutable $now): array {
		try {
			$tasks = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $taskSchema,
				filters: ['assignee' => $userId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: task search failed', ['error' => $e->getMessage()]);
			return [];
		}

		$items = [];
		foreach ($tasks as $task) {
			$status = (string)($task['status'] ?? '');
			if (in_array($status, self::TASK_TERMINAL_STATUSES, true) === true) {
				continue;
			}

			$priority = (string)($task['priority'] ?? 'normal');
			$dueDate = (string)($task['dueDate'] ?? '');
			$deadline = null;
			if ($dueDate !== '') {
				$deadline = $dueDate;
			}

			$scoring = $this->scoreItem(deadline: $deadline, priority: $priority, referenceDate: null, now: $now);

			$items[] = array_merge(
				[
					'itemType' => 'task',
					'id' => (string)($task['id'] ?? ''),
					'title' => (string)($task['title'] ?? ''),
					'case' => ($task['case'] ?? null),
					'status' => $status,
					'priority' => $priority,
					'deadline' => $deadline,
				],
				$scoring
			);
		}//end foreach

		return $items;
	}//end queueTaskItems()

	/**
	 * Resolve a case's nearest active termijn deadline, falling back to the
	 * case's own computed `deadline` field when no active termijn instance
	 * tracks it (or termijn tracking is not configured).
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseId Case id.
	 * @param string $fallback The case's own `deadline` field value.
	 *
	 * @return string|null The resolved deadline, or null when none available.
	 */
	private function resolveCaseDeadline(object $objectService, string $register, string $caseId, string $fallback): ?string {
		$nearest = $this->nearestActiveTermDeadline(objectService: $objectService, register: $register, caseId: $caseId);
		if ($nearest !== null) {
			return $nearest;
		}

		if ($fallback !== '') {
			return $fallback;
		}

		return null;
	}//end resolveCaseDeadline()

	/**
	 * Find the nearest `einddatumActueel` among a case's active (`lopend`)
	 * termijn instances, or null when termijn tracking is not configured, the
	 * case has none, or the lookup fails.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseId Case id.
	 *
	 * @return string|null The nearest active deadline, or null.
	 */
	private function nearestActiveTermDeadline(object $objectService, string $register, string $caseId): ?string {
		$termSchema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($termSchema === '' || $caseId === '') {
			return null;
		}

		try {
			$instances = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $termSchema,
				filters: [
					'case' => $caseId,
					'status' => 'lopend',
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		$nearest = null;
		foreach ($instances as $instance) {
			$date = (string)($instance['endDateCurrent'] ?? '');
			if ($date === '' || ($nearest !== null && $date >= $nearest)) {
				continue;
			}

			$nearest = $date;
		}

		return $nearest;
	}//end nearestActiveTermijnDeadline()

	/**
	 * Determine the urgency tier for a given business-day offset.
	 *
	 * @param int $daysUntilDeadline Signed business-day offset (negative = overdue).
	 *
	 * @return string One of the TIER_* constants.
	 */
	private function tierFor(int $daysUntilDeadline): string {
		if ($daysUntilDeadline < 0) {
			return self::TIER_OVERDUE;
		}

		if ($daysUntilDeadline <= 3) {
			return self::TIER_CRITICAL;
		}

		if ($daysUntilDeadline <= 7) {
			return self::TIER_WARNING;
		}

		return self::TIER_NORMAL;
	}//end tierFor()

	/**
	 * Count signed business days (Mon–Fri) between two dates.
	 *
	 * Returns 0 when the dates are the same calendar day, a positive count
	 * when `target` is in the future, negative when in the past. Weekend
	 * days are never counted. Bounded by MAX_BUSINESS_DAY_WALK to guard
	 * against pathological input.
	 *
	 * @param DateTimeImmutable $today The reference "today" (date-only).
	 * @param DateTimeImmutable $target The target date (date-only).
	 *
	 * @return int Signed business-day offset.
	 */
	private function businessDaysBetween(DateTimeImmutable $today, DateTimeImmutable $target): int {
		if ($today->format('Y-m-d') === $target->format('Y-m-d')) {
			return 0;
		}

		$direction = 1;
		if ($target < $today) {
			$direction = -1;
		}

		$cursor = $today;
		$count = 0;
		$walked = 0;

		while ($cursor->format('Y-m-d') !== $target->format('Y-m-d') && $walked < self::MAX_BUSINESS_DAY_WALK) {
			$step = '+1 day';
			if ($direction < 0) {
				$step = '-1 day';
			}

			$cursor = $cursor->modify($step);
			$dow = (int)$cursor->format('N');
			if ($dow < 6) {
				$count++;
			}

			$walked++;
		}

		return ($count * $direction);
	}//end businessDaysBetween()

	/**
	 * Parse a date string into a date-only DateTimeImmutable, or null when
	 * empty/unparseable.
	 *
	 * @param string|null $value The raw date/date-time string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseDateOnly(?string $value): ?DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}

		try {
			$parsed = new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			return null;
		}

		return new DateTimeImmutable($parsed->format('Y-m-d'));
	}//end parseDateOnly()
}//end class
