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
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SelectionReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

if (interface_exists(SubstitutionObjectServiceStub::class) === false) {
	/**
	 * Mockable ObjectService surface used by the substitution services.
	 */
	interface SubstitutionObjectServiceStub {
		/** @param int|string $id @param mixed ...$args @return mixed */
		public function find(int|string $id, ...$args): mixed;

		/** @param array<string,mixed> $query @return array<int,mixed>|int */
		public function searchObjects(array $query = []): array|int;

		/** @param string $r @param string $s @param array<string,mixed> $f @return array<int,mixed>|int */
		public function searchObjectsBySlug(string $r, string $s, array $f = []): array|int;

		/** @param mixed ...$args @return mixed */
		public function saveObject(...$args): mixed;

		/** @param mixed ...$args @return mixed */
		public function updateObject(...$args): mixed;
	}//end interface
}//end if

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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

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
					'task_schema' => 'task',
					'status_type_schema' => 'statusType',
				];
				return ($map[$key] ?? $default);
			}
		);

		return new CaseReassignmentService($this->settingsService, $this->notificationManager, $this->logger);
	}//end makeService()

	/**
	 * The sibling service that reassigns a hand-picked selection.
	 *
	 * @param object|null $objectService The ObjectService mock or null.
	 *
	 * @return SelectionReassignmentService The service.
	 */
	private function selectionService(?object $objectService): SelectionReassignmentService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'case_schema' => 'case', 'task_schema' => 'task'];

				return ($map[$key] ?? $default);
			}
		);

		return new SelectionReassignmentService($this->settingsService, $this->logger);

	}//end selectionService()

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
				if ($schema === 'task') {
					return [
						['id' => 't1', 'title' => 'Open task', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1'],
						['id' => 't2', 'title' => 'Done task', 'assignee' => 'jan', 'status' => 'completed', 'case' => 'c1'],
					];
				}
				return [];
			}
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
				if ($schema === 'task') {
					return [
						['id' => 't1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1'],
						['id' => 't2', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c2'],
					];
				}
				return [];
			}
		);

		$preview = $this->makeService($os)->preview('jan', ['caseType' => 'vth']);
		$this->assertCount(1, $preview['cases']);
		$this->assertSame('c1', $preview['cases'][0]['id']);
		// Only the task belonging to the in-scope case is included.
		$this->assertCount(1, $preview['tasks']);
		$this->assertSame('t1', $preview['tasks'][0]['id']);
	}//end testFilteredPreview()

	/**
	 * Self-reassignment is rejected.
	 *
	 * @return void
	 */
	public function testSelfReassignmentRejected(): void {
		$service = $this->makeService($this->objectServiceMock());
		$this->expectException(InvalidArgumentException::class);
		$service->execute('jan', 'jan', null, 'coord');
	}//end testSelfReassignmentRejected()

	/**
	 * Execute transfers all open items, stamps audit entries with a shared
	 * batch id, and sends exactly one digest notification.
	 *
	 * @return void
	 */
	public function testExecuteFullReassignment(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [['id' => 'c1', 'title' => 'Case 1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]']];
				}
				if ($schema === 'task') {
					return [['id' => 't1', 'title' => 'Task 1', 'assignee' => 'jan', 'status' => 'active', 'case' => 'c1']];
				}
				return [];
			}
		);

		$updated = [];
		$os->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) use (&$updated) {
				$updated[$id] = $payload;
				return $payload;
			}
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$result = $this->makeService($os)->execute('jan', 'pieter', null, 'coord');

		$this->assertSame(2, $result['succeeded']);
		$this->assertSame(0, $result['failed']);
		$this->assertStringStartsWith('batch-', $result['batchId']);
		// Case reassigned to pieter.
		$this->assertSame('pieter', $updated['c1']['assignee']);
		$this->assertSame('pieter', $updated['t1']['assignee']);
		// Audit entry on the case carries the batch id.
		$activity = json_decode($updated['c1']['activity'], true);
		$this->assertSame($result['batchId'], $activity[0]['batchId']);
		$this->assertSame('jan', $activity[0]['reassignedFrom']);
		$this->assertSame('coord', $activity[0]['reassignedBy']);
	}//end testExecuteFullReassignment()

	/**
	 * A failing item is reported as failed and stays on the original handler.
	 *
	 * @return void
	 */
	public function testPartialFailureReported(): void {
		$os = $this->objectServiceMock();
		$os->method('searchObjectsBySlug')->willReturnCallback(
			function (string $reg, string $schema, array $filters) {
				if ($schema === 'statusType') {
					return [];
				}
				if ($schema === 'case') {
					return [
						['id' => 'c1', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]'],
						['id' => 'c2', 'assignee' => 'jan', 'status' => 'open', 'caseType' => 'vth', 'activity' => '[]'],
					];
				}
				if ($schema === 'task') {
					return [];
				}
				return [];
			}
		);
		$os->method('updateObject')->willReturnCallback(
			function (string $r, string $s, string $id, array $payload) {
				if ($id === 'c2') {
					throw new \RuntimeException('concurrent modification');
				}
				return $payload;
			}
		);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$result = $this->makeService($os)->execute('jan', 'pieter', null, 'coord');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame(1, $result['failed']);
		$byId = [];
		foreach ($result['results'] as $r) {
			$byId[$r['id']] = $r['success'];
		}
		$this->assertTrue($byId['c1']);
		$this->assertFalse($byId['c2']);
	}//end testPartialFailureReported()
	/**
	 * A selection moves to the receiving handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function testASelectionIsReassigned(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'jan', 'title' => 'Case ' . $id]
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$this->assertSame(2, $result['requested']);
		$this->assertSame(2, $result['succeeded']);
		$this->assertSame('piet', $written['c1']['assignee']);
		$this->assertSame('piet', $written['c2']['assignee']);

	}//end testASelectionIsReassigned()

	/**
	 * The audit records where each case actually came FROM.
	 *
	 * The selection is hand-picked, so its rows may have different assignees.
	 * A batch-level `from` would name one person for all of them, which is a
	 * false audit trail on every row that came from somebody else.
	 *
	 * @return void
	 */
	public function testTheAuditRecordsEachCasesOwnPreviousAssignee(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => [
				'id' => (string)$id,
				'assignee' => ($id === 'c1' ? 'jan' : 'klaas'),
				'activity' => '[]',
			]
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$from = [];
		foreach (['c1', 'c2'] as $id) {
			$entries = json_decode($written[$id]['activity'], true);
			$last = end($entries);
			$from[$id] = $last['reassignedFrom'];
		}

		$this->assertSame(['c1' => 'jan', 'c2' => 'klaas'], $from);

	}//end testTheAuditRecordsEachCasesOwnPreviousAssignee()

	/**
	 * The rows of one selection share a batch id.
	 *
	 * @return void
	 */
	public function testOneSelectionIsOneBatch(): void {
		$written = [];
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'jan', 'activity' => '[]']
		);
		$os->method('updateObject')->willReturnCallback(
			static function (string $r, string $s, string $id, array $item) use (&$written): array {
				$written[$id] = $item;

				return $item;
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1', 'c2'], 'piet', 'admin');

		$batches = [];
		foreach (['c1', 'c2'] as $id) {
			$entries = json_decode($written[$id]['activity'], true);
			$last = end($entries);
			$batches[] = $last['batchId'];
		}

		$this->assertSame($result['batchId'], $batches[0]);
		$this->assertSame($batches[0], $batches[1]);

	}//end testOneSelectionIsOneBatch()

	/**
	 * A case already assigned to the receiver is left alone.
	 *
	 * Rewriting it would add an audit entry saying it moved from somebody to
	 * themselves.
	 *
	 * @return void
	 */
	public function testACaseAlreadyAssignedToTheReceiverIsNotRewritten(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static fn (int|string $id, ...$args): array => ['id' => (string)$id, 'assignee' => 'piet']
		);
		$os->expects($this->never())->method('updateObject');

		$result = $this->selectionService($os)->executeForCases(['c1'], 'piet', 'admin');

		$this->assertSame(1, $result['succeeded']);
		$this->assertSame('already assigned', $result['results'][0]['reason']);

	}//end testACaseAlreadyAssignedToTheReceiverIsNotRewritten()

	/**
	 * A case that cannot be read is reported, not silently skipped.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsReported(): void {
		$os = $this->objectServiceMock();
		$os->method('find')->willReturnCallback(
			static function (int|string $id, ...$args): mixed {
				throw new \RuntimeException('gone');
			}
		);

		$result = $this->selectionService($os)->executeForCases(['c1'], 'piet', 'admin');

		$this->assertSame(0, $result['succeeded']);
		$this->assertFalse($result['results'][0]['success']);
		$this->assertSame('not found', $result['results'][0]['reason']);

	}//end testAMissingCaseIsReported()

	/**
	 * An empty selection or a missing receiver is refused.
	 *
	 * @return void
	 */
	public function testItRefusesAnEmptySelectionOrNoReceiver(): void {
		$service = $this->selectionService($this->objectServiceMock());

		$this->expectException(\InvalidArgumentException::class);
		$service->executeForCases([], 'piet', 'admin');

	}//end testItRefusesAnEmptySelectionOrNoReceiver()

}//end class
