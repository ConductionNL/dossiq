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
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\BulkStatusTransitionService;
use OCA\Procest\Service\StatusTransitionService;
use OCA\Procest\Service\Transitions\GuardFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BulkStatusTransitionService::preview() and ::execute().
 *
 * @covers \OCA\Procest\Service\BulkStatusTransitionService
 *
 * @spec openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
 */
final class BulkStatusTransitionServiceTest extends TestCase
{

    /**
     * @var StatusTransitionService&MockObject
     */
    private StatusTransitionService $engine;

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
    protected function setUp(): void
    {
        $this->engine  = $this->createMock(StatusTransitionService::class);
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new BulkStatusTransitionService($this->engine, $this->logger);
    }//end setUp()

    /**
     * Build a `getAvailableTransitions()`-shaped payload for a single transition.
     *
     * @param string $transitionId Transition id
     * @param bool   $guardsPassed Whether guards pass
     * @param array<int, array<string, mixed>> $failedGuards Failed guard snapshots
     *
     * @return array<string, mixed>
     */
    private function availableResult(string $transitionId, bool $guardsPassed, array $failedGuards=[]): array
    {
        return [
            'transitions' => [
                [
                    'id'           => $transitionId,
                    'label'        => 'Submit',
                    'toStatus'     => 'status-2',
                    'guardsPassed' => $guardsPassed,
                    'failedGuards' => $failedGuards,
                ],
            ],
            'current'     => ['statusId' => 'status-1', 'statusName' => 'Ontvangen'],
        ];
    }//end availableResult()

    /**
     * preview() marks every case ready when its guards pass, and never calls execute().
     *
     * @return void
     */
    public function testPreviewHappyPathMarksAllReady(): void
    {
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
    public function testPreviewMarksGuardFailureAsBlocked(): void
    {
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
    public function testPreviewMarksUnavailableTransitionAsBlocked(): void
    {
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
    public function testPreviewIsolatesPerCaseException(): void
    {
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
    public function testPreviewRejectsEmptyCaseIds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('case_ids_required');

        $this->service->preview([], 'submit');
    }//end testPreviewRejectsEmptyCaseIds()

    /**
     * preview() rejects more than 100 case ids.
     *
     * @return void
     */
    public function testPreviewRejectsOversizedCaseIds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too_many_case_ids');

        $this->service->preview(array_map(static fn (int $i): string => "case-$i", range(1, 101)), 'submit');
    }//end testPreviewRejectsOversizedCaseIds()

    /**
     * preview() rejects an empty transitionId.
     *
     * @return void
     */
    public function testPreviewRejectsEmptyTransitionId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transition_id_required');

        $this->service->preview(['case-1'], '');
    }//end testPreviewRejectsEmptyTransitionId()

    /**
     * execute() loops the engine once per case and reports the happy-path summary.
     *
     * @return void
     */
    public function testExecuteHappyPathCallsEngineOncePerCase(): void
    {
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
    public function testExecuteMixedGuardFailureAllowsPartialSuccess(): void
    {
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
    public function testExecuteIsolatesPerCaseException(): void
    {
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
    public function testExecuteRejectsEmptyCaseIds(): void
    {
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
    public function testExecuteRejectsOversizedCaseIds(): void
    {
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
    public function testExecuteRejectsEmptyTransitionId(): void
    {
        $this->engine->expects($this->never())->method('execute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transition_id_required');

        $this->service->execute(['case-1'], '', null);
    }//end testExecuteRejectsEmptyTransitionId()
}//end class
