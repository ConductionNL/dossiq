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
use OCA\Dossiq\Service\Queue\CaseQueueSource;
use OCA\Dossiq\Service\Queue\QueueCaseFacts;
use OCA\Dossiq\Service\Queue\QueueScoringRun;
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
	 * The waiting-on declarations counted apart from ours, by their count key.
	 */
	private const WAITING_ON_BUCKETS = [
		StatusDeclaration::WAITING_ON_APPLICANT => 'applicant',
		StatusDeclaration::WAITING_ON_THIRD_PARTY => 'thirdParty',
	];

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

		$source = $this->caseSource();
		if ($source === null || $userId === '') {
			return [];
		}

		$run = new QueueScoringRun(source: $source, now: $now, profile: $this->urgencySettings->profile());

		$caseItems = $this->queueCaseItems(run: $run, userId: $userId);
		$items = array_merge(
			$caseItems,
			$this->scoreCases(run: $run, cases: $this->notYetQueued(covered: $covered, caseItems: $caseItems)),
			$this->queueTaskItems(run: $run, userId: $userId)
		);

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
		$source = $this->caseSource();
		if ($source === null) {
			return $this->tallyWaitingOn(cases: []);
		}

		$filters = ['_limit' => self::WORKLOAD_LIMIT];
		if ((string)$userId !== '') {
			$filters['assignee'] = $userId;
		}

		try {
			$cases = $this->searchCases(source: $source, filters: $filters);
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
			if (is_array($case) === false || $this->isFinished(row: $case) === true) {
				continue;
			}

			$counts['total']++;
			$counts[$this->waitingBucket(row: $case)]++;
		}

		return $counts;
	}//end tallyWaitingOn()

	/**
	 * Whether a case is closed or stands in a final status.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return bool True when nobody waits on it any more.
	 */
	private function isFinished(array $row): bool {
		return $this->isClosed(row: $row) === true || ($row['isFinalStatus'] ?? false) === true;
	}//end isFinished()

	/**
	 * Whether a case has an end date.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return bool True when the case is closed.
	 */
	private function isClosed(array $row): bool {
		return (string)($row['endDate'] ?? '') !== '';
	}//end isClosed()

	/**
	 * The count a case's waiting-on declaration falls in; an undeclared case is ours.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return string The bucket key.
	 */
	private function waitingBucket(array $row): string {
		return (self::WAITING_ON_BUCKETS[trim((string)($row['waitingOn'] ?? ''))] ?? 'ours');
	}//end waitingBucket()

	/**
	 * Compute per-handler open-case counts across all cases.
	 *
	 * @return array<int, array{handler: string, openCaseCount: int}> Handlers sorted by count descending.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function computeWorkload(): array {
		$source = $this->caseSource();
		if ($source === null) {
			return [];
		}

		try {
			$cases = $this->searchCases(source: $source, filters: ['_limit' => self::WORKLOAD_LIMIT]);
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
			if ($this->isClosed(row: $case) === true) {
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
	 * The configured case source, or null while the register or case schema is not set.
	 *
	 * @return CaseQueueSource|null The source.
	 */
	private function caseSource(): ?CaseQueueSource {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $caseSchema === '') {
			return null;
		}

		return new CaseQueueSource(objectService: $objectService, register: $register, caseSchema: $caseSchema);
	}//end caseSource()

	/**
	 * Read cases from the source as plain arrays.
	 *
	 * @param CaseQueueSource      $source  Where the cases live.
	 * @param array<string, mixed> $filters The search filters.
	 *
	 * @return array<int, array<string, mixed>> The case rows.
	 */
	private function searchCases(CaseQueueSource $source, array $filters): array {
		return $this->searchObjectsAsArrays(
			objectService: $source->objectService,
			register: $source->register,
			schema: $source->caseSchema,
			filters: $filters
		);
	}//end searchCases()

	/**
	 * The covered cases that are not already in the queue as the user's own.
	 *
	 * @param array<int, array<string, mixed>> $covered   The covered cases.
	 * @param array<int, array<string, mixed>> $caseItems The user's own case items.
	 *
	 * @return array<int, array<string, mixed>> The covered cases still to score.
	 */
	private function notYetQueued(array $covered, array $caseItems): array {
		$queuedIds = array_column($caseItems, 'id');

		return array_values(
			array_filter(
				$covered,
				static fn (array $case): bool => in_array((string)($case['id'] ?? ''), $queuedIds, true) === false
			)
		);
	}//end notYetQueued()

	/**
	 * Build scored case queue items for one user.
	 *
	 * The cases are read with the filters the My Work list uses, so the
	 * ranked set is the same set the list shows, and with a named limit so no
	 * open case falls off a default page. Each item carries the case row, so
	 * a list can render the card from the ranked answer.
	 *
	 * @param QueueScoringRun $run    The source, instant and profile of this computation.
	 * @param string          $userId User id to scope to.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function queueCaseItems(QueueScoringRun $run, string $userId): array {
		try {
			$cases = $this->searchCases(
				source: $run->source,
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

		return $this->scoreCases(run: $run, cases: $cases);
	}//end queueCaseItems()

	/**
	 * Score open cases into queue items, reading each case type's profile once.
	 *
	 * @param QueueScoringRun                  $run   The source, instant and instance profile.
	 * @param array<int, array<string, mixed>> $cases The cases.
	 *
	 * @return array<int, array<string, mixed>> One item per open case, each with `coveredFor`.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function scoreCases(QueueScoringRun $run, array $cases): array {
		$profilesByType = [];
		$items = [];
		foreach ($cases as $case) {
			if ($this->isClosed(row: $case) === true) {
				// Closed case — not part of the open queue.
				continue;
			}

			$caseTypeId = $this->caseFacts->caseTypeIdOf($case);
			if (array_key_exists($caseTypeId, $profilesByType) === false) {
				$profilesByType[$caseTypeId] = $this->caseTypeProfile(run: $run, caseTypeId: $caseTypeId);
			}

			$items[] = $this->caseItem(run: $run->withProfile(profile: $profilesByType[$caseTypeId]), row: $case);
		}

		return $items;
	}//end scoreCases()

	/**
	 * The profile one case type scores with: its own thresholds over the instance profile.
	 *
	 * @param QueueScoringRun $run        The source and instance profile.
	 * @param string          $caseTypeId The case type id.
	 *
	 * @return UrgencyProfile The profile.
	 */
	private function caseTypeProfile(QueueScoringRun $run, string $caseTypeId): UrgencyProfile {
		return $this->caseFacts->profileForCaseType(
			objectService: $run->source->objectService,
			register: $run->source->register,
			caseTypeId: $caseTypeId,
			profile: $run->profile
		);
	}//end caseTypeProfile()

	/**
	 * Score one open case into a queue item.
	 *
	 * @param QueueScoringRun      $run  The source, instant and the case type's profile.
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return array<string, mixed> The queue item, carrying the row and `coveredFor`.
	 */
	private function caseItem(QueueScoringRun $run, array $row): array {
		$rowId = (string)($row['id'] ?? '');
		$deadline = $this->caseFacts->resolveCaseDeadline(
			objectService: $run->source->objectService,
			register: $run->source->register,
			caseId: $rowId,
			fallback: (string)($row['deadline'] ?? '')
		);
		$priority = (string)($row['priority'] ?? 'normal');
		$referenceDate = $this->caseFacts->lastActivityOf($row);
		$scoring = $this->scoreItem(deadline: $deadline, priority: $priority, referenceDate: $referenceDate, now: $run->now, profile: $run->profile);

		$row['coveredFor'] = ($row['coveredFor'] ?? null);

		return array_merge(
			[
				'itemType' => 'case',
				'id' => $rowId,
				'title' => (string)($row['title'] ?? ($row['identifier'] ?? $rowId)),
				'identifier' => (string)($row['identifier'] ?? ''),
				'caseType' => ($row['caseType'] ?? null),
				'status' => ($row['status'] ?? null),
				'priority' => $priority,
				'deadline' => $deadline,
				'coveredFor' => $row['coveredFor'],
				'case' => $row,
			],
			$scoring
		);
	}//end caseItem()

	/**
	 * Build scored task queue items for one user.
	 *
	 * @param QueueScoringRun $run    The instant and instance profile.
	 * @param string          $userId User id to scope to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queueTaskItems(QueueScoringRun $run, string $userId): array {
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

			$scoring = $this->scoreItem(deadline: $deadline, priority: $priority, referenceDate: null, now: $run->now, profile: $run->profile);

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
