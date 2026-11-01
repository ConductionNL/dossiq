<?php

/**
 * MilestoneService stalled-case detection Unit Tests
 *
 * Tests for MilestoneService::findStalledCases(): which active cases get
 * flagged as bottlenecks based on their earliest unreached milestone's
 * working-day deadline.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\MilestoneService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MilestoneService::findStalledCases().
 *
 * @covers \OCA\Dossiq\Service\MilestoneService
 * @covers \OCA\Dossiq\Service\Milestone\StalledCaseDetector
 * @covers \OCA\Dossiq\Service\Milestone\MilestoneRepository
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Milestone\MilestoneSchedule
 * @uses \OCA\Dossiq\Service\Term\DayCount
 * @covers \OCA\Dossiq\Service\Milestone\StallCandidate
 * @covers \OCA\Dossiq\Service\Milestone\StallWindow
 */
class MilestoneServiceStalledTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service under test with REAL collaborators.
	 *
	 * The repository and the stalled-case detector are deliberately real, not
	 * mocks: these tests assert the production stall arithmetic, so a mocked
	 * detector would only ever replay its own canned answer.
	 *
	 * @return MilestoneService The service wired over the mocked settings service.
	 */
	private function buildService(): MilestoneService {
		$repository = new MilestoneRepository(settingsService: $this->settingsService);

		return new MilestoneService(
			settingsService: $this->settingsService,
			repository: $repository,
			stalledDetector: new StalledCaseDetector(
				settingsService: $this->settingsService,
				repository: $repository,
				workingDays: new WorkingDayCalculator(),
				schedule: new MilestoneSchedule(workingDays: new WorkingDayCalculator()),
			),
			logger: $this->logger,
		);

	}//end buildService()

	/**
	 * Build a fake object service that returns scripted result sets keyed by
	 * the schema id present in the query's `@self` block.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Result
	 *                                                                  sets keyed by schema id ('1' = case, '2' = definition,
	 *                                                                  '3' = record).
	 *
	 * @return object The fake object service.
	 */
	private function fakeObjectService(array $bySchema): object {
		return new class($bySchema) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Scripted results.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			/**
			 * @param array<string, mixed> $query The search query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				// Record lookups filter by case id; honour it so each case
				// sees only its own records.
				$rows = ($this->bySchema[$schema] ?? []);
				if ($schema === '3' && isset($query['case']) === true) {
					$rows = array_values(
						array_filter(
							$rows,
							static fn (array $r): bool => (($r['case'] ?? '') === $query['case'])
						)
					);
				}

				if ($schema === '2' && isset($query['caseType']) === true) {
					$rows = array_values(
						array_filter(
							$rows,
							static fn (array $r): bool => (($r['caseType'] ?? '') === $query['caseType'])
						)
					);
				}

				return $rows;
			}
		};

	}//end fakeObjectService()

	/**
	 * Wire the settings-service mock to resolve config keys + object service.
	 *
	 * @param object $objectService The fake object service.
	 *
	 * @return void
	 */
	private function wireSettings(object $objectService): void {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', '10'],
				['case_schema', '', '1'],
				['milestone_definition_schema', '', '2'],
				['milestone_record_schema', '', '3'],
			]
		);

	}//end wireSettings()

	/**
	 * A case whose earliest unreached milestone's deadline is long past MUST be
	 * flagged, reporting that milestone's identifier and label.
	 *
	 * @return void
	 */
	public function testOverdueCaseIsFlagged(): void {
		$longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

		$objectService = $this->fakeObjectService(
			[
				'1' => [
					[
						'id' => 'zaak-1',
						'title' => 'Omgevingsvergunning',
						'caseType' => 'ct-1',
						'status' => 'in_handling',
						'assignee' => 'behandelaar-a',
						'startDate' => $longAgo,
					],
				],
				'2' => [
					[
						'id' => 'def-1',
						'caseType' => 'ct-1',
						'identifier' => 'documenten_compleet',
						'label' => 'Documenten compleet',
						'order' => 1,
						'expectedDurationWorkingDays' => 5,
					],
				],
				'3' => [],
			]
		);

		$this->wireSettings($objectService);
		$service = $this->buildService();

		$stalled = $service->findStalledCases(thresholdDays: 0);

		$this->assertCount(1, $stalled);
		$this->assertSame('zaak-1', $stalled[0]['caseId']);
		$this->assertSame('documenten_compleet', $stalled[0]['milestoneIdentifier']);
		$this->assertSame('behandelaar-a', $stalled[0]['assignee']);
		$this->assertGreaterThan(0, $stalled[0]['daysOverdue']);
		// The exact count, not only its sign: the days from the reported
		// deadline to today.
		$this->assertSame(
			expected: (int)(new \DateTimeImmutable($stalled[0]['deadline']))->diff(new \DateTimeImmutable('today'))->days,
			actual: $stalled[0]['daysOverdue']
		);

	}//end testOverdueCaseIsFlagged()

	/**
	 * A case whose milestone deadline is still in the future MUST NOT be
	 * flagged.
	 *
	 * @return void
	 */
	public function testOnTrackCaseIsNotFlagged(): void {
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');

		$objectService = $this->fakeObjectService(
			[
				'1' => [
					[
						'id' => 'zaak-2',
						'caseType' => 'ct-1',
						'status' => 'in_handling',
						'assignee' => 'behandelaar-a',
						'startDate' => $today,
					],
				],
				'2' => [
					[
						'id' => 'def-1',
						'caseType' => 'ct-1',
						'identifier' => 'documenten_compleet',
						'label' => 'Documenten compleet',
						'order' => 1,
						'expectedDurationWorkingDays' => 10,
					],
				],
				'3' => [],
			]
		);

		$this->wireSettings($objectService);
		$service = $this->buildService();

		$this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

	}//end testOnTrackCaseIsNotFlagged()

	/**
	 * A closed case MUST be skipped even when its deadline is long past.
	 *
	 * @return void
	 */
	public function testClosedCaseIsSkipped(): void {
		$longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

		$objectService = $this->fakeObjectService(
			[
				'1' => [
					[
						'id' => 'zaak-3',
						'caseType' => 'ct-1',
						'status' => 'handled',
						'assignee' => 'behandelaar-a',
						'startDate' => $longAgo,
					],
				],
				'2' => [
					[
						'id' => 'def-1',
						'caseType' => 'ct-1',
						'identifier' => 'documenten_compleet',
						'label' => 'Documenten compleet',
						'order' => 1,
						'expectedDurationWorkingDays' => 5,
					],
				],
				'3' => [],
			]
		);

		$this->wireSettings($objectService);
		$service = $this->buildService();

		$this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

	}//end testClosedCaseIsSkipped()

	/**
	 * When the earliest unreached milestone is already reached, the NEXT
	 * unreached milestone determines stall — a case with all milestones
	 * reached is complete and MUST NOT be flagged.
	 *
	 * @return void
	 */
	public function testAllMilestonesReachedIsNotFlagged(): void {
		$longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

		$objectService = $this->fakeObjectService(
			[
				'1' => [
					[
						'id' => 'zaak-4',
						'caseType' => 'ct-1',
						'status' => 'in_handling',
						'assignee' => 'behandelaar-a',
						'startDate' => $longAgo,
					],
				],
				'2' => [
					[
						'id' => 'def-1',
						'caseType' => 'ct-1',
						'identifier' => 'documenten_compleet',
						'label' => 'Documenten compleet',
						'order' => 1,
						'expectedDurationWorkingDays' => 5,
					],
				],
				'3' => [
					[
						'id' => 'rec-1',
						'case' => 'zaak-4',
						'milestoneDefinition' => 'def-1',
						'reached' => true,
					],
				],
			]
		);

		$this->wireSettings($objectService);
		$service = $this->buildService();

		$this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

	}//end testAllMilestonesReachedIsNotFlagged()


	/**
	 * waitingOn() names the milestone a case waits on even when it is not late,
	 * with the deadline the stalled list would use, so the stall timer and the
	 * list agree.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function testWaitingOnNamesTheMilestoneBeforeItIsLate(): void {
		$today = (new \DateTimeImmutable('today'))->format('Y-m-d');
		$case  = [
			'id'        => 'zaak-2',
			'caseType'  => 'ct-1',
			'status'    => 'in_handling',
			'assignee'  => 'behandelaar-a',
			'startDate' => $today,
		];
		$this->wireSettings(
			objectService: $this->fakeObjectService(
				[
					'1' => [$case],
					'2' => [
						[
							'id'                          => 'def-1',
							'caseType'                    => 'ct-1',
							'identifier'                  => 'documenten_compleet',
							'label'                       => 'Documenten compleet',
							'order'                       => 1,
							'expectedDurationWorkingDays' => 5,
						],
					],
					'3' => [],
				]
			)
		);
		$detector = new StalledCaseDetector(
			settingsService: $this->settingsService,
			repository: new MilestoneRepository(settingsService: $this->settingsService),
			workingDays: new WorkingDayCalculator(),
			schedule: new MilestoneSchedule(workingDays: new WorkingDayCalculator()),
		);

		$row = $detector->waitingOn(case: $case);

		$this->assertSame(expected: 'documenten_compleet', actual: $row['milestoneIdentifier']);
		$this->assertLessThan(0, $row['daysOverdue']);
		// Before the deadline the count is the days still to go, negated.
		$this->assertSame(
			expected: (-1 * (int)(new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($row['deadline']))->days),
			actual: $row['daysOverdue']
		);
		$this->assertGreaterThan($today, $row['deadline']);
		$this->assertSame(expected: [], actual: $detector->findStalledCases(thresholdDays: 0));
	}//end testWaitingOnNamesTheMilestoneBeforeItIsLate()

	/**
	 * One mixed world, pinned whole: closed, untyped, undated and unscheduled
	 * cases, milestones out of order, reached on a date or with a broken one,
	 * a dependent milestone, a case still on track, and three thresholds. The
	 * days overdue are checked against the reported deadline and then left out,
	 * so the file does not move with the calendar.
	 *
	 * @return void
	 */
	public function testGoldenStallWorld(): void {
		$case = static fn (string $id, string $type, string $start, array $extra = []): array => array_merge(
			['id' => $id, 'title' => 'T ' . $id, 'caseType' => $type, 'status' => 'in_handling', 'assignee' => 'u-' . $id, 'startDate' => $start],
			$extra
		);
		$objectService = $this->fakeObjectService(
			[
				'1' => [
					$case('a', 'ct-1', '2020-03-02'),
					$case('b', 'ct-1', '2020-03-02'),
					$case('c', 'ct-1', '2099-01-05'),
					$case('d', 'ct-2', '2020-03-02'),
					$case('e', 'ct-1', '2020-03-02', ['status' => 'Afgesloten']),
					$case('e2', 'ct-1', '2020-03-02', ['status' => 'Case WITHDRAWN']),
					$case('f', '', '2020-03-02'),
					$case('g', 'ct-1', ''),
					$case('h', 'ct-1', 'garbage'),
					array_diff_key($case('i', 'ct-1', '2020-03-02', ['uuid' => 'i-uuid']), ['id' => true]),
					$case('', 'ct-1', '2020-03-02'),
					$case('j', 'ct-none', '2020-03-02'),
					array_diff_key($case('k', 'ct-1', '', ['created' => '2020-05-04T10:00:00Z', 'title' => null]), ['startDate' => true]),
					$case('l', 'ct-3', '2020-03-02'),
					$case('m', 'ct-1', '2020-03-02'),
				],
				'2' => [
					['id' => 'd2', 'caseType' => 'ct-1', 'identifier' => 'second', 'name' => 'Second', 'order' => 2, 'expectedDurationWorkingDays' => 10, 'dependsOn' => ['first']],
					['id' => 'd1', 'caseType' => 'ct-1', 'identifier' => 'first', 'label' => 'First', 'order' => 1, 'expectedDurationWorkingDays' => 5],
					['uuid' => 'd3', 'caseType' => 'ct-1', 'identifier' => 'third', 'order' => 3],
					['id' => 'e1', 'caseType' => 'ct-2', 'identifier' => 'only', 'label' => 'Only', 'order' => 1, 'expectedDurationWorkingDays' => 3],
					['id' => 'f1', 'caseType' => 'ct-3', 'label' => 'Nameless', 'expectedDurationWorkingDays' => 4],
					['id' => 'z1', 'caseType' => '', 'identifier' => 'untyped', 'order' => 1, 'expectedDurationWorkingDays' => 1],
				],
				'3' => [
					['case' => 'b', 'milestoneDefinition' => 'd1', 'milestoneIdentifier' => 'first', 'reachedAt' => '2020-04-01T09:00:00Z'],
					['case' => 'b', 'milestoneDefinition' => 'd2', 'milestoneIdentifier' => 'second', 'reached' => false, 'reachedAt' => '2020-04-02'],
					['case' => 'd', 'milestoneDefinition' => 'e1', 'milestoneIdentifier' => 'only', 'reachedAt' => 'not-a-date'],
					['case' => 'k', 'milestoneDefinition' => 'd1', 'milestoneIdentifier' => 'first'],
					['case' => 'm', 'milestoneDefinition' => 'd1', 'milestoneIdentifier' => 'first', 'reachedAt' => '2020-03-05'],
					['case' => 'm', 'milestoneDefinition' => 'd2', 'milestoneIdentifier' => 'second', 'reachedAt' => '2020-03-20'],
					['case' => 'm', 'milestoneDefinition' => 'd3', 'milestoneIdentifier' => 'third'],
				],
			]
		);
		$this->wireSettings($objectService);
		$service = $this->buildService();
		$today = new \DateTimeImmutable('today');
		$days = new DayCount();

		$world = [];
		foreach ([0, 30, 100000] as $threshold) {
			$rows = $service->findStalledCases(thresholdDays: $threshold);
			foreach ($rows as $index => $row) {
				$this->assertSame($days->signed(from: new \DateTimeImmutable($row['deadline']), to: $today), $row['daysOverdue']);
				unset($rows[$index]['daysOverdue']);
			}

			$world['threshold ' . $threshold] = $rows;
		}

		// A case exactly as many days past its deadline as the grace allows is not stalled; one day less grace and it is.
		$atDeadline = $days->signed(from: new \DateTimeImmutable('2020-03-09'), to: $today);
		$world['grace equal to the days overdue'] = array_column($service->findStalledCases(thresholdDays: $atDeadline), 'caseId');
		$world['grace one day less'] = array_column($service->findStalledCases(thresholdDays: ($atDeadline - 1)), 'caseId');

		$repository = new MilestoneRepository(settingsService: $this->settingsService);
		$detector = new StalledCaseDetector(
			settingsService: $this->settingsService,
			repository: $repository,
			workingDays: new WorkingDayCalculator(),
			schedule: new MilestoneSchedule(workingDays: new WorkingDayCalculator()),
		);
		foreach (['c' => $case('c', 'ct-1', '2099-01-05'), 'a' => $case('a', 'ct-1', '2020-03-02'), 'closed' => $case('x', 'ct-1', '2020-03-02', ['status' => 'closed']), 'd' => $case('d', 'ct-2', '2020-03-02')] as $label => $one) {
			$row = $detector->waitingOn($one);
			if ($row !== null) {
				$this->assertSame($days->signed(from: new \DateTimeImmutable($row['deadline']), to: $today), $row['daysOverdue']);
				unset($row['daysOverdue']);
			}

			$world['waitingOn ' . $label] = $row;
		}

		$this->assertMatchesGolden('stalled-case-world', $world);
	}//end testGoldenStallWorld()
}//end class
