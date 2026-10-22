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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\QueueCaseFacts;
use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\Queue\UrgencyScorer;
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
 * Scoring is `Queue\UrgencyScorer`; what is read about a case before it is
 * scored is `Queue\QueueCaseFacts`. This class reads the cases and tasks,
 * ranks them, and counts the open work.
 */
class WorkQueueService {
	use SearchesObjects;

	/**
	 * Maximum number of open cases one queue computation reads. Named, so the
	 * search never falls back to OpenRegister's default page and silently
	 * leaves every case past it out of the ranking.
	 */
	private const QUEUE_CASE_LIMIT = 1000;

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
	 * @param QueueUrgencySettings $urgencySettings The admin's thresholds and weights.
	 * @param UrgencyScorer $scorer Scores one item.
	 * @param QueueCaseFacts $caseFacts The deadline, last activity and urgency profile of a case.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly EngineTaskInbox $engineTasks,
		private readonly LoggerInterface $logger,
		private readonly QueueUrgencySettings $urgencySettings,
		private readonly UrgencyScorer $scorer,
		private readonly QueueCaseFacts $caseFacts,
	) {
	}//end __construct()

	/**
	 * Score a single item deterministically. Pure function, no I/O.
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
	 * @see UrgencyScorer::scoreItem()
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function scoreItem(
		?string $deadline,
		string $priority,
		?string $referenceDate,
		DateTimeImmutable $now,
		?UrgencyProfile $profile = null,
	): array {
		return $this->scorer->scoreItem(deadline: $deadline, priority: $priority, referenceDate: $referenceDate, now: $now, profile: $profile);
	}//end scoreItem()

	/**
	 * The instance's thresholds and weights, for a caller that scores items
	 * without a case type (the personal queue).
	 *
	 * @return UrgencyProfile The admin profile.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
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
	 * Open cases the user covers for an absent colleague ({@see Substitution\CoveredWork})
	 * join the ranking when given, scored the same way and never twice; each case item carries
	 * `coveredFor` (`null` for the user's own), on the item and on its row.
	 *
	 * @param string $userId The Nextcloud user id to scope to.
	 * @param DateTimeImmutable|null $now Optional "now" override for testing.
	 * @param array<int, array<string, mixed>> $covered Open covered cases, each with `coveredFor`; already read-checked.
	 *
	 * @return array<int, array<string, mixed>> Scored, sorted queue items.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function computeQueue(string $userId, ?DateTimeImmutable $now = null, array $covered = []): array {
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

		$coveredItems = $this->scoreCases(
			objectService: $objectService,
			register: $register,
			cases: array_values(
				array_filter(
					$covered,
					static fn (array $case): bool => in_array((string)($case['id'] ?? ''), array_column($caseItems, 'id'), true) === false
				)
			),
			now: $now,
			profile: $profile
		);
		foreach ($coveredItems as $item) {
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
	 * @spec openspec/specs/status-transition-engine/spec.md
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
	 * @spec openspec/specs/status-transition-engine/spec.md
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
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
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

		return $this->scoreCases(
			objectService: $objectService,
			register: $register,
			cases: $cases,
			now: $now,
			profile: $profile
		);
	}//end queueCaseItems()

	/**
	 * Score open cases into queue items.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug/id.
	 * @param array<int, array<string, mixed>> $cases The cases.
	 * @param DateTimeImmutable $now Now.
	 * @param UrgencyProfile $profile The instance profile.
	 *
	 * @return array<int, array<string, mixed>> One item per open case, each with `coveredFor`.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function scoreCases(
		object $objectService,
		string $register,
		array $cases,
		DateTimeImmutable $now,
		UrgencyProfile $profile,
	): array {
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
			$deadline = $this->caseFacts->resolveCaseDeadline(objectService: $objectService, register: $register, caseId: $caseId, fallback: $fallbackDate);
			$priority = (string)($case['priority'] ?? 'normal');

			$caseTypeId = $this->caseFacts->caseTypeIdOf(case: $case);
			if (array_key_exists($caseTypeId, $profilesByType) === false) {
				$profilesByType[$caseTypeId] = $this->caseFacts->profileForCaseType(
					objectService: $objectService,
					register: $register,
					caseTypeId: $caseTypeId,
					profile: $profile
				);
			}

			$scoring = $this->scoreItem(
				deadline: $deadline,
				priority: $priority,
				referenceDate: $this->caseFacts->lastActivityOf(case: $case),
				now: $now,
				profile: $profilesByType[$caseTypeId]
			);

			$case['coveredFor'] = ($case['coveredFor'] ?? null);
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
					'coveredFor' => $case['coveredFor'],
					'case' => $case,
				],
				$scoring
			);
		}//end foreach

		return $items;
	}//end scoreCases()

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

}//end class
