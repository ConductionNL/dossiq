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
use OCA\Dossiq\Service\Queue\QueueCaseFacts;
use OCA\Dossiq\Service\Queue\QueueUrgencySettings;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\Queue\UrgencyScorer;
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
 * @covers \OCA\Dossiq\Service\Queue\UrgencyScorer
 * @covers \OCA\Dossiq\Service\Queue\QueueCaseFacts
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
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
			urgencySettings: new QueueUrgencySettings(settings: $settings),
			scorer: new UrgencyScorer(dates: $this->caseDates(), logger: $this->createMock(originalClassName: LoggerInterface::class)),
			caseFacts: new QueueCaseFacts(settingsService: $settings, journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)), dates: $this->caseDates(), logger: $this->createMock(originalClassName: LoggerInterface::class)),
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
			urgencySettings: new QueueUrgencySettings(settings: $settings),
			scorer: new UrgencyScorer(dates: $this->caseDates(), logger: $this->createMock(LoggerInterface::class), calendar: $calendar),
			caseFacts: new QueueCaseFacts(settingsService: $settings, journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)), dates: $this->caseDates(), logger: $this->createMock(LoggerInterface::class)),
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
	 * A covered case joins the queue, scored like an own case and marked with whom it is covered for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function testACoveredCaseJoinsTheQueueMarked(): void {
		$this->seedOpenCase(['id' => 'own-1', 'title' => 'Own']);
		$coveredFor = ['absentee' => 'anouk', 'name' => 'Anouk Bakker', 'until' => '2026-10-10'];

		$items = $this->service->computeQueue(
			'jan',
			new DateTimeImmutable('2026-07-13'),
			[['id' => 'cov-1', 'title' => 'Covered', 'assignee' => 'anouk', 'endDate' => '', 'deadline' => '2026-07-10', 'coveredFor' => $coveredFor]]
		);

		$byId = array_column($items, null, 'id');
		self::assertSame(['cov-1', 'own-1'], array_column($items, 'id'), 'the overdue covered case ranks first');
		self::assertSame($coveredFor, $byId['cov-1']['coveredFor']);
		self::assertSame($coveredFor, $byId['cov-1']['case']['coveredFor']);
		self::assertSame('overdue', $byId['cov-1']['deadlineTier']);
		self::assertNull($byId['own-1']['coveredFor']);
	}//end testACoveredCaseJoinsTheQueueMarked()

	/**
	 * A covered case that is already the caller's own is listed once, as their own.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function testACoveredCaseThatIsAlsoOwnIsListedOnce(): void {
		$this->seedOpenCase(['id' => 'own-1', 'title' => 'Own']);
		$coveredFor = ['absentee' => 'anouk', 'name' => 'Anouk Bakker', 'until' => ''];

		$items = $this->service->computeQueue(
			'jan',
			new DateTimeImmutable('2026-07-13'),
			[['id' => 'own-1', 'coveredFor' => $coveredFor]]
		);

		self::assertSame(['own-1'], array_column($items, 'id'));
		self::assertNull($items[0]['coveredFor']);
	}//end testACoveredCaseThatIsAlsoOwnIsListedOnce()

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

	// ── Characterisation before the split (method-decomposition) ────────

	/**
	 * A service over a store that can fail, with a logger that keeps its warnings.
	 *
	 * @param FailingWorkQueueStore $store    The store.
	 * @param array<int, string>    $warnings Receives every warning logged.
	 * @param WorkingDayRoll|null   $calendar The administered calendar, or none.
	 *
	 * @return WorkQueueService The service.
	 */
	private function serviceOver(FailingWorkQueueStore $store, array &$warnings, ?WorkingDayRoll $calendar = null): WorkQueueService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$engineTasks = $this->createMock(EngineTaskInbox::class);
		$engineTasks->method('openForAssignee')->willReturn([]);

		return new WorkQueueService(
			settingsService: $settings,
			engineTasks: $engineTasks,
			logger: $logger,
			urgencySettings: new QueueUrgencySettings(settings: $settings),
			scorer: new UrgencyScorer(dates: $this->caseDates(), logger: $logger, calendar: $calendar),
			caseFacts: new QueueCaseFacts(settingsService: $settings, journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)), dates: $this->caseDates(), logger: $logger),
		);
	}//end serviceOver()

	/**
	 * The whole answer of one score, to the cent.
	 *
	 * @return void
	 */
	public function testAScoreCarriesItsTierItsDaysAndItsParts(): void {
		// Monday 2026-07-13; Friday 07-17 is 4 working days on; idle since Wednesday 07-01.
		$result = $this->service->scoreItem(' 2026-07-17', ' HIGH ', '2026-07-01', new DateTimeImmutable('2026-07-13 15:30'));

		self::assertSame(
			[
				'deadlineTier' => 'warning',
				'daysUntilDeadline' => 4,
				'idleDays' => 12,
				'score' => 522.0,
				'scoreBreakdown' => ['deadline' => 496.0, 'priority' => 20.0, 'idle' => 6.0],
			],
			$result
		);
	}//end testAScoreCarriesItsTierItsDaysAndItsParts()

	/**
	 * Without a calendar the days are walked Monday to Friday, in both directions, and idle days
	 * stop counting at sixty.
	 *
	 * @return void
	 */
	public function testTheWalkSkipsWeekendsBothWaysAndIdleDaysAreCapped(): void {
		$now = new DateTimeImmutable('2026-07-13');

		self::assertSame(10, $this->service->scoreItem('2026-07-27', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(1, $this->service->scoreItem('2026-07-14', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(-1, $this->service->scoreItem('2026-07-10', 'low', null, $now)['daysUntilDeadline']);
		// A deadline on the weekend just past has no working day between it and Monday: zero, not overdue.
		self::assertSame(0, $this->service->scoreItem('2026-07-11', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame('critical', $this->service->scoreItem('2026-07-11', 'low', null, $now)['deadlineTier']);
		self::assertSame(-6, $this->service->scoreItem('2026-07-03', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(1006.0, $this->service->scoreItem('2026-07-03', 'low', null, $now)['score']);

		$idle = $this->service->scoreItem(null, 'urgent', '2025-01-01', $now);
		self::assertSame(558, $idle['idleDays']);
		self::assertSame(['deadline' => 0.0, 'priority' => 30.0, 'idle' => 30.0], $idle['scoreBreakdown']);
		self::assertNull($this->service->scoreItem('geen datum', 'low', 'ook niet', $now)['daysUntilDeadline']);
		self::assertNull($this->service->scoreItem('geen datum', 'low', 'ook niet', $now)['idleDays']);
	}//end testTheWalkSkipsWeekendsBothWaysAndIdleDaysAreCapped()

	/**
	 * A calendar that does not answer falls back to the walk, signs a past count, and the
	 * fallback is said once however many items are scored.
	 *
	 * @return void
	 */
	public function testASilentCalendarFallsBackAndSaysSoOnce(): void {
		$warnings = [];
		$silent   = $this->createMock(WorkingDayRoll::class);
		$silent->method('daysBetween')->willReturn(null);
		$service = $this->serviceOver(store: new FailingWorkQueueStore(), warnings: $warnings, calendar: $silent);
		$now     = new DateTimeImmutable('2026-07-13');

		self::assertSame(4, $service->scoreItem('2026-07-17', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(-1, $service->scoreItem('2026-07-10', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(0, $service->scoreItem('2026-07-13', 'low', null, $now)['daysUntilDeadline']);
		self::assertSame(
			[
				'Dossiq work queue: the organisation calendar did not answer, so days until a deadline '
				.'were counted Monday to Friday, without holidays or closure days',
			],
			$warnings
		);

		$answering = $this->createMock(WorkingDayRoll::class);
		$answering->expects(self::once())->method('daysBetween')
			->with(
				self::callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-07-09 00:00'),
				self::callback(static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d H:i') === '2026-07-13 00:00'),
				WorkingDayRoll::MODE_WORKING_DAYS
			)
			->willReturn(2);
		$warnings = [];
		$past     = $this->serviceOver(store: new FailingWorkQueueStore(), warnings: $warnings, calendar: $answering)
			->scoreItem('2026-07-09', 'low', null, new DateTimeImmutable('2026-07-13 09:00'));

		self::assertSame(-2, $past['daysUntilDeadline']);
		self::assertSame('overdue', $past['deadlineTier']);
		self::assertSame([], $warnings);
	}//end testASilentCalendarFallsBackAndSaysSoOnce()

	/**
	 * Of several open terms the nearest end counts; a closed term, and one without an end, do not.
	 *
	 * @return void
	 */
	public function testTheNearestOpenTermDecidesTheDeadline(): void {
		$this->seedOpenCase(['id' => 'case-1', 'deadline' => '2026-12-31']);
		foreach ([
			['id' => 't1', 'status' => 'lopend', 'endDateCurrent' => '2026-08-20'],
			['id' => 't2', 'status' => 'verlengd', 'endDateCurrent' => '2026-08-05'],
			['id' => 't3', 'status' => 'exceeded', 'endDateCurrent' => '2026-08-10'],
			['id' => 't4', 'status' => 'afgerond', 'endDateCurrent' => '2026-07-01'],
			['id' => 't5', 'status' => 'lopend', 'endDateCurrent' => ''],
			['id' => 't6', 'status' => 'lopend', 'endDateCurrent' => '2026-07-02', 'case' => 'case-2'],
		] as $term) {
			$this->objects->saveObject('deadlineInstance', ($term + ['case' => 'case-1']));
		}

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame('2026-08-05', $items[0]['deadline']);
		self::assertSame([['case' => 'case-1']], $this->objects->searches['deadlineInstance']);
	}//end testTheNearestOpenTermDecidesTheDeadline()

	/**
	 * Without a term schema, or when the terms cannot be read, the case's own deadline counts; a
	 * case without either has none.
	 *
	 * @return void
	 */
	public function testTheCasesOwnDeadlineIsTheFallback(): void {
		$warnings = [];
		$store    = new FailingWorkQueueStore();
		$store->failOn = ['deadlineInstance'];
		$store->saveObject('case', ['id' => 'case-1', 'assignee' => 'jan', 'deadline' => '2026-07-20', 'statusHiddenInLists' => false, 'isDraft' => false]);
		$store->saveObject('case', ['id' => 'case-2', 'assignee' => 'jan', 'statusHiddenInLists' => false, 'isDraft' => false]);
		$store->saveObject('deadlineInstance', ['id' => 't1', 'case' => 'case-1', 'status' => 'lopend', 'endDateCurrent' => '2026-07-14']);

		$failed = array_column($this->serviceOver(store: $store, warnings: $warnings)->computeQueue('jan', new DateTimeImmutable('2026-07-13')), 'deadline', 'id');

		self::assertSame(['case-1' => '2026-07-20', 'case-2' => null], $failed);
		self::assertSame([], array_filter($warnings, static fn (string $w): bool => str_starts_with($w, 'WorkQueue')));

		$store->failOn = [];
		$this->config['termijn_instance_schema'] = '';
		$unconfigured = array_column($this->serviceOver(store: $store, warnings: $warnings)->computeQueue('jan', new DateTimeImmutable('2026-07-13')), 'deadline', 'id');

		self::assertSame(['case-1' => '2026-07-20', 'case-2' => null], $unconfigured);
		self::assertArrayNotHasKey('', $store->searches);
	}//end testTheCasesOwnDeadlineIsTheFallback()

	/**
	 * A case names its type by id or as an object; a type that cannot be read, or is not there,
	 * scores with the instance thresholds, and a failed read is said in the log. Each type is read once.
	 *
	 * @return void
	 */
	public function testTheCaseTypeIsReadOncePerTypeAndAFailureFallsBack(): void {
		$warnings = [];
		$store    = new FailingWorkQueueStore();
		$store->saveObject('caseType', ['id' => 'wide', 'queueCriticalDays' => 10]);
		$case = static fn (string $id, mixed $type): array => [
			'id' => $id, 'assignee' => 'jan', 'caseType' => $type, 'deadline' => '2026-07-23', 'statusHiddenInLists' => false, 'isDraft' => false,
		];
		$store->saveObject('case', $case('by-id', 'wide'));
		$store->saveObject('case', $case('by-id-again', 'wide'));
		$store->saveObject('case', $case('by-object-id', ['id' => 'wide']));
		$store->saveObject('case', $case('by-object-uuid', ['uuid' => 'wide']));
		$store->saveObject('case', $case('empty-object', []));
		$store->saveObject('case', $case('a-number', 7));
		$store->saveObject('case', $case('unknown', 'gone'));

		$tiers = array_column($this->serviceOver(store: $store, warnings: $warnings)->computeQueue('jan', new DateTimeImmutable('2026-07-13')), 'deadlineTier', 'id');

		self::assertSame(
			[
				'by-id' => 'critical',
				'by-id-again' => 'critical',
				'by-object-id' => 'critical',
				'by-object-uuid' => 'critical',
				'empty-object' => 'normal',
				'a-number' => 'normal',
				'unknown' => 'normal',
			],
			$tiers
		);
		self::assertSame(['wide', 'gone'], $store->finds['caseType']);
		self::assertSame([], array_filter($warnings, static fn (string $w): bool => str_starts_with($w, 'WorkQueue')));

		$warnings = [];
		$store->failOn = ['caseType'];
		$failed = array_column($this->serviceOver(store: $store, warnings: $warnings)->computeQueue('jan', new DateTimeImmutable('2026-07-13')), 'deadlineTier', 'id');

		self::assertSame('normal', $failed['by-id']);
		self::assertSame(
			['WorkQueue: case type read failed, scoring with the instance thresholds', 'WorkQueue: case type read failed, scoring with the instance thresholds'],
			array_values(array_filter($warnings, static fn (string $w): bool => str_starts_with($w, 'WorkQueue')))
		);
	}//end testTheCaseTypeIsReadOncePerTypeAndAFailureFallsBack()

	/**
	 * A failed case search gives an empty queue, an empty workload and zero counts, each said in the log.
	 *
	 * @return void
	 */
	public function testAFailedCaseSearchIsSaidAndAnswersEmpty(): void {
		$warnings = [];
		$store    = new FailingWorkQueueStore();
		$store->failOn = ['case'];
		$service = $this->serviceOver(store: $store, warnings: $warnings);

		self::assertSame([], $service->computeQueue('jan', new DateTimeImmutable('2026-07-13')));
		self::assertSame([], $service->computeWorkload());
		self::assertSame(['ours' => 0, 'applicant' => 0, 'thirdParty' => 0, 'total' => 0], $service->countByWaitingOn('jan'));
		self::assertSame(
			['WorkQueue: case search failed', 'WorkQueue: workload case search failed', 'WorkQueue: waiting-on count search failed'],
			$warnings
		);
	}//end testAFailedCaseSearchIsSaidAndAnswersEmpty()

	/**
	 * The waiting-on count reads the whole list or one handler's, with a named limit.
	 *
	 * @return void
	 */
	public function testTheWaitingOnCountIsScopedToAHandler(): void {
		$this->objects->saveObject('case', ['id' => 'c1', 'assignee' => 'jan', 'waitingOn' => 'applicant']);
		$this->objects->saveObject('case', ['id' => 'c2', 'assignee' => 'marie', 'waitingOn' => 'third_party']);
		$this->objects->saveObject('case', ['id' => 'c3', 'assignee' => 'jan']);
		$this->objects->saveObject('case', ['id' => 'c4', 'assignee' => 'jan', 'endDate' => '2026-01-01']);

		$all = $this->service->countByWaitingOn();
		$jan = $this->service->countByWaitingOn('jan');
		$this->service->countByWaitingOn('');

		self::assertSame(3, $all['total']);
		self::assertSame(['ours' => 1, 'applicant' => 1, 'thirdParty' => 0, 'total' => 2], $jan);
		self::assertSame(
			[['_limit' => 1000], ['_limit' => 1000, 'assignee' => 'jan'], ['_limit' => 1000]],
			$this->objects->searches['case']
		);
	}//end testTheWaitingOnCountIsScopedToAHandler()

	/**
	 * A task is scored on its due date with the instance profile and no idle part.
	 *
	 * @return void
	 */
	public function testATaskIsScoredOnItsDueDate(): void {
		$this->config['queue_critical_days'] = '10';
		$this->objects->saveObject('caseTask', ['id' => 't1', 'assignee' => 'jan', 'title' => 'Bel terug', 'status' => 'active', 'dueDate' => '2026-07-23', 'case' => 'case-9', 'priority' => 'high']);
		$this->objects->saveObject('caseTask', ['id' => 't2', 'assignee' => 'jan', 'status' => 'active', 'dueDate' => '']);

		$items = $this->service->computeQueue('jan', new DateTimeImmutable('2026-07-13'));

		self::assertSame(
			[
				'itemType' => 'task',
				'id' => 't1',
				'title' => 'Bel terug',
				'case' => 'case-9',
				'status' => 'active',
				'priority' => 'high',
				'deadline' => '2026-07-23',
				'deadlineTier' => 'critical',
				'daysUntilDeadline' => 8,
				'idleDays' => null,
				'score' => 762.0,
				'scoreBreakdown' => ['deadline' => 742.0, 'priority' => 20.0, 'idle' => 0.0],
			],
			$items[0]
		);
		self::assertNull($items[1]['deadline']);
		self::assertSame('normal', $items[1]['priority']);
		self::assertNull($items[1]['case']);
	}//end testATaskIsScoredOnItsDueDate()
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

/**
 * The same store, able to fail the reads of chosen schemas and remembering every find.
 */
class FailingWorkQueueStore extends FakeWorkQueueStore {

	/**
	 * Schema slugs whose search and find throw.
	 *
	 * @var array<int, string>
	 */
	public array $failOn = [];

	/**
	 * Every id read by find(), keyed by schema slug, in order.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $finds = [];

	/**
	 * @param string          $id       Object id.
	 * @param int|string|null $register Register (unused).
	 * @param int|string|null $schema   Schema slug.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	public function find(string $id, int|string|null $register = null, int|string|null $schema = null): ?array {
		$this->finds[(string) $schema][] = $id;
		if (in_array((string) $schema, $this->failOn, true) === true) {
			throw new \RuntimeException('down');
		}

		return parent::find($id, $register, $schema);
	}//end find()

	/**
	 * @param string               $registerSlug Register slug.
	 * @param string               $schemaSlug   Schema slug.
	 * @param array<string, mixed> $filters      Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		if (in_array($schemaSlug, $this->failOn, true) === true) {
			throw new \RuntimeException('down');
		}

		return parent::searchObjectsBySlug($registerSlug, $schemaSlug, $filters);
	}//end searchObjectsBySlug()
}//end class
