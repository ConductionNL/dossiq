<?php

/**
 * MilestoneService progress, marking and reversal Unit Tests
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
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\MilestoneService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Per-case milestone progress, marking a milestone and reversing one.
 *
 * @covers \OCA\Dossiq\Service\MilestoneService
 * @uses \OCA\Dossiq\Service\Support\ObjectSearch
 */
class MilestoneServiceProgressTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked milestone repository.
	 *
	 * @var MilestoneRepository&MockObject
	 */
	private MilestoneRepository $repository;

	/**
	 * The mocked stalled-case detector.
	 *
	 * @var StalledCaseDetector&MockObject
	 */
	private StalledCaseDetector $detector;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->repository = $this->createMock(originalClassName: MilestoneRepository::class);
		$this->detector = $this->createMock(originalClassName: StalledCaseDetector::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return MilestoneService The service.
	 */
	private function service(): MilestoneService {
		return new MilestoneService(
			settingsService: $this->settings,
			repository: $this->repository,
			stalledDetector: $this->detector,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * Configure the register and milestone record schema.
	 *
	 * @param string $register The register.
	 * @param string $schema   The milestone record schema.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', $register],
				['milestone_record_schema', '', $schema],
			]
		);
	}//end configure()

	/**
	 * getMilestones delegates to the repository's definitions.
	 *
	 * @return void
	 */
	public function testGetMilestonesReadsTheDefinitions(): void {
		$definitions = [['id' => 'd1', 'label' => 'Intake']];
		$this->repository->expects($this->once())->method('findDefinitions')->with('type-1')->willReturn($definitions);

		$this->assertSame(expected: $definitions, actual: $this->service()->getMilestones(caseTypeId: 'type-1'));
	}//end testGetMilestonesReadsTheDefinitions()

	/**
	 * A case type without milestones has no progress and reads no records.
	 *
	 * @return void
	 */
	public function testCaseProgressWithoutDefinitionsIsEmpty(): void {
		$this->repository->method('findDefinitions')->willReturn([]);
		$this->repository->expects($this->never())->method('findRecords');

		$this->assertSame(
			expected: ['milestones' => [], 'reached' => 0, 'total' => 0, 'percentage' => 0],
			actual: $this->service()->getCaseProgress(caseId: 'case-1', caseTypeId: 'type-1')
		);
	}//end testCaseProgressWithoutDefinitionsIsEmpty()

	/**
	 * Progress lists every definition in order with whether, when and by whom it was reached.
	 *
	 * @return void
	 */
	public function testCaseProgressJoinsDefinitionsAndRecords(): void {
		$this->repository->method('findDefinitions')->with('type-1')->willReturn(
			[
				['id' => 'd1', 'identifier' => 'intake', 'label' => 'Intake', 'order' => 1, 'description' => 'Received'],
				['uuid' => 'd2', 'identifier' => 'review', 'name' => 'Review', 'order' => 2],
				['id' => 'd3'],
			]
		);
		$this->repository->expects($this->once())->method('findRecords')->with('case-1')->willReturn(
			[
				['milestoneDefinition' => 'd1', 'reachedAt' => '2026-01-02T10:00:00', 'reachedBy' => 'alice'],
				['milestoneDefinition' => 'd2'],
			]
		);

		$progress = $this->service()->getCaseProgress(caseId: 'case-1', caseTypeId: 'type-1');

		$this->assertSame(expected: 2, actual: $progress['reached']);
		$this->assertSame(expected: 3, actual: $progress['total']);
		$this->assertSame(expected: 67, actual: $progress['percentage']);
		$this->assertSame(
			expected: [
				'identifier' => 'intake',
				'label' => 'Intake',
				'order' => 1,
				'description' => 'Received',
				'reached' => true,
				'reachedAt' => '2026-01-02T10:00:00',
				'reachedBy' => 'alice',
			],
			actual: $progress['milestones'][0]
		);
		$this->assertSame(expected: 'Review', actual: $progress['milestones'][1]['label']);
		$this->assertTrue(condition: $progress['milestones'][1]['reached']);
		$this->assertNull(actual: $progress['milestones'][1]['reachedAt']);
		$this->assertNull(actual: $progress['milestones'][1]['reachedBy']);
		$this->assertSame(
			expected: [
				'identifier' => '',
				'label' => '',
				'order' => 0,
				'description' => '',
				'reached' => false,
				'reachedAt' => null,
				'reachedBy' => null,
			],
			actual: $progress['milestones'][2]
		);
	}//end testCaseProgressJoinsDefinitionsAndRecords()

	/**
	 * A case whose type does not resolve has no progress.
	 *
	 * @return void
	 */
	public function testProgressForCaseWithoutTypeIsEmpty(): void {
		$this->repository->method('findCaseTypeId')->with('case-1')->willReturn(null);
		$this->repository->expects($this->never())->method('findDefinitions');

		$this->assertSame(expected: 0, actual: $this->service()->getCaseProgressForCase(caseId: 'case-1')['total']);
	}//end testProgressForCaseWithoutTypeIsEmpty()

	/**
	 * A case's own type is resolved and its progress computed.
	 *
	 * @return void
	 */
	public function testProgressForCaseUsesItsOwnType(): void {
		$this->repository->method('findCaseTypeId')->with('case-1')->willReturn('type-7');
		$this->repository->expects($this->once())->method('findDefinitions')->with('type-7')->willReturn([['id' => 'd1']]);
		$this->repository->method('findRecords')->willReturn([]);

		$progress = $this->service()->getCaseProgressForCase(caseId: 'case-1');

		$this->assertSame(expected: 1, actual: $progress['total']);
		$this->assertSame(expected: 0, actual: $progress['percentage']);
	}//end testProgressForCaseUsesItsOwnType()

	/**
	 * Marking needs OpenRegister.
	 *
	 * @return void
	 */
	public function testMarkWithoutOpenRegisterThrows(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available');
		$this->service()->markMilestone(caseId: 'c', definitionId: 'd', userId: 'u');
	}//end testMarkWithoutOpenRegisterThrows()

	/**
	 * Marking needs a configured record schema.
	 *
	 * @return void
	 */
	public function testMarkWithoutSchemaThrows(): void {
		$this->settings->method('getObjectService')->willReturn(new InMemoryRegister());
		$this->configure(register: 'dossiq', schema: '');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Milestone record schema not configured');
		$this->service()->markMilestone(caseId: 'c', definitionId: 'd', userId: 'u');
	}//end testMarkWithoutSchemaThrows()

	/**
	 * Marking writes one stamped record to the configured schema and answers its id.
	 *
	 * @return void
	 */
	public function testMarkWritesAStampedRecord(): void {
		$store = new class {
			/**
			 * The saved object and its options.
			 *
			 * @var array<int, array{0: array<string, mixed>, 1: ObjectSaveOptions}>
			 */
			public array $saved = [];

			/**
			 * Save an object.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options The options.
			 *
			 * @return object The saved entity.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): object {
				$this->saved[] = [$object, $options];

				return new class {
					/**
					 * The uuid.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'rec-1';
					}
				};
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);
		$this->configure(register: 'dossiq', schema: 'milestone-record');
		$this->logger->expects($this->once())->method('info')->with('Milestone marked: d1 on case case-1', ['app' => 'dossiq']);

		$result = $this->service()->markMilestone(caseId: 'case-1', definitionId: 'd1', userId: 'bob', trigger: 'workflow');

		$this->assertCount(expectedCount: 1, haystack: $store->saved);
		[$object, $options] = $store->saved[0];
		$this->assertSame(expected: 'case-1', actual: $object['case']);
		$this->assertSame(expected: 'd1', actual: $object['milestoneDefinition']);
		$this->assertSame(expected: 'bob', actual: $object['reachedBy']);
		$this->assertSame(expected: 'workflow', actual: $object['trigger']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', string: $object['reachedAt']);
		$this->assertSame(expected: 'dossiq', actual: $options->target->register);
		$this->assertSame(expected: 'milestone-record', actual: $options->target->schema);
		$this->assertSame(expected: ['id' => 'rec-1', 'reachedAt' => $object['reachedAt'], 'reachedBy' => 'bob'], actual: $result);
	}//end testMarkWritesAStampedRecord()

	/**
	 * Reversing a milestone that was never reached answers false and deletes nothing.
	 *
	 * @return void
	 */
	public function testReverseWithoutRecordsAnswersFalse(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'milestone-record', uuid: 'r1', row: ['case' => 'other', 'milestoneDefinition' => 'd1']);
		$this->settings->method('getObjectService')->willReturn($register);
		$this->configure(register: 'dossiq', schema: 'milestone-record');
		$this->logger->expects($this->never())->method('info');

		$this->assertFalse(condition: $this->service()->reverseMilestone(caseId: 'case-1', definitionId: 'd1', userId: 'bob', reason: 'oops'));
		$this->assertCount(expectedCount: 1, haystack: $register->all('milestone-record'));
	}//end testReverseWithoutRecordsAnswersFalse()

	/**
	 * Reversing deletes only this case's records of that milestone and logs who and why.
	 *
	 * @return void
	 */
	public function testReverseDeletesTheMatchingRecords(): void {
		$register = new InMemoryRegister();
		$register->seed(schema: 'milestone-record', uuid: 'r1', row: ['case' => 'case-1', 'milestoneDefinition' => 'd1']);
		$register->seed(schema: 'milestone-record', uuid: 'r2', row: ['case' => 'case-1', 'milestoneDefinition' => 'd2']);
		$register->seed(schema: 'milestone-record', uuid: 'r3', row: ['case' => 'case-2', 'milestoneDefinition' => 'd1']);
		$this->settings->method('getObjectService')->willReturn($register);
		$this->configure(register: 'dossiq', schema: 'milestone-record');
		$this->logger->expects($this->once())->method('info')->with(
			'Milestone reversed: d1 on case case-1 by bob reason: wrong phase',
			['app' => 'dossiq']
		);

		$this->assertTrue(condition: $this->service()->reverseMilestone(caseId: 'case-1', definitionId: 'd1', userId: 'bob', reason: 'wrong phase'));
		$this->assertSame(expected: [], actual: $register->row('milestone-record', 'r1'));
		$this->assertNotSame(expected: [], actual: $register->row('milestone-record', 'r2'));
		$this->assertNotSame(expected: [], actual: $register->row('milestone-record', 'r3'));
	}//end testReverseDeletesTheMatchingRecords()

	/**
	 * Duration analytics is a placeholder that answers an empty phase list.
	 *
	 * @return void
	 */
	public function testDurationAnalyticsIsAnEmptyPlaceholder(): void {
		$this->logger->expects($this->once())->method('debug')->with(
			'Duration analytics requested for case type: type-1',
			['app' => 'dossiq']
		);

		$this->assertSame(
			expected: [
				'caseTypeId' => 'type-1',
				'phases' => [],
				'message' => 'Duration analytics requires sufficient historical data',
			],
			actual: $this->service()->getDurationAnalytics(caseTypeId: 'type-1')
		);
	}//end testDurationAnalyticsIsAnEmptyPlaceholder()

	/**
	 * The stalled-case report is the detector's, with the threshold passed on.
	 *
	 * @return void
	 */
	public function testStalledCasesDelegateToTheDetector(): void {
		$this->detector->expects($this->once())->method('findStalledCases')->with(5)->willReturn([['caseId' => 'c1']]);

		$this->assertSame(expected: [['caseId' => 'c1']], actual: $this->service()->findStalledCases(thresholdDays: 5));
	}//end testStalledCasesDelegateToTheDetector()
}//end class
