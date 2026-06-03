<?php

/**
 * SyncBackoffService Unit Tests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SyncBackoffService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SyncBackoffService.
 *
 * @covers \OCA\Procest\Service\SyncBackoffService
 */
class SyncBackoffServiceTest extends TestCase
{

    /**
     * @var SyncBackoffService
     */
    private SyncBackoffService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new SyncBackoffService();
    }//end setUp()

    /**
     * The backoff schedule follows 1s, 5s, 30s, 5min, 30min.
     *
     * @return void
     */
    public function testBackoffScheduleMatchesSpec(): void
    {
        $this->assertSame(1, $this->service->delayForAttempt(0));
        $this->assertSame(5, $this->service->delayForAttempt(1));
        $this->assertSame(30, $this->service->delayForAttempt(2));
        $this->assertSame(300, $this->service->delayForAttempt(3));
        $this->assertSame(1800, $this->service->delayForAttempt(4));
    }//end testBackoffScheduleMatchesSpec()

    /**
     * A negative attempt count is treated as the first attempt.
     *
     * @return void
     */
    public function testNegativeAttemptClampsToFirst(): void
    {
        $this->assertSame(1, $this->service->delayForAttempt(-3));
    }//end testNegativeAttemptClampsToFirst()

    /**
     * The schedule is exhausted after the fifth attempt.
     *
     * @return void
     */
    public function testExhaustedScheduleReturnsMinusOne(): void
    {
        $this->assertSame(-1, $this->service->delayForAttempt(5));
        $this->assertSame(-1, $this->service->delayForAttempt(99));
    }//end testExhaustedScheduleReturnsMinusOne()

    /**
     * Jitter never reduces below the base and stays within 25%.
     *
     * @return void
     */
    public function testJitterStaysWithinBounds(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $delay = $this->service->delayForAttempt(3, jitter: true);
            $this->assertGreaterThanOrEqual(300, $delay);
            $this->assertLessThanOrEqual(375, $delay);
        }
    }//end testJitterStaysWithinBounds()

    /**
     * Retry accounting matches the schedule length.
     *
     * @return void
     */
    public function testHasRetriesRemaining(): void
    {
        $this->assertTrue($this->service->hasRetriesRemaining(0));
        $this->assertTrue($this->service->hasRetriesRemaining(4));
        $this->assertFalse($this->service->hasRetriesRemaining(5));
        $this->assertSame(5, $this->service->maxAttempts());
    }//end testHasRetriesRemaining()

    /**
     * A successful attempt marks the operation synced and clears the error.
     *
     * @return void
     */
    public function testApplySuccessOutcome(): void
    {
        $op     = ['status' => 'pending', 'attemptCount' => 2, 'lastError' => 'boom'];
        $result = $this->service->applyAttemptOutcome($op, 'success', null, '2026-05-22T10:00:00+00:00');

        $this->assertSame(SyncBackoffService::STATUS_SYNCED, $result['status']);
        $this->assertSame('2026-05-22T10:00:00+00:00', $result['syncedAt']);
        $this->assertNull($result['lastError']);
    }//end testApplySuccessOutcome()

    /**
     * A transient error increments the attempt count and stays pending while
     * retries remain.
     *
     * @return void
     */
    public function testTransientErrorRequeuesWhileRetriesRemain(): void
    {
        $op     = ['status' => 'syncing', 'attemptCount' => 1];
        $result = $this->service->applyAttemptOutcome($op, 'transient_error', '503 Service Unavailable');

        $this->assertSame(SyncBackoffService::STATUS_PENDING, $result['status']);
        $this->assertSame(2, $result['attemptCount']);
        $this->assertSame('503 Service Unavailable', $result['lastError']);
    }//end testTransientErrorRequeuesWhileRetriesRemain()

    /**
     * A transient error after the final retry moves the operation to failed.
     *
     * @return void
     */
    public function testTransientErrorFailsAfterMaxAttempts(): void
    {
        $op     = ['status' => 'syncing', 'attemptCount' => 4];
        $result = $this->service->applyAttemptOutcome($op, 'transient_error', 'timeout');

        $this->assertSame(SyncBackoffService::STATUS_FAILED, $result['status']);
        $this->assertSame(5, $result['attemptCount']);
    }//end testTransientErrorFailsAfterMaxAttempts()

    /**
     * A conflict does not consume a retry and sets conflict status.
     *
     * @return void
     */
    public function testConflictDoesNotConsumeRetry(): void
    {
        $op     = ['status' => 'syncing', 'attemptCount' => 1];
        $result = $this->service->applyAttemptOutcome($op, 'conflict');

        $this->assertSame(SyncBackoffService::STATUS_CONFLICT, $result['status']);
        $this->assertSame(1, $result['attemptCount']);
    }//end testConflictDoesNotConsumeRetry()

    /**
     * Permission loss is terminal regardless of remaining retries.
     *
     * @return void
     */
    public function testPermissionLostIsTerminal(): void
    {
        $op     = ['status' => 'syncing', 'attemptCount' => 0];
        $result = $this->service->applyAttemptOutcome($op, 'permission_lost', '403 Forbidden');

        $this->assertSame(SyncBackoffService::STATUS_FAILED, $result['status']);
        $this->assertSame('403 Forbidden', $result['lastError']);
    }//end testPermissionLostIsTerminal()

    /**
     * Synced operations older than 7 days are eligible for cleanup.
     *
     * @return void
     */
    public function testCleanupEligibility(): void
    {
        $old = ['status' => 'synced', 'syncedAt' => '2026-05-01T00:00:00+00:00'];
        $new = ['status' => 'synced', 'syncedAt' => '2026-05-20T00:00:00+00:00'];

        $this->assertTrue($this->service->isEligibleForCleanup($old, '2026-05-22T00:00:00+00:00'));
        $this->assertFalse($this->service->isEligibleForCleanup($new, '2026-05-22T00:00:00+00:00'));
    }//end testCleanupEligibility()

    /**
     * Non-synced or undated operations are never cleaned up.
     *
     * @return void
     */
    public function testCleanupSkipsNonSynced(): void
    {
        $pending = ['status' => 'pending', 'syncedAt' => '2026-05-01T00:00:00+00:00'];
        $undated = ['status' => 'synced'];

        $this->assertFalse($this->service->isEligibleForCleanup($pending, '2026-05-22T00:00:00+00:00'));
        $this->assertFalse($this->service->isEligibleForCleanup($undated, '2026-05-22T00:00:00+00:00'));
    }//end testCleanupSkipsNonSynced()

    /**
     * Operations are ordered ascending by queuedAt, undated last.
     *
     * @return void
     */
    public function testOrderByQueuedAt(): void
    {
        $ops = [
            ['id' => 'c', 'queuedAt' => '2026-05-22T09:30:00+00:00'],
            ['id' => 'x'],
            ['id' => 'a', 'queuedAt' => '2026-05-22T09:00:00+00:00'],
            ['id' => 'b', 'queuedAt' => '2026-05-22T09:15:00+00:00'],
        ];

        $ordered = $this->service->orderByQueuedAt($ops);
        $ids     = array_column($ordered, 'id');

        $this->assertSame(['a', 'b', 'c', 'x'], $ids);
    }//end testOrderByQueuedAt()
}//end class
