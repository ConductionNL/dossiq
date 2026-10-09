<?php

/**
 * Dossiq Work Queue Service
 *
 * Computes a deterministic urgency score for a case handler's open cases
 * and tasks (the deadline tier, priority, and how long a case has been lying
 * still), and a per-handler open-case workload summary for coordinators.
 *
 * The deadline tier ("termijnstatus") is deliberately not called urgency in
 * code: a case also carries the ITIL `urgency` field, which CasePriorityService
 * combines with impact into the priority. The user-facing word stays
 * "urgentie", for the score this class computes.
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
 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
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
	 * Deadline tier (termijnstatus) constants.
	 */
	private const DEADLINE_TIER_OVERDUE = 'overdue';
	private const DEADLINE_TIER_CRITICAL = 'critical';
	private const DEADLINE_TIER_WARNING = 'warning';
	private const DEADLINE_TIER_NORMAL = 'normal';

	/**
	 * Base score per deadline tier. Higher tiers score higher; the deadline
	 * component further differentiates within a tier by exact day count.
	 * The 250 points between two bases are more than the priority and idle
	 * parts can add together (see UrgencyProfile's bounds), so the tier
	 * always decides first.
	 *
	 * @var array<string, float>
	 */
	private const DEADLINE_TIER_BASE_SCORE = [
		self::DEADLINE_TIER_OVERDUE => 1000.0,
		self::DEADLINE_TIER_CRITICAL => 750.0,
		self::DEADLINE_TIER_WARNING => 500.0,
		self::DEADLINE_TIER_NORMAL => 250.0,
	];

	/**
	 * Priority steps; the profile's priority weight is paid per step.
	 *
	 * @var array<string, int>
	 */
	private const PRIORITY_POINTS = [
		'urgent' => 3,
		'high' => 2,
		'normal' => 1,
		'low' => 0,
	];

	/**
	 * Fallback priority step for an unknown/empty priority value.
	 */
	private const DEFAULT_PRIORITY_POINTS = 1;

	/**
	 * Idle component: days lying still count up to this cap.
	 */
	private const MAX_IDLE_DAYS = 60;

	/**
	 * Maximum number of open cases one queue computation reads. Named, so the
	 * search never falls back to OpenRegister's default page and silently
	 * leaves every case past it out of the ranking.
	 */
	private const QUEUE_CASE_LIMIT = 1000;

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (register/schema config + ObjectService).
	 * @param EngineTaskInbox $engineTasks     The engine's inbox reader.
	 * @param LoggerInterface $logger Logger.
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param QueueUrgencySettings $urgencySettings The admin's thresholds and weights.
	 * @param CaseJournal $journal Reads the case's own record of acts.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineTaskInbox $engineTasks,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		private readonly QueueUrgencySettings $urgencySettings,
		private readonly CaseJournal $journal,
	) {
	}//end __construct()

	/**
	 * The instance's thresholds and weights, for a caller that scores items
	 * without a case type (the personal queue).
	 *
	 * @return UrgencyProfile The admin profile.
	 *
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function adminProfile(): UrgencyProfile {
		return $this->urgencySettings->profile();
	}//end adminProfile()

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

		$profile = $this->urgencySettings->profile();

		$items = [];
		$caseItems = $this->queueCaseItems(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			userId: $userId,
			now: $now,
			profile: $profile
		);
		foreach ($caseItems as $item) {
			$items[] = $item;
		}

		foreach ($this->queueTaskItems(userId: $userId, now: $now, profile: $profile) as $item) {
			$items[] = $item;
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
	 * Count the open cases in a queue by who the queue is waiting on.
	 *
	 * The question a team lead asks is not how many cases are open, it is how
	 * many of them anybody on the team can actually move today. A queue of
	 * forty with twelve on the applicant and six on an advisory body is a queue
	 * of twenty-two, and the other eighteen are somebody else's turn.
	 *
	 * @param string|null $userId Scope to one handler's queue, or null for the whole list.
	 *
	 * @return array{ours: int, applicant: int, thirdParty: int, total: int}
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function countByWaitingOn(?string $userId = null): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return $this->tallyWaitingOn(cases: []);
		}

		$filters = ['_limit' => self::WORKLOAD_LIMIT];
		if ($userId !== null && $userId !== '') {
			$filters['assignee'] = $userId;
		}

		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: $filters
			);
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: waiting-on count search failed', ['error' => $e->getMessage()]);
			return $this->tallyWaitingOn(cases: []);
		}

		return $this->tallyWaitingOn(cases: $cases);
	}//end countByWaitingOn()

	/**
	 * Tally open cases by who each one waits on. Pure function — no I/O.
	 *
	 * An UNDECLARED case counts as ours, which is the whole reason
	 * `statusType.waitingOn` coalesces to `us` on the case: a fourth bucket
	 * called "not declared" would hold most of the queue on every case type
	 * nobody has annotated, and a count nobody trusts is a count nobody reads.
	 *
	 * A CLOSED case counts in none of the three. Nobody is waiting on a case
	 * that is finished, and a team lead who saw last year's work in this
	 * week's number would stop using the number.
	 *
	 * @param array<int, array<string, mixed>> $cases The raw case rows.
	 *
	 * @return array{ours: int, applicant: int, thirdParty: int, total: int}
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function tallyWaitingOn(array $cases): array {
		$counts = ['ours' => 0, 'applicant' => 0, 'thirdParty' => 0, 'total' => 0];

		foreach ($cases as $case) {
			if (is_array($case) === false) {
				continue;
			}

			if ((string)($case['endDate'] ?? '') !== '' || ($case['isFinalStatus'] ?? false) === true) {
				continue;
			}

			$counts['total']++;
			$waitingOn = trim((string)($case['waitingOn'] ?? ''));
			if ($waitingOn === StatusDeclaration::WAITING_ON_APPLICANT) {
				$counts['applicant']++;
				continue;
			}

			if ($waitingOn === StatusDeclaration::WAITING_ON_THIRD_PARTY) {
				$counts['thirdParty']++;
				continue;
			}

			$counts['ours']++;
		}

		return $counts;
	}//end tallyWaitingOn()

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
	 * Score a single item deterministically. Pure function, no I/O.
	 *
	 * The deadline tier comes from the working days left against the
	 * profile's thresholds; the score adds the priority and idle parts the
	 * profile weighs. The reference date is the last activity on the item:
	 * the idle part counts the calendar days since it, capped, and a moment in
	 * the future counts as today.
	 *
	 * @param string|null $deadline Resolved deadline (Y-m-d or parseable date), or null.
	 * @param string $priority Priority value (low/normal/high/urgent), any casing.
	 * @param string|null $referenceDate The last activity on the item, or null for no idle part.
	 * @param DateTimeImmutable $now The "now" instant the score is computed against.
	 * @param UrgencyProfile|null $profile Thresholds and weights; null scores with the defaults.
	 *
	 * @return array{
	 *     deadlineTier: string,
	 *     daysUntilDeadline: int|null,
	 *     idleDays: int|null,
	 *     score: float,
	 *     scoreBreakdown: array{deadline: float, priority: float, idle: float}
	 * } Score result.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function scoreItem(
		?string $deadline,
		string $priority,
		?string $referenceDate,
		DateTimeImmutable $now,
		?UrgencyProfile $profile = null,
	): array {
		$profile = ($profile ?? new UrgencyProfile());
		$today = new DateTimeImmutable($now->format('Y-m-d'));

		$daysUntilDeadline = null;
		$deadlineTier = self::DEADLINE_TIER_NORMAL;
		$deadlineComponent = 0.0;

		$deadlineDate = $this->dates->tryParse($this->dates->toCalendarDateOrNull($deadline));
		if ($deadlineDate !== null) {
			$daysUntilDeadline = $this->businessDaysBetween(today: $today, target: $deadlineDate);
			$deadlineTier = $this->deadlineTierFor(daysUntilDeadline: $daysUntilDeadline, profile: $profile);
			$deadlineComponent = (self::DEADLINE_TIER_BASE_SCORE[$deadlineTier] - $daysUntilDeadline);
		}

		$priorityKey = strtolower(trim($priority));
		$priorityComponent = ((self::PRIORITY_POINTS[$priorityKey] ?? self::DEFAULT_PRIORITY_POINTS) * $profile->priorityWeight);

		$idleDays = null;
		$idleComponent = 0.0;
		$referenceParsed = $this->dates->tryParse($this->dates->toCalendarDateOrNull($referenceDate));
		if ($referenceParsed !== null) {
			$idleDays = 0;
			if ($referenceParsed < $today) {
				$idleDays = (int)$today->diff($referenceParsed)->days;
			}

			$idleComponent = (min($idleDays, self::MAX_IDLE_DAYS) * $profile->idleWeight);
		}

		$score = ($deadlineComponent + $priorityComponent + $idleComponent);

		return [
			'deadlineTier' => $deadlineTier,
			'daysUntilDeadline' => $daysUntilDeadline,
			'idleDays' => $idleDays,
			'score' => round($score, 2),
			'scoreBreakdown' => [
				'deadline' => round($deadlineComponent, 2),
				'priority' => round($priorityComponent, 2),
				'idle' => round($idleComponent, 2),
			],
		];
	}//end scoreItem()

	/**
	 * Build scored case queue items for one user.
	 *
	 * The cases are read with the filters the My Work list uses, so the
	 * ranked set is the same set the list shows, and with a named limit so no
	 * open case falls off a default page. Each item carries the case row, so
	 * a list can render the card from the ranked answer.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseSchema Case schema slug/id.
	 * @param string $userId User id to scope to.
	 * @param DateTimeImmutable $now Now.
	 * @param UrgencyProfile $profile The instance profile.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function queueCaseItems(
		object $objectService,
		string $register,
		string $caseSchema,
		string $userId,
		DateTimeImmutable $now,
		UrgencyProfile $profile,
	): array {
		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: [
					'assignee' => $userId,
					'statusHiddenInLists' => false,
					'isDraft' => false,
					'_limit' => self::QUEUE_CASE_LIMIT,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('WorkQueue: case search failed', ['error' => $e->getMessage()]);
			return [];
		}

		$profilesByType = [];
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

			$caseTypeId = $this->caseTypeIdOf(case: $case);
			if (array_key_exists($caseTypeId, $profilesByType) === false) {
				$profilesByType[$caseTypeId] = $this->profileForCaseType(
					objectService: $objectService,
					register: $register,
					caseTypeId: $caseTypeId,
					profile: $profile
				);
			}

			$scoring = $this->scoreItem(
				deadline: $deadline,
				priority: $priority,
				referenceDate: $this->lastActivityOf(case: $case),
				now: $now,
				profile: $profilesByType[$caseTypeId]
			);

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
					'case' => $case,
				],
				$scoring
			);
		}//end foreach

		return $items;
	}//end queueCaseItems()

	/**
	 * The last activity on a case: the latest of its last save and the newest
	 * entry in its journal, falling back to its start date.
	 *
	 * `SilenceCloseService::lastActivity()` reads the journal first on
	 * purpose, because its own warning write touches the case. The queue
	 * writes nothing to a case, so the latest of the two is the honest answer
	 * here: an edit that wrote no journal entry is still somebody working on
	 * the case.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string|null The moment, or null when the case carries none.
	 *
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function lastActivityOf(array $case): ?string {
		$latest = null;
		foreach ($this->activityMoments(case: $case) as $moment) {
			if ($latest === null || $moment > $latest) {
				$latest = $moment;
			}
		}

		if ($latest !== null) {
			return $latest->format('Y-m-d');
		}

		$startDate = (string)($case['startDate'] ?? '');
		if ($startDate === '') {
			return null;
		}

		return $startDate;
	}//end lastActivityOf()

	/**
	 * Every moment a case records activity: its last save and each journal entry.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<int, DateTimeImmutable> The moments that parse.
	 */
	private function activityMoments(array $case): array {
		$candidates = [($case['@self']['updated'] ?? null)];
		foreach ($this->journal->entries(case: $case) as $entry) {
			$candidates[] = ($entry['at'] ?? null);
		}

		$moments = [];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false || $candidate === '') {
				continue;
			}

			$moment = $this->dates->tryParse($candidate);
			if ($moment !== null) {
				$moments[] = $moment;
			}
		}

		return $moments;
	}//end activityMoments()

	/**
	 * The case type id a case row names, whether as a uuid or an object.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The id, or '' when the case names none.
	 */
	private function caseTypeIdOf(array $case): string {
		$caseType = ($case['caseType'] ?? '');
		if (is_array($caseType) === true) {
			$caseType = ($caseType['id'] ?? ($caseType['uuid'] ?? ''));
		}

		if (is_string($caseType) === false) {
			return '';
		}

		return $caseType;
	}//end caseTypeIdOf()

	/**
	 * The profile for the cases of one case type: the instance profile with
	 * the type's own thresholds where it sets them.
	 *
	 * A case type that cannot be read scores with the instance profile, and
	 * says so in the log: a queue that refuses to rank because one case type
	 * went missing helps nobody.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param string $caseTypeId The case type id, or ''.
	 * @param UrgencyProfile $profile The instance profile.
	 *
	 * @return UrgencyProfile The profile for that type.
	 *
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function profileForCaseType(object $objectService, string $register, string $caseTypeId, UrgencyProfile $profile): UrgencyProfile {
		$caseTypeSchema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($caseTypeId === '' || $caseTypeSchema === '') {
			return $profile;
		}

		try {
			$caseType = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseTypeSchema,
				id: $caseTypeId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WorkQueue: case type read failed, scoring with the instance thresholds',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
			return $profile;
		}

		if ($caseType === null) {
			return $profile;
		}

		return $profile->withCaseTypeThresholds(
			criticalDays: ($caseType['queueCriticalDays'] ?? null),
			warningDays: ($caseType['queueWarningDays'] ?? null)
		);
	}//end profileForCaseType()

	/**
	 * Build scored task queue items for one user.
	 *
	 * @param string $userId User id to scope to.
	 * @param DateTimeImmutable $now Now.
	 * @param UrgencyProfile $profile The instance profile.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queueTaskItems(string $userId, DateTimeImmutable $now, UrgencyProfile $profile): array {
		// The ENGINE, and the open/closed split is made THERE. Filtering a
		// paged window client-side silently drops every open task past the
		// boundary, which is how a queue comes to look empty on the day
		// somebody has a hundred things to do.
		$tasks = $this->engineTasks->openForAssignee(actor: $userId);

		$items = [];
		foreach ($tasks as $task) {
			$priority = (string)($task['priority'] ?? 'normal');
			$dueDate = (string)($task['dueDate'] ?? '');
			$deadline = null;
			if ($dueDate !== '') {
				$deadline = $dueDate;
			}

			$scoring = $this->scoreItem(deadline: $deadline, priority: $priority, referenceDate: null, now: $now, profile: $profile);

			$items[] = array_merge(
				[
					'itemType' => 'task',
					'id' => (string)($task['id'] ?? ''),
					'title' => (string)($task['title'] ?? ''),
					'case' => ($task['case'] ?? null),
					'status' => (string)($task['status'] ?? ''),
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
	 * Determine the deadline tier for a given business-day offset.
	 *
	 * @param int $daysUntilDeadline Signed business-day offset (negative = overdue).
	 * @param UrgencyProfile $profile The thresholds to hold it against.
	 *
	 * @return string One of the DEADLINE_TIER_* constants.
	 *
	 * @spec openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function deadlineTierFor(int $daysUntilDeadline, UrgencyProfile $profile): string {
		if ($daysUntilDeadline < 0) {
			return self::DEADLINE_TIER_OVERDUE;
		}

		if ($daysUntilDeadline <= $profile->criticalDays) {
			return self::DEADLINE_TIER_CRITICAL;
		}

		if ($daysUntilDeadline <= $profile->warningDays) {
			return self::DEADLINE_TIER_WARNING;
		}

		return self::DEADLINE_TIER_NORMAL;
	}//end deadlineTierFor()

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

}//end class
