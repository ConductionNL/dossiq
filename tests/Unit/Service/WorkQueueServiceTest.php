<?php

/**
 * WorkQueueService Unit Tests
 *
 * Tests for the Dossiq WorkQueueService that computes the intelligent
 * work-queue urgency score (deadline math, priority, case age) and the
 * coordinator workload summary.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\WorkQueueService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\WorkQueueService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Queue\QueueUrgencySettings
 * @uses \OCA\Dossiq\Service\Queue\UrgencyProfile
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class WorkQueueServiceTest extends TestCase {
	use MakesCaseDateNormaliser;


	private FakeWorkQueueStore $objects;

	private WorkQueueService $service;

	/**
	 * App config the settings double answers, per test.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeWorkQueueStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$this->config = [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'case_type_schema' => 'caseType',
			'task_schema' => 'caseTask',
			'termijn_instance_schema' => 'deadlineInstance',
		];
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		// The engine's inbox, over the tasks this test seeds. The work queue
		// reads tasks from the engine now; cases still come from the object
		// service, so both doubles are in play.
		$engineTasks = new class ($this->objects) extends EngineTaskInbox {
			/**
			 * @param object $objects The in-memory object double.
			 */
			public function __construct(private readonly object $objects) {
			}

			/**
			 * @param string  $actor The person.
			 * @param integer $limit The page size.
			 *
			 * @return array<int, array<string, mixed>> The open tasks.
			 */
			public function openForAssignee(string $actor, int $limit = 200): array {
				$open = [];
				$rows = $this->objects->searchObjectsBySlug('dossiq', 'caseTask', ['assignee' => $actor]);
				foreach ($rows as $task) {
					// The ENGINE makes this split; the double mirrors it so
					// the test exercises what the service actually receives.
					if (in_array((string) ($task['status'] ?? ''), ['completed', 'terminated', 'disabled'], true) === true) {
						continue;
					}

					$open[] = $task;
				}

				return $open;
			}
		};

		$this->service = new WorkQueueService(
			settingsService: $settings,
			engineTasks: $engineTasks,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
			urgencySettings: new QueueUrgencySettings(settings: $settings),
			journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)),
		);
	}//end setUp()

	/**
	 * A profile with the given numbers, through the same normalisation the
	 * settings use.
	 *
	 * @param mixed $critical Critical threshold.
	 * @param mixed $warning  Warning threshold.
	 * @param mixed $priority Priority weight.
	 * @param mixed $idle     Idle weight.
	 *
	 * @return UrgencyProfile The profile.
	 */
	private function profile(mixed $critical = 3, mixed $warning = 7, mixed $priority = 10, mixed $idle = 0.5): UrgencyProfile {
		return new UrgencyProfile(criticalDays: $critical, warningDays: $warning, priorityWeight: $priority, idleWeight: $idle);
	}//end profile()

	/**
	 * An open case assigned to jan, as the My Work filters see it.
	 *
	 * @param array<string, mixed> $fields Fields on top of the defaults.
	 *
	 * @return void
	 */
	private function seedOpenCase(array $fields): void {
		$this->objects->saveObject(
			'case',
			array_merge(
				[
					'assignee' => 'jan',
					'endDate' => '',
					'priority' => 'normal',
					'statusHiddenInLists' => false,
					'isDraft' => false,
				],
				$fields
			)
		);
	}//end seedOpenCase()

	// ── Pure scoreItem() tests ──────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testNoDeadlineScoresNormalTierWithNullDays(): void {
		$result = $this->service->scoreItem(null, 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame('normal', $result['deadlineTier']);
		self::assertNull($result['daysUntilDeadline']);
	}//end testNoDeadlineScoresNormalTierWithNullDays()

	/**
	 * @return void
	 */
	public function testOverdueDeadlineScoresOverdueTier(): void {
		// 2026-07-13 is a Monday; 2 business days before is 2026-07-09 (Thu).
		$result = $this->service->scoreItem('2026-07-09', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame('overdue', $result['deadlineTier']);
		self::assertLessThan(0, $result['daysUntilDeadline']);
	}//end testOverdueDeadlineScoresOverdueTier()

	/**
	 * @return void
	 */
	public function testCriticalTierAtZeroBusinessDays(): void {
		// Same calendar day → 0 business days.
		$result = $this->service->scoreItem('2026-07-13', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(0, $result['daysUntilDeadline']);
		self::assertSame('critical', $result['deadlineTier']);
	}//end testCriticalTierAtZeroBusinessDays()

	/**
	 * The administered calendar decides the business days, not Monday to Friday.
	 *
	 * Friday 24 April to Monday 27 April 2026 is one working day by the walk;
	 * the calendar knows Koningsdag (27 April) and answers zero. The bounds
	 * passed are the days after today up to and including the target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijnbewaking-schemas/spec.md#requirement-lead-times-and-the-work-queue-count-working-days-on-the-administered-calendar-req-ote-04
	 */
	public function testTheAdministeredCalendarCountsTheBusinessDays(): void {
		$calendar = $this->createMock(WorkingDayRoll::class);
		$calendar->expects(self::once())->method('daysBetween')
			->with(
				self::callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-04-25 00:00'),
				self::callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-04-28 00:00'),
				WorkingDayRoll::MODE_WORKING_DAYS
			)
			->willReturn(0);
		$settings = $this->createMock(SettingsService::class);
		$service  = new WorkQueueService(
			settingsService: $settings,
			engineTasks: $this->createMock(EngineTaskInbox::class),
			logger: $this->createMock(LoggerInterface::class),
			dates: $this->caseDates(),
			urgencySettings: new QueueUrgencySettings(settings: $settings),
			journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)),
			calendar: $calendar,
		);

		$result = $service->scoreItem('2026-04-27', 'normal', null, new DateTimeImmutable('2026-04-24'));

		self::assertSame(0, $result['daysUntilDeadline']);
	}//end testTheAdministeredCalendarCountsTheBusinessDays()

	/**
	 * @return void
	 */
	public function testCriticalTierAtThreeBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 3 business days = 2026-07-16 (Thu).
		$result = $this->service->scoreItem('2026-07-16', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(3, $result['daysUntilDeadline']);
		self::assertSame('critical', $result['deadlineTier']);
	}//end testCriticalTierAtThreeBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testWarningTierAtFourBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 4 business days = 2026-07-17 (Fri).
		$result = $this->service->scoreItem('2026-07-17', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(4, $result['daysUntilDeadline']);
		self::assertSame('warning', $result['deadlineTier']);
	}//end testWarningTierAtFourBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testWarningTierAtSevenBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 7 business days = 2026-07-22 (Wed), skipping the weekend.
		$result = $this->service->scoreItem('2026-07-22', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(7, $result['daysUntilDeadline']);
		self::assertSame('warning', $result['deadlineTier']);
	}//end testWarningTierAtSevenBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testNormalTierAtEightBusinessDaysBoundary(): void {
		// 2026-07-13 (Mon) + 8 business days = 2026-07-23 (Thu).
		$result = $this->service->scoreItem('2026-07-23', 'normal', null, new DateTimeImmutable('2026-07-13'));

		self::assertSame(8, $result['daysUntilDeadline']);
		self::assertSame('normal', $result['deadlineTier']);
	}//end testNormalTierAtEightBusinessDaysBoundary()

	/**
	 * @return void
	 */
	public function testHigherPriorityScoresHigherWithinSameTier(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$urgent = $this->service->scoreItem('2026-07-22', 'urgent', null, $now);
		$low = $this->service->scoreItem('2026-07-22', 'low', null, $now);

		self::assertSame($urgent['deadlineTier'], $low['deadlineTier']);
		self::assertGreaterThan($low['score'], $urgent['score']);
	}//end testHigherPriorityScoresHigherWithinSameTier()

	/**
	 * @return void
	 */
	public function testUnknownPriorityFallsBackToNormalWeight(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$unknown = $this->service->scoreItem(null, 'mystery', null, $now);
		$normal = $this->service->scoreItem(null, 'normal', null, $now);

		self::assertSame($normal['scoreBreakdown']['priority'], $unknown['scoreBreakdown']['priority']);
	}//end testUnknownPriorityFallsBackToNormalWeight()

	/**
	 * @return void
	 */
	public function testOlderLastActivityIncreasesIdleComponent(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$old = $this->service->scoreItem(null, 'normal', '2026-05-01', $now);
		$fresh = $this->service->scoreItem(null, 'normal', '2026-07-12', $now);

		self::assertGreaterThan($fresh['scoreBreakdown']['idle'], $old['scoreBreakdown']['idle']);
		self::assertSame(1, $fresh['idleDays']);
	}//end testOlderLastActivityIncreasesIdleComponent()

	/**
	 * @return void
	 */
	public function testFutureLastActivityCountsAsToday(): void {
		$now = new DateTimeImmutable('2026-07-13');

		$result = $this->service->scoreItem(null, 'normal', '2026-12-01', $now);

		self::assertSame(0.0, $result['scoreBreakdown']['idle']);
		self::assertSame(0, $result['idleDays']);
	}//end testFutureLastActivityCountsAsToday()

	/**
	 * The default weights keep today's priority points.
	 *
	 * @return void
	 */
	public function testDefaultWeightsKeepTodaysPriorityPoints(): void {
		$now = new DateTimeImmutable('2026-07-13');
		$parts = [];
		foreach (['urgent', 'high', 'normal', 'low'] as $priority) {
			$parts[] = $this->service->scoreItem(null, $priority, null, $now)['scoreBreakdown']['priority'];
		}

		self::assertSame([30.0, 20.0, 10.0, 0.0], $parts);
	}//end testDefaultWeightsKeepTodaysPriorityPoints()

	/**
	 * Configured thresholds move the tier boundaries.
	 *
	 * @return void
	 */
	public function testConfiguredThresholdsMoveTheBoundaries(): void {
		// Monday 2026-07-13. 5 working days on: Mon 07-20; 6: Tue 07-21;
		// 10: Mon 07-27; 11: Tue 07-28.
		$now = new DateTimeImmutable('2026-07-13');
		$profile = $this->profile(critical: 5, warning: 10);

		$tiers = [];
		foreach (['2026-07-20', '2026-07-21', '2026-07-27', '2026-07-28'] as $deadline) {
			$tiers[] = $this->service->scoreItem($deadline, 'normal', null, $now, $profile)['deadlineTier'];
		}

		self::assertSame(['critical', 'warning', 'warning', 'normal'], $tiers);
	}//end testConfiguredThresholdsMoveTheBoundaries()

	/**
	 * A warning threshold below the critical one leaves no almost-due band.
	 *
	 * @return void
	 */
	public function testWarningBelowCriticalClosesTheSoonBand(): void {
		$now = new DateTimeImmutable('2026-07-13');
		$profile = $this->profile(critical: 5, warning: 2);

		self::assertSame('critical', $this->service->scoreItem('2026-07-20', 'normal', null, $now, $profile)['deadlineTier']);
		self::assertSame('normal', $this->service->scoreItem('2026-07-21', 'normal', null, $now, $profile)['deadlineTier']);
	}//end testWarningBelowCriticalClosesTheSoonBand()

	/**
	 * Weights of zero switch their parts off.
	 *
	 * @return void
	 */
	public function testZeroWeightsSwitchTheirPartsOff(): void {
		$now = new DateTimeImmutable('2026-07-13');
		$profile = $this->profile(priority: 0, idle: 0);

		$urgentIdle = $this->service->scoreItem('2026-07-22', 'urgent', '2026-05-01', $now, $profile);
		$lowFresh = $this->service->scoreItem('2026-07-22', 'low', '2026-07-13', $now, $profile);

		self::assertSame($lowFresh['score'], $urgentIdle['score']);
	}//end testZeroWeightsSwitchTheirPartsOff()

	/**
	 * The tier outranks the heaviest weights.
	 *
	 * @return void
	 */
	public function testTheTierAlwaysOutranksTheWeights(): void {
		$now = new DateTimeImmutable('2026-07-13');
		$profile = $this->profile(critical: 3, warning: 7, priority: 1000, idle: 1000);

		// Warning tier (4 working days), urgent, idle for 90 days.
		$warning = $this->service->scoreItem('2026-07-17', 'urgent', '2026-04-14', $now, $profile);
		// Critical tier (3 working days), low, touched today.
		$critical = $this->service->scoreItem('2026-07-16', 'low', '2026-07-13', $now, $profile);

		self::assertSame('warning', $warning['deadlineTier']);
		self::assertSame('critical', $critical['deadlineTier']);
		self::assertGreaterThan($warning['score'], $critical['score']);
	}//end testTheTierAlwaysOutranksTheWeights()

	// ── computeQueue() ───────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testComputeQueueOnlyReturnsCallersOpenItems(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Jan open case',
			'assignee' => 'jan',
			'endDate' => '',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'deadline' => '2026-07-16',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('case', [
			'id' => 'case-2',
			'title' => 'Jan closed case',
			'assignee' => 'jan',
			'endDate' => '2026-06-01',
			'deadline' => '2026-06-05',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'priority' => 'normal',
		]);
		$this->objects->saveObject('case', [
			'id' => 'case-3',
			'title' => 'Marie open case',
			'assignee' => 'marie',
			'endDate' => '',
			'deadline' => '2026-07-14',
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertCount(1, $items);
		self::assertSame('case-1', $items[0]['id']);
		self::assertSame('case', $items[0]['itemType']);
	}//end testComputeQueueOnlyReturnsCallersOpenItems()

	/**
	 * @return void
	 */
	public function testComputeQueuePrefersActiveTermijnDeadlineOverCaseField(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Case with termijn',
			'assignee' => 'jan',
			'endDate' => '',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'deadline' => '2026-08-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'case-1',
			'status' => 'lopend',
			'endDateCurrent' => '2026-07-14',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('2026-07-14', $items[0]['deadline']);
	}//end testComputeQueuePrefersActiveTermijnDeadlineOverCaseField()

	/**
	 * A paused term's moved end still decides urgency (REQ-OTE-06).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/my-work/spec.md#requirement-the-urgency-score-reads-every-open-term-req-ote-06
	 */
	public function testComputeQueueReadsAPausedTerm(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Paused case',
			'assignee' => 'jan',
			'endDate' => '',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'deadline' => '2026-08-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'case-1',
			'status' => 'paused',
			'endDateCurrent' => '2026-07-20',
		]);
		$this->objects->saveObject('deadlineInstance', [
			'id' => 'ti-2',
			'case' => 'case-1',
			'status' => 'completed',
			'endDateCurrent' => '2026-07-14',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('2026-07-20', $items[0]['deadline'], 'A completed term is not open; the paused one decides.');
	}//end testComputeQueueReadsAPausedTerm()

	/**
	 * @return void
	 */
	public function testComputeQueueExcludesTerminalTasksAndSortsByScore(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'title' => 'Distant case',
			'assignee' => 'jan',
			'endDate' => '',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'deadline' => '2026-08-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('caseTask', [
			'id' => 'task-1',
			'title' => 'Overdue task',
			'assignee' => 'jan',
			'status' => 'active',
			'dueDate' => '2026-07-01',
			'priority' => 'normal',
		]);
		$this->objects->saveObject('caseTask', [
			'id' => 'task-2',
			'title' => 'Completed task',
			'assignee' => 'jan',
			'status' => 'completed',
			'dueDate' => '2026-07-01',
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertCount(2, $items);
		// Overdue task must sort first (highest score).
		self::assertSame('task-1', $items[0]['id']);
		self::assertSame('overdue', $items[0]['deadlineTier']);
		self::assertSame('case-1', $items[1]['id']);
	}//end testComputeQueueExcludesTerminalTasksAndSortsByScore()

	/**
	 * @return void
	 */
	public function testComputeQueueReturnsEmptyForUnknownUser(): void {
		$this->objects->saveObject('case', [
			'id' => 'case-1',
			'assignee' => 'jan',
			'endDate' => '',
			'statusHiddenInLists' => false,
			'isDraft' => false,
			'priority' => 'normal',
		]);

		$items = $this->service->computeQueue('nobody', new DateTimeImmutable('2026-07-13'));

		self::assertSame([], $items);
	}//end testComputeQueueReturnsEmptyForUnknownUser()

	/**
	 * The case search names its limit and the list's filters.
	 *
	 * @return void
	 */
	public function testCaseSearchNamesItsLimitAndTheListsFilters(): void {
		$this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame(
			['assignee' => 'jan', 'statusHiddenInLists' => false, 'isDraft' => false, '_limit' => 1000],
			$this->objects->searches['case'][0]
		);
	}//end testCaseSearchNamesItsLimitAndTheListsFilters()

	/**
	 * A case item carries its row, its deadline tier and its idle days.
	 *
	 * @return void
	 */
	public function testCaseItemCarriesItsRowAndDeadlineTier(): void {
		$this->seedOpenCase(['id' => 'case-1', 'title' => 'One', 'startDate' => '2026-07-01']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('case-1', $items[0]['case']['id']);
		self::assertSame('normal', $items[0]['deadlineTier']);
		self::assertSame(12, $items[0]['idleDays']);
		self::assertArrayNotHasKey('tier', $items[0]);
	}//end testCaseItemCarriesItsRowAndDeadlineTier()

	/**
	 * A recent save resets the idle days.
	 *
	 * @return void
	 */
	public function testARecentEditResetsTheIdleDays(): void {
		$this->seedOpenCase([
			'id' => 'case-1',
			'startDate' => '2026-06-03',
			'@self' => ['updated' => '2026-07-11T14:00:00+00:00'],
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame(2, $items[0]['idleDays']);
	}//end testARecentEditResetsTheIdleDays()

	/**
	 * A journal entry newer than the last save counts.
	 *
	 * @return void
	 */
	public function testAJournalEntryNewerThanTheSaveCounts(): void {
		$this->seedOpenCase([
			'id' => 'case-1',
			'@self' => ['updated' => '2026-07-04T09:00:00+00:00'],
			'activity' => json_encode([['type' => 'resumed', 'at' => '2026-07-09T10:00:00+00:00']]),
		]);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame(4, $items[0]['idleDays']);
	}//end testAJournalEntryNewerThanTheSaveCounts()

	/**
	 * With no activity the idle days count from the start.
	 *
	 * @return void
	 */
	public function testNoActivityCountsFromTheStart(): void {
		$this->seedOpenCase(['id' => 'case-1', 'startDate' => '2026-07-01']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame(12, $items[0]['idleDays']);
	}//end testNoActivityCountsFromTheStart()

	/**
	 * The admin settings reach the queue.
	 *
	 * @return void
	 */
	public function testTheAdminThresholdsReachTheQueue(): void {
		$this->config['queue_critical_days'] = '10';
		// Monday 2026-07-13; 8 working days on is Thursday 07-23.
		$this->seedOpenCase(['id' => 'case-1', 'deadline' => '2026-07-23']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('critical', $items[0]['deadlineTier']);
	}//end testTheAdminThresholdsReachTheQueue()

	/**
	 * The case type's own threshold wins over the admin default.
	 *
	 * @return void
	 */
	public function testTheCaseTypesOwnThresholdWins(): void {
		$this->objects->saveObject('caseType', ['id' => 'woo', 'queueCriticalDays' => 10]);
		$this->seedOpenCase(['id' => 'case-1', 'caseType' => 'woo', 'deadline' => '2026-07-23']);
		$this->seedOpenCase(['id' => 'case-2', 'caseType' => 'other', 'deadline' => '2026-07-23']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));
		$byId = array_column($items, 'deadlineTier', 'id');

		self::assertSame('critical', $byId['case-1']);
		self::assertSame('normal', $byId['case-2']);
	}//end testTheCaseTypesOwnThresholdWins()

	/**
	 * An empty override falls back to the admin default on its own.
	 *
	 * @return void
	 */
	public function testAnEmptyOverrideFallsBack(): void {
		$this->objects->saveObject('caseType', ['id' => 'melding', 'queueWarningDays' => 12, 'queueCriticalDays' => null]);
		// 3 working days on is Thursday 07-16; 12 working days on is Wednesday 07-29.
		$this->seedOpenCase(['id' => 'case-1', 'caseType' => 'melding', 'deadline' => '2026-07-16']);
		$this->seedOpenCase(['id' => 'case-2', 'caseType' => 'melding', 'deadline' => '2026-07-29']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));
		$byId = array_column($items, 'deadlineTier', 'id');

		self::assertSame('critical', $byId['case-1']);
		self::assertSame('warning', $byId['case-2']);
	}//end testAnEmptyOverrideFallsBack()

	/**
	 * The warning-days fields of a term do not steer the deadline tier.
	 *
	 * @return void
	 */
	public function testTheTermWarningFieldsDoNotSteerTheTier(): void {
		$this->objects->saveObject('caseType', ['id' => 'bezwaar', 'statutoryWarningDays' => 20, 'plannedWarningDays' => 20]);
		$this->seedOpenCase(['id' => 'case-1', 'caseType' => 'bezwaar', 'deadline' => '2026-07-29']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('normal', $items[0]['deadlineTier']);
	}//end testTheTermWarningFieldsDoNotSteerTheTier()

	// ── computeWorkload() ────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public function testComputeWorkloadCountsOpenCasesPerHandler(): void {
		$this->objects->saveObject('case', ['id' => 'c1', 'assignee' => 'jan', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c2', 'assignee' => 'jan', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c3', 'assignee' => 'marie', 'endDate' => '']);
		$this->objects->saveObject('case', ['id' => 'c4', 'assignee' => 'jan', 'endDate' => '2026-06-01']);

		$workload = $this->service->computeWorkload();

		self::assertSame(
			[
				['handler' => 'jan', 'openCaseCount' => 2],
				['handler' => 'marie', 'openCaseCount' => 1],
			],
			$workload
		);
	}//end testComputeWorkloadCountsOpenCasesPerHandler()
}//end class

/**
 * Minimal in-memory ObjectService fake for WorkQueueServiceTest, mirroring
 * the real OpenRegister ObjectService's `searchObjectsBySlug()` contract.
 * Ignores underscore-prefixed keys (`_limit`, `_offset`) during equality
 * filtering, matching the real API's pagination-vs-field-filter split
 * (unlike the shared FakeTermijnStore fixture, which treats every filter
 * key as a literal field match and is unsuitable for callers that pass
 * `_limit`).
 */
class FakeWorkQueueStore {

	/**
	 * Object store, keyed by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Every search's filters, keyed by schema slug, in order.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $searches = [];

	/**
	 * Single-object read, mirroring ObjectService::find(); null when absent.
	 *
	 * @param string          $id       Object id.
	 * @param int|string|null $register Register (unused).
	 * @param int|string|null $schema   Schema slug.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	public function find(string $id, int|string|null $register = null, int|string|null $schema = null): ?array {
		return ($this->store[(string)$schema][$id] ?? null);
	}//end find()

	/**
	 * Persist (insert or update) an object by id.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $object Object.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(string $schema, array $object): array {
		$this->store[$schema][(string)$object['id']] = $object;
		return $object;
	}//end saveObject()

	/**
	 * Slug-aware search bridge mirroring OpenRegister
	 * ObjectService::searchObjectsBySlug().
	 *
	 * @param string $registerSlug Register slug (unused by this fake).
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Object-field filters (underscore keys ignored).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		$this->searches[$schemaSlug][] = $filters;
		$rows = array_values($this->store[$schemaSlug] ?? []);

		$fieldFilters = array_filter(
			$filters,
			static fn ($value, $key): bool => (is_string($key) === true && str_starts_with($key, '_') === false),
			ARRAY_FILTER_USE_BOTH
		);

		if (count($fieldFilters) === 0) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($fieldFilters): bool {
					foreach ($fieldFilters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end searchObjectsBySlug()
}//end class
