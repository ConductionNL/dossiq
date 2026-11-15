<?php

/**
 * CaseReassignmentService release golden tests.
 *
 * Pins what a caseload release does end to end: the refusals, the trimmed
 * values the job receives, the commit path, the log line, the digest
 * notification and the warning when the digest cannot be sent.
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
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\EngineTaskInbox;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for CaseReassignmentService::releaseCaseload().
 *
 * @covers \OCA\Dossiq\Service\CaseReassignmentService
 * @uses \OCA\Dossiq\Service\CaseloadReleaseRun
 */
class CaseReassignmentReleaseGoldenTest extends TestCase {

	/**
	 * @var BulkJobHandoff&MockObject
	 */
	private $handoff;

	/**
	 * @var IManager&MockObject
	 */
	private $notificationManager;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var EngineTaskGateway&MockObject
	 */
	private $engineTask;

	/**
	 * The open tasks the engine answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $openTasks = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handoff = $this->createMock(BulkJobHandoff::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->engineTask = $this->getMockBuilder(EngineTaskGateway::class)->disableOriginalConstructor()->getMock();
	}//end setUp()

	/**
	 * Build the service over two open cases of handler jan.
	 *
	 * @return CaseReassignmentService
	 */
	private function service(): CaseReassignmentService {
		$objectService = $this->createMock(SubstitutionObjectServiceStub::class);
		$objectService->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $reg, string $schema): array {
				if ($schema === 'case') {
					return [['id' => 'c1', 'status' => 'open'], ['uuid' => 'c2', 'status' => 'open']];
				}

				return [];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return ['register' => 'dossiq', 'case_schema' => 'case', 'status_type_schema' => 'statusType'][$key] ?? '';
			}
		);

		$inbox = $this->getMockBuilder(EngineTaskInbox::class)->disableOriginalConstructor()->getMock();
		$inbox->method('openForAssignee')->willReturnCallback(fn (): array => $this->openTasks);

		return new CaseReassignmentService($settings, $this->handoff, $inbox, $this->engineTask, $this->notificationManager, $this->logger);
	}//end service()

	/**
	 * A notification double that records every setter call.
	 *
	 * @param array<string, mixed> $seen Collects the setter arguments.
	 *
	 * @return INotification&MockObject
	 */
	private function notification(array &$seen): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (...$args) use (&$seen, $setter, $notification) {
					$seen[$setter] = $args;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * Every refusal names what is missing, before the job is touched.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no departing handler' => ['  ', 'pieter', 'reason', 'Both fromUser and toUser are required'],
			'no receiver' => ['jan', ' ', 'reason', 'Both fromUser and toUser are required'],
			'to themselves' => [' jan', 'jan ', 'reason', 'Cannot reassign a handler to themselves'],
			'no reason' => ['jan', 'pieter', "\t", 'A written justification is required'],
		];
	}//end refusals()

	/**
	 * A release without a handler, receiver or reason is refused.
	 *
	 * @param string $from    The departing handler.
	 * @param string $to      The receiver.
	 * @param string $reason  The reason.
	 * @param string $message The expected refusal.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testARefusedReleaseTouchesNothing(string $from, string $to, string $reason, string $message): void {
		$this->handoff->expects($this->never())->method('create');
		$this->engineTask->expects($this->never())->method('reassign');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->service()->releaseCaseload($from, $to, $reason, null, 'coord', true);
	}//end testARefusedReleaseTouchesNothing()

	/**
	 * A committed release hands trimmed values to the job and commits it.
	 *
	 * @return void
	 */
	public function testACommittedReleaseCommitsTheJob(): void {
		$created = [];
		$this->handoff->expects($this->once())->method('create')->willReturnCallback(
			function (string $actionId, array $parameters, array $selection, ?string $justification, string $actorUid) use (&$created): array {
				$created = [$parameters, $selection, $justification, $actorUid];
				return ['id' => 9, 'state' => 'previewed'];
			}
		);
		$this->handoff->expects($this->once())->method('commit')
			->with(['id' => 9, 'state' => 'previewed'], 'Jan left')
			->willReturn(['id' => 9, 'state' => 'running']);

		$result = $this->service()->releaseCaseload(' jan ', ' pieter ', ' Jan left ', null, 'coord', true);

		$this->assertSame(['id' => 9, 'state' => 'running'], $result['job']);
		$this->assertSame(['c1', 'c2'], $result['caseIds']);
		$this->assertSame([], $result['tasks']);
		$this->assertSame('pieter', $created[0]['toUser']);
		$this->assertSame('Jan left', $created[0]['reason']);
		$this->assertSame(['ids' => ['c1', 'c2']], $created[1]);
		$this->assertSame('Jan left', $created[2]);
		$this->assertSame('coord', $created[3]);
	}//end testACommittedReleaseCommitsTheJob()

	/**
	 * A rehearsed release is never committed.
	 *
	 * @return void
	 */
	public function testARehearsedReleaseIsNotCommitted(): void {
		$this->handoff->method('create')->willReturn(['id' => 9, 'state' => 'previewed']);
		$this->handoff->expects($this->never())->method('commit');

		$result = $this->service()->releaseCaseload('jan', 'pieter', 'Jan left', null, 'coord', false);

		$this->assertSame(['id' => 9, 'state' => 'previewed'], $result['job']);
	}//end testARehearsedReleaseIsNotCommitted()

	/**
	 * The log line names the batch, both handlers, the actor and the counts,
	 * and the digest carries the same batch to the receiver.
	 *
	 * @return void
	 */
	public function testTheReleaseIsLoggedAndTheReceiverNotified(): void {
		$this->openTasks = [['id' => 't1', 'title' => 'Task 1'], ['uuid' => 't2']];
		$this->engineTask->method('reassign')->willReturnMap([['t1', 'pieter', 'coord', true], ['t2', 'pieter', 'coord', false]]);
		$this->handoff->method('create')->willReturn(['id' => 9]);

		$logged = [];
		$this->logger->expects($this->once())->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged = [$message, $context];
			}
		);
		$seen = [];
		$this->notificationManager->method('createNotification')->willReturn($this->notification($seen));
		$this->notificationManager->expects($this->once())->method('notify');

		$result = $this->service()->releaseCaseload('jan', 'pieter', 'Jan left', null, 'coord');

		$this->assertSame(
			[['id' => 't1', 'title' => 'Task 1', 'success' => true], ['id' => 't2', 'title' => '', 'success' => false]],
			$result['tasks']
		);
		$this->assertSame('Dossiq caseload release handed to a bulk job', $logged[0]);
		$batchId = $logged[1]['batchId'];
		$this->assertStringStartsWith('batch-', $batchId);
		$this->assertSame(
			['batchId' => $batchId, 'from' => 'jan', 'to' => 'pieter', 'actor' => 'coord', 'cases' => 2, 'tasks' => 2],
			$logged[1]
		);
		$this->assertSame(['dossiq'], $seen['setApp']);
		$this->assertSame(['pieter'], $seen['setUser']);
		$this->assertSame(['reassignment', $batchId], $seen['setObject']);
		$this->assertSame(['cases_reassigned', ['fromUser' => 'jan', 'count' => 2]], $seen['setSubject']);
	}//end testTheReleaseIsLoggedAndTheReceiverNotified()

	/**
	 * Without tasks there is no digest.
	 *
	 * @return void
	 */
	public function testNoTasksMeansNoDigest(): void {
		$this->handoff->method('create')->willReturn(['id' => 9]);
		$this->notificationManager->expects($this->never())->method('createNotification');

		$this->service()->releaseCaseload('jan', 'pieter', 'Jan left', null, 'coord');
	}//end testNoTasksMeansNoDigest()

	/**
	 * A digest that cannot be sent is a warning, and the release still answers.
	 *
	 * @return void
	 */
	public function testAFailedDigestIsAWarning(): void {
		$this->openTasks = [['id' => 't1']];
		$this->engineTask->method('reassign')->willReturn(true);
		$this->handoff->method('create')->willReturn(['id' => 9]);
		$seen = [];
		$this->notificationManager->method('createNotification')->willReturn($this->notification($seen));
		$this->notificationManager->method('notify')->willThrowException(new RuntimeException('mail down'));

		$warned = [];
		$this->logger->expects($this->once())->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warned): void {
				$warned = [$message, $context];
			}
		);

		$result = $this->service()->releaseCaseload('jan', 'pieter', 'Jan left', null, 'coord');

		$this->assertCount(1, $result['tasks']);
		$this->assertSame('Reassignment digest notification failed', $warned[0]);
		$this->assertSame('pieter', $warned[1]['toUser']);
		$this->assertSame('mail down', $warned[1]['error']);
		$this->assertStringStartsWith('batch-', $warned[1]['batchId']);
	}//end testAFailedDigestIsAWarning()
}//end class
