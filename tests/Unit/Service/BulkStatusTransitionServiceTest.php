<?php

/**
 * BulkStatusTransitionService tests.
 *
 * Verifies the bulk wrapper's invariants: `preview()` never calls the
 * engine's `execute()` (read-only), `execute()` loops the engine once per
 * case with per-case guard-failure and exception isolation (partial success
 * allowed, never silently swallowed), and the 1..100 id / non-empty
 * transitionId validation on both entry points.
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

use OCA\Dossiq\Service\BulkStatusTransitionService;
use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BulkStatusTransitionService::preview() and ::execute().
 *
 * @covers \OCA\Dossiq\Service\BulkStatusTransitionService
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardFailedException
 *
 * @spec openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
 */
final class BulkStatusTransitionServiceTest extends TestCase {

	/**
	 * @var StatusTransitionService&MockObject
	 */
	private StatusTransitionService $engine;

	/**
	 * @var CaseLifecycleService&MockObject
	 */
	private CaseLifecycleService $lifecycle;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var BulkStatusTransitionService
	 */
	private BulkStatusTransitionService $service;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = $this->createMock(StatusTransitionService::class);
		$this->lifecycle = $this->createMock(CaseLifecycleService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new BulkStatusTransitionService(
			$this->engine,
			$this->lifecycle,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Build a `getAvailableTransitions()`-shaped payload for a single transition.
	 *
	 * @param string $transitionId Transition id
	 * @param bool $guardsPassed Whether guards pass
	 * @param array<int, array<string, mixed>> $failedGuards Failed guard snapshots
	 *
	 * @return array<string, mixed>
	 */
	private function availableResult(string $transitionId, bool $guardsPassed, array $failedGuards = []): array {
		return [
			'transitions' => [
				[
					'id' => $transitionId,
					'label' => 'Submit',
					'toStatus' => 'status-2',
					'guardsPassed' => $guardsPassed,
					'failedGuards' => $failedGuards,
				],
			],
			'current' => ['statusId' => 'status-1', 'statusName' => 'Received'],
		];
	}//end availableResult()

	/**
	 * preview() marks every case ready when its guards pass, and never calls execute().
	 *
	 * @return void
	 */
	public function testPreviewHappyPathMarksAllReady(): void {
		$this->engine->method('getAvailableTransitions')->willReturn($this->availableResult('submit', true));
		$this->engine->expects($this->never())->method('execute');

		$result = $this->service->preview(['case-1', 'case-2'], 'submit');

		$this->assertSame('ready', $result['results']['case-1']['status']);
		$this->assertSame('ready', $result['results']['case-2']['status']);
		$this->assertSame(['total' => 2, 'ready' => 2, 'blocked' => 0, 'error' => 0], $result['summary']);
	}//end testPreviewHappyPathMarksAllReady()

	/**
	 * preview() reports a per-case guard failure as blocked, with reasons, and
	 * still performs no writes.
	 *
	 * @return void
	 */
	public function testPreviewMarksGuardFailureAsBlocked(): void {
		$failedGuards = [['type' => 'roleGuard', 'passed' => false, 'failureMessage' => 'not allowed']];

		$this->engine->method('getAvailableTransitions')->willReturnCallback(
			fn (string $caseId): array => $caseId === 'case-2'
				? $this->availableResult('submit', false, $failedGuards)
				: $this->availableResult('submit', true)
		);
		$this->engine->expects($this->never())->method('execute');

		$result = $this->service->preview(['case-1', 'case-2'], 'submit');

		$this->assertSame('ready', $result['results']['case-1']['status']);
		$this->assertSame('blocked', $result['results']['case-2']['status']);
		$this->assertSame($failedGuards, $result['results']['case-2']['reasons']);
		$this->assertSame(['total' => 2, 'ready' => 1, 'blocked' => 1, 'error' => 0], $result['summary']);
	}//end testPreviewMarksGuardFailureAsBlocked()

	/**
	 * preview() marks a case blocked when the requested transition is not in
	 * that case's available set (e.g. wrong fromStatus).
	 *
	 * @return void
	 */
	public function testPreviewMarksUnavailableTransitionAsBlocked(): void {
		$this->engine->method('getAvailableTransitions')->willReturn(
			['transitions' => [], 'current' => ['statusId' => 'status-9', 'statusName' => 'Anders']]
		);

		$result = $this->service->preview(['case-1'], 'submit');

		$this->assertSame('blocked', $result['results']['case-1']['status']);
		$this->assertSame(['total' => 1, 'ready' => 0, 'blocked' => 1, 'error' => 0], $result['summary']);
	}//end testPreviewMarksUnavailableTransitionAsBlocked()

	/**
	 * preview() isolates a per-case exception as an 'error' outcome rather than
	 * aborting the batch.
	 *
	 * @return void
	 */
	public function testPreviewIsolatesPerCaseException(): void {
		$this->engine->method('getAvailableTransitions')->willReturnCallback(
			function (string $caseId): array {
				if ($caseId === 'case-1') {
					throw new RuntimeException('boom');
				}
				return $this->availableResult('submit', true);
			}
		);

		$result = $this->service->preview(['case-1', 'case-2'], 'submit');

		$this->assertSame('error', $result['results']['case-1']['status']);
		$this->assertSame('ready', $result['results']['case-2']['status']);
		$this->assertSame(['total' => 2, 'ready' => 1, 'blocked' => 0, 'error' => 1], $result['summary']);
	}//end testPreviewIsolatesPerCaseException()

	/**
	 * preview() rejects an empty case id list.
	 *
	 * @return void
	 */
	public function testPreviewRejectsEmptyCaseIds(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_ids_required');

		$this->service->preview([], 'submit');
	}//end testPreviewRejectsEmptyCaseIds()

	/**
	 * preview() rejects more than 100 case ids.
	 *
	 * @return void
	 */
	public function testPreviewRejectsOversizedCaseIds(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too_many_case_ids');

		$this->service->preview(array_map(static fn (int $i): string => "case-$i", range(1, 101)), 'submit');
	}//end testPreviewRejectsOversizedCaseIds()

	/**
	 * preview() rejects an empty transitionId.
	 *
	 * @return void
	 */
	public function testPreviewRejectsEmptyTransitionId(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('transition_id_required');

		$this->service->preview(['case-1'], '');
	}//end testPreviewRejectsEmptyTransitionId()

	/**
	 * execute() loops the engine once per case and reports the happy-path summary.
	 *
	 * @return void
	 */
	public function testExecuteHappyPathCallsEngineOncePerCase(): void {
		$this->engine->expects($this->exactly(2))
			->method('execute')
			->willReturn(['status' => 'ok', 'statusRecord' => ['id' => 'rec-1'], 'dispatchedActions' => [], 'version' => 2]);

		$result = $this->service->execute(['case-1', 'case-2'], 'submit', 'go ahead');

		$this->assertSame('succeeded', $result['results']['case-1']['status']);
		$this->assertSame('succeeded', $result['results']['case-2']['status']);
		$this->assertSame(['total' => 2, 'succeeded' => 2, 'failed' => 0, 'error' => 0], $result['summary']);
	}//end testExecuteHappyPathCallsEngineOncePerCase()

	/**
	 * A GuardFailedException on one case is recorded as 'failed' with reasons,
	 * and does not stop the remaining cases from being processed (partial
	 * success). Two of three cases succeed, one fails with guard reasons.
	 *
	 * @return void
	 */
	public function testExecuteMixedGuardFailureAllowsPartialSuccess(): void {
		$failedGuards = [['type' => 'requiredDocumentGuard', 'passed' => false, 'failureMessage' => 'missing document']];

		$this->engine->method('execute')->willReturnCallback(
			function (string $caseId) use ($failedGuards): array {
				if ($caseId === 'case-2') {
					throw new GuardFailedException($failedGuards);
				}
				return ['status' => 'ok', 'statusRecord' => ['id' => 'rec-' . $caseId], 'dispatchedActions' => [], 'version' => 2];
			}
		);

		$result = $this->service->execute(['case-1', 'case-2', 'case-3'], 'submit', null);

		$this->assertSame('succeeded', $result['results']['case-1']['status']);
		$this->assertSame('failed', $result['results']['case-2']['status']);
		$this->assertSame($failedGuards, $result['results']['case-2']['reasons']);
		$this->assertSame('succeeded', $result['results']['case-3']['status']);
		$this->assertSame(['total' => 3, 'succeeded' => 2, 'failed' => 1, 'error' => 0], $result['summary']);
	}//end testExecuteMixedGuardFailureAllowsPartialSuccess()

	/**
	 * A generic Throwable on one case is recorded as 'error' and logged, and
	 * does not abort the rest of the batch (per-case exception isolation).
	 *
	 * @return void
	 */
	public function testExecuteIsolatesPerCaseException(): void {
		$this->engine->method('execute')->willReturnCallback(
			function (string $caseId): array {
				if ($caseId === 'case-1') {
					throw new RuntimeException('case_not_found');
				}
				return ['status' => 'ok', 'statusRecord' => [], 'dispatchedActions' => [], 'version' => 1];
			}
		);

		$this->logger->expects($this->once())->method('error');

		$result = $this->service->execute(['case-1', 'case-2'], 'submit', null);

		$this->assertSame('error', $result['results']['case-1']['status']);
		$this->assertSame('succeeded', $result['results']['case-2']['status']);
		$this->assertSame(['total' => 2, 'succeeded' => 1, 'failed' => 0, 'error' => 1], $result['summary']);
	}//end testExecuteIsolatesPerCaseException()

	/**
	 * execute() rejects an empty case id list without touching the engine.
	 *
	 * @return void
	 */
	public function testExecuteRejectsEmptyCaseIds(): void {
		$this->engine->expects($this->never())->method('execute');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_ids_required');

		$this->service->execute([], 'submit', null);
	}//end testExecuteRejectsEmptyCaseIds()

	/**
	 * execute() rejects more than 100 case ids without touching the engine.
	 *
	 * @return void
	 */
	public function testExecuteRejectsOversizedCaseIds(): void {
		$this->engine->expects($this->never())->method('execute');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too_many_case_ids');

		$this->service->execute(array_map(static fn (int $i): string => "case-$i", range(1, 101)), 'submit', null);
	}//end testExecuteRejectsOversizedCaseIds()

	/**
	 * execute() rejects an empty transitionId without touching the engine.
	 *
	 * @return void
	 */
	public function testExecuteRejectsEmptyTransitionId(): void {
		$this->engine->expects($this->never())->method('execute');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('transition_id_required');

		$this->service->execute(['case-1'], '', null);
	}//end testExecuteRejectsEmptyTransitionId()

	/**
	 * A `state()`-shaped payload.
	 *
	 * @param bool $canSuspend Whether the case may be suspended
	 * @param bool $suspended Whether it is suspended now
	 * @param bool $isFinal Whether its status is final
	 *
	 * @return array<string, mixed>
	 */
	private function lifecycleState(bool $canSuspend, bool $suspended = false, bool $isFinal = false): array {
		return [
			'suspended' => $suspended,
			'canSuspend' => $canSuspend,
			'canResume' => $suspended,
			'canExtend' => ($isFinal === false),
			'canReopen' => $isFinal,
			'isFinalStatus' => $isFinal,
			'extensionCount' => 0,
			'deadline' => '2026-10-01',
			'statusName' => 'In behandeling',
		];
	}//end lifecycleState()

	/**
	 * previewLifecycle() reports per case whether the gesture is allowed, and
	 * writes nothing while doing it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testPreviewLifecycleReportsPerCaseWithoutWriting(): void {
		$this->lifecycle->expects($this->never())->method('suspend');
		$this->lifecycle->method('state')->willReturnCallback(
			fn (string $caseId): array => $this->lifecycleState(canSuspend: $caseId === 'case-1')
		);

		$result = $this->service->previewLifecycle(['case-1', 'case-2'], 'suspend');

		$this->assertSame('ready', $result['results']['case-1']['status']);
		$this->assertSame('blocked', $result['results']['case-2']['status']);
		$this->assertSame('suspension_not_allowed', $result['results']['case-2']['reasons'][0]['message']);
		$this->assertSame(['total' => 2, 'ready' => 1, 'blocked' => 1, 'error' => 0], $result['summary']);
	}//end testPreviewLifecycleReportsPerCaseWithoutWriting()

	/**
	 * previewLifecycle() names the case's own state as the refusal, not the
	 * case type's rule, when the case is already suspended.
	 *
	 * @return void
	 */
	public function testPreviewLifecycleNamesTheAlreadySuspendedCase(): void {
		$this->lifecycle->method('state')->willReturn(
			$this->lifecycleState(canSuspend: false, suspended: true)
		);

		$result = $this->service->previewLifecycle(['case-1'], 'suspend');

		$this->assertSame('already_suspended', $result['results']['case-1']['reasons'][0]['message']);
	}//end testPreviewLifecycleNamesTheAlreadySuspendedCase()

	/**
	 * executeLifecycle() suspends every case through the single-case gesture,
	 * carrying the reason and the days.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testExecuteLifecycleSuspendsWithTheReason(): void {
		$seen = [];
		$this->lifecycle->method('suspend')->willReturnCallback(
			function (string $caseId, string $reason, int $days) use (&$seen): array {
				$seen[] = [$caseId, $reason, $days];
				return $this->lifecycleState(canSuspend: false, suspended: true);
			}
		);

		$result = $this->service->executeLifecycle(['case-1', 'case-2'], 'suspend', 'Awaiting documents', 21);

		$this->assertSame(
			[['case-1', 'Awaiting documents', 21], ['case-2', 'Awaiting documents', 21]],
			$seen,
		);
		$this->assertSame(['total' => 2, 'succeeded' => 2, 'failed' => 0, 'error' => 0], $result['summary']);
	}//end testExecuteLifecycleSuspendsWithTheReason()

	/**
	 * executeLifecycle() resumes through the single-case gesture.
	 *
	 * @return void
	 */
	public function testExecuteLifecycleResumesWithTheReason(): void {
		$this->lifecycle->expects($this->once())
			->method('resume')
			->with('case-1', 'Documents received')
			->willReturn($this->lifecycleState(canSuspend: true));

		$result = $this->service->executeLifecycle(['case-1'], 'resume', 'Documents received');

		$this->assertSame('succeeded', $result['results']['case-1']['status']);
	}//end testExecuteLifecycleResumesWithTheReason()

	/**
	 * executeLifecycle() passes the named new end date through to extend().
	 *
	 * @return void
	 */
	public function testExecuteLifecycleExtendsToTheNamedDate(): void {
		$this->lifecycle->expects($this->once())
			->method('extend')
			->with('case-1', 'Complex case', '2026-12-01')
			->willReturn($this->lifecycleState(canSuspend: true));

		$result = $this->service->executeLifecycle(['case-1'], 'extend', 'Complex case', 0, '2026-12-01');

		$this->assertSame('succeeded', $result['results']['case-1']['status']);
	}//end testExecuteLifecycleExtendsToTheNamedDate()

	/**
	 * A case the gesture refuses is reported per case and never aborts the
	 * batch — the partial failure the spec requires can never read as success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testExecuteLifecycleReportsARefusalPerCase(): void {
		$this->lifecycle->method('suspend')->willReturnCallback(
			function (string $caseId): array {
				if ($caseId === 'case-1') {
					throw new RuntimeException('already_suspended');
				}
				return $this->lifecycleState(canSuspend: false, suspended: true);
			}
		);

		$result = $this->service->executeLifecycle(['case-1', 'case-2'], 'suspend', 'Awaiting documents');

		$this->assertSame('failed', $result['results']['case-1']['status']);
		$this->assertSame('already_suspended', $result['results']['case-1']['reasons'][0]['message']);
		$this->assertSame('succeeded', $result['results']['case-2']['status']);
		$this->assertSame(['total' => 2, 'succeeded' => 1, 'failed' => 1, 'error' => 0], $result['summary']);
	}//end testExecuteLifecycleReportsARefusalPerCase()

	/**
	 * executeLifecycle() refuses a batch with no reason, before writing
	 * anything: a statutory act nobody justified is the one thing a bulk
	 * gesture must not record twenty times.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testExecuteLifecycleRejectsAnEmptyReason(): void {
		$this->lifecycle->expects($this->never())->method('suspend');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('reason_required');

		$this->service->executeLifecycle(['case-1'], 'suspend', '   ');
	}//end testExecuteLifecycleRejectsAnEmptyReason()

	/**
	 * A gesture the service does not know is refused outright rather than
	 * falling through to one it does.
	 *
	 * @return void
	 */
	public function testLifecycleRejectsAnUnknownGesture(): void {
		$this->lifecycle->expects($this->never())->method('state');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('unknown_gesture');

		$this->service->previewLifecycle(['case-1'], 'reopen');
	}//end testLifecycleRejectsAnUnknownGesture()

	/**
	 * The id-count validation guards the lifecycle path too.
	 *
	 * @return void
	 */
	public function testLifecycleRejectsOversizedCaseIds(): void {
		$this->lifecycle->expects($this->never())->method('state');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('too_many_case_ids');

		$this->service->previewLifecycle(
			array_map(static fn (int $i): string => "case-$i", range(1, 101)),
			'suspend',
		);
	}//end testLifecycleRejectsOversizedCaseIds()
}//end class
