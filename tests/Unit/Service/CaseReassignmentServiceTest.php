<?php

/**
 * CaseReassignmentService Unit Tests.
 *
 * Covers non-mutating preview (open only, closed/archived excluded), filtered
 * preview, full execute (per-item audit + single digest), and partial-failure
 * reporting.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Notification\IManager;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;


/**
 * Unit tests for CaseReassignmentService.
 *
 * @covers \OCA\Dossiq\Service\CaseReassignmentService
 *
 * @uses \OCA\Dossiq\Service\Support\ReassignmentBatch
 */
class CaseReassignmentServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * @var IManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $notificationManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * The engine's task reader. Tasks live in the engine, not the register,
	 * so the object-service double no longer answers for them.
	 *
	 * @var EngineTaskInbox|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $engineTasks;

	/**
	 * The engine's task verbs, which is how a task is handed over now.
	 *
	 * @var EngineTaskGateway|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $engineTask;

	/**
	 * The hand-off to OpenRegister's bulk job, which is where the loop went.
	 *
	 * @var BulkJobHandoff|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $handoff;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->handoff = $this->createMock(BulkJobHandoff::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->engineTasks = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$this->engineTask = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$this->engineTasks->method('openForAssignee')->willReturn([]);
		$this->engineTask->method('reassign')->willReturn(true);
	}//end setUp()

	/**
	 * Require the engine's `reassign` verb to be called exactly once.
	 *
	 * The assertion that matters most in this file. Reassigning a task used
	 * to be an object write, so the old test could read the new assignee
	 * back off `updateObject`. Through a verb there is nothing to read back,
	 * and without this the suite would pass with the task loop deleted.
	 *
	 * @param string $taskId The task expected to move.
	 * @param string $toUser Who it should be handed to.
	 * @param string $actor  The acting coordinator.
	 *
	 * @return void
	 */
	private function engineExpectsReassign(string $taskId, string $toUser, string $actor): void {
		$this->engineTask = $this->getMockBuilder(EngineTaskGateway::class)
			->disableOriginalConstructor()
			->getMock();
		$this->engineTask->expects($this->once())
			->method('reassign')
			->with($taskId, $toUser, $actor)
			->willReturn(true);
	}//end engineExpectsReassign()

	/**
	 * The open tasks the engine answers with for this test.
	 *
	 * The engine applies `isTerminal: false` itself, so a double that
	 * returned completed rows would be modelling something the engine
	 * cannot produce.
	 *
	 * @param array<int, array<string, mixed>> $tasks The open tasks.
	 *
	 * @return void
	 */
	private function engineHoldsOpenTasks(array $tasks): void {
		$this->engineTasks = $this->getMockBuilder(EngineTaskInbox::class)
			->disableOriginalConstructor()
			->getMock();
		$this->engineTasks->method('openForAssignee')->willReturn($tasks);
	}//end engineHoldsOpenTasks()

	/**
	 * Configure SettingsService to expose the given ObjectService via the slug path.
	 *
	 * @param object|null $objectService The ObjectService mock or null.
	 *
	 * @return CaseReassignmentService
	 */
	private function makeService(?object $objectService): CaseReassignmentService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'task_schema' => 'caseTask',
					'status_type_schema' => 'statusType',
				];
				return ($map[$key] ?? $default);
			}
		);

		return new CaseReassignmentService(
			$this->settingsService,
			$this->handoff,
			$this->engineTasks,
			$this->engineTask,
			$this->notificationManager,
			$this->logger
		);
	}//end makeService()


	/**
	 * Build a slug-aware ObjectService mock.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function objectServiceMock() {
		return $this->createMock(SubstitutionObjectServiceStub::class);
	}//end objectServiceMock()

	/**
	 * Preview returns only open cases/tasks and never mutates.
	 *
	 * @return void
	 */
	public function testPreviewOpenOnlyNonMutating(): void {
		$os = $this->objectServiceMock();
		$os->expects($this->never())->method('updateObject');
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [['id' => 'st-final', 'isFinal' => true]];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'title' => 'Open 1', 'assignee' => 'jan', 'status' => 'st-open', 'caseType' => 'vth'],
						['id' => 'c2', 'title' => 'Closed', 'assignee' => 'jan', 'status' => 'st-final', 'caseType' => 'vth'],
					];
				}
				return [];
			}
		);

		// The engine already applied `isTerminal: false`, so the closed task
		// never reaches dossiq. That split moved: it used to be a status
		// check in `filterOpenTasks`, and keeping a second copy of the three
		// state names here is exactly what this change removes.
		$this->engineHoldsOpenTasks(
			[['id' => 't1', 'title' => 'Open task', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1']]
		);

		$preview = $this->makeService($os)->preview('jan');
		$this->assertCount(1, $preview['cases']);
		$this->assertSame('c1', $preview['cases'][0]['id']);
		$this->assertCount(1, $preview['tasks']);
		$this->assertSame('t1', $preview['tasks'][0]['id']);
	}//end testPreviewOpenOnlyNonMutating()

	/**
	 * Filtered preview limits to the requested case type (and its tasks).
	 *
	 * @return void
	 */
	public function testFilteredPreview(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth'],
						['id' => 'c2', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'objectionProceeding'],
					];
				}
				return [];
			}
		);

		$this->engineHoldsOpenTasks(
			[
				['id' => 't1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1'],
				['id' => 't2', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c2'],
			]
		);

		$preview = $this->makeService($os)->preview('jan', ['caseType' => 'vth']);
		$this->assertCount(1, $preview['cases']);
		$this->assertSame('c1', $preview['cases'][0]['id']);
		// Only the task belonging to the in-scope case is included.
		$this->assertCount(1, $preview['tasks']);
		$this->assertSame('t1', $preview['tasks'][0]['id']);
	}//end testFilteredPreview()

	/**
	 * A release hands the CASES to the bulk job, scoped and with the reason.
	 *
	 * This is the assertion that carries D-6. The service used to loop here,
	 * writing each case itself; what it does now is build the selection and
	 * hand it over. A test that only checked the answer would pass on either,
	 * so what is asserted is WHAT REACHED THE JOB.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testReleaseHandsTheCasesToTheJobWithTheReason(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $reg, string $schema, array $filters) {
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'title' => 'Case 1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth'],
						['id' => 'c2', 'title' => 'Case 2', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth'],
					];
				}

				return [];
			}
		);

		$this->engineHoldsOpenTasks([]);

		$seen = [];
		$this->handoff->expects($this->once())
			->method('create')
			->willReturnCallback(
				function (string $actionId, array $parameters, array $selection, ?string $justification, string $actorUid) use (&$seen): array {
					$seen = compact('actionId', 'parameters', 'selection', 'justification', 'actorUid');

					return ['id' => 9, 'state' => 'previewed', 'total' => count($selection['ids'])];
				}
			);

		$result = $this->makeService($os)->releaseCaseload(
			fromUser: 'jan',
			toUser: 'pieter',
			justification: 'Jan left on 1 October',
			filter: null,
			actorId: 'coord',
		);

		$this->assertSame(ReassignCasesAction::ID, $seen['actionId']);
		$this->assertSame(['c1', 'c2'], $seen['selection']['ids']);
		$this->assertSame('pieter', $seen['parameters']['toUser']);
		$this->assertSame('Jan left on 1 October', $seen['parameters']['reason']);
		$this->assertSame('Jan left on 1 October', $seen['justification']);
		$this->assertSame('coord', $seen['actorUid']);
		$this->assertStringStartsWith('batch-', $seen['parameters']['batchId']);

		// Rehearsed, not run. Nothing has moved yet, and the coordinator reads
		// the job before committing it.
		$this->assertSame('previewed', $result['job']['state']);
	}//end testReleaseHandsTheCasesToTheJobWithTheReason()

	/**
	 * The service writes no case itself. A release that still called
	 * `updateObject` would be the loop back, wearing a job's name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAReleaseWritesNoCaseItself(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $reg, string $schema, array $filters) {
				return (($schema === 'case')
					? [['id' => 'c1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth']]
					: []);
			}
		);
		$os->expects($this->never())->method('updateObject');

		$this->engineHoldsOpenTasks([]);
		$this->handoff->method('create')->willReturn(['id' => 9, 'state' => 'previewed', 'total' => 1]);

		$this->makeService($os)->releaseCaseload(
			fromUser: 'jan',
			toUser: 'pieter',
			justification: 'Jan left',
			filter: null,
			actorId: 'coord',
		);
	}//end testAReleaseWritesNoCaseItself()

	/**
	 * The departing handler's TASKS move with the request, through the
	 * engine's own verb, and are not part of the job.
	 *
	 * A flow task is the engine's record, not an object in the case register,
	 * so the job cannot walk it. Leaving the tasks behind until somebody
	 * commits is the failure the gesture exists to prevent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheTasksMoveThroughTheEngineVerb(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturn([]);

		$this->engineHoldsOpenTasks(
			[['id' => 't1', 'title' => 'Task 1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1']]
		);
		$this->engineExpectsReassign(taskId: 't1', toUser: 'pieter', actor: 'coord');

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		// No cases, so no job: handing an empty selection over would create a
		// job with nothing in it and present that as a finished preview.
		$this->handoff->expects($this->never())->method('create');

		$result = $this->makeService($os)->releaseCaseload(
			fromUser: 'jan',
			toUser: 'pieter',
			justification: 'Jan left',
			filter: null,
			actorId: 'coord',
		);

		$this->assertSame([], $result['job']);
		$this->assertCount(1, $result['tasks']);
		$this->assertTrue($result['tasks'][0]['success']);
	}//end testTheTasksMoveThroughTheEngineVerb()

	/**
	 * A release with no written reason is refused before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAReleaseWithoutAReasonIsRefused(): void {
		$service = $this->makeService($this->objectServiceMock());

		$this->handoff->expects($this->never())->method('create');
		$this->expectException(InvalidArgumentException::class);

		$service->releaseCaseload(fromUser: 'jan', toUser: 'pieter', justification: '   ', actorId: 'coord');
	}//end testAReleaseWithoutAReasonIsRefused()






}//end class
