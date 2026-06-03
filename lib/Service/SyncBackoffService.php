<?php

/**
 * Procest Sync Backoff Service.
 *
 * Pure-logic helper for the offline sync-queue replay engine. Computes the
 * exponential-backoff delay schedule (1s, 5s, 30s, 5min, 30min) with optional
 * jitter, decides the next status of a queued operation after an attempt, and
 * determines which synced operations are eligible for the 7-day cleanup.
 *
 * Contains no I/O and no OpenRegister calls so it is fully unit-testable; the
 * OR-backed replay orchestration lives in SyncQueueReplayService.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

/**
 * Stateless backoff and status-transition logic for the sync queue.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
 *
 * @psalm-suppress UnusedClass
 */
class SyncBackoffService
{
    /**
     * Status: operation queued, not yet attempted or awaiting next attempt.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Status: operation currently being replayed.
     */
    public const STATUS_SYNCING = 'syncing';

    /**
     * Status: operation replayed successfully.
     */
    public const STATUS_SYNCED = 'synced';

    /**
     * Status: operation hit a conflict (409) awaiting resolution.
     */
    public const STATUS_CONFLICT = 'conflict';

    /**
     * Status: operation exhausted all retries and needs manual review.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Backoff schedule in seconds: 1s, 5s, 30s, 5min, 30min.
     *
     * The array index is the attempt number (zero-based). After the last
     * entry is exhausted the operation is moved to STATUS_FAILED.
     *
     * @var array<int, int>
     */
    private const BACKOFF_SCHEDULE = [1, 5, 30, 300, 1800];

    /**
     * Number of days a synced operation is retained before cleanup.
     */
    private const SYNCED_RETENTION_DAYS = 7;

    /**
     * Compute the backoff delay (in seconds) before the given attempt.
     *
     * Attempt 0 is the first retry after the initial failure. Jitter, when
     * enabled, adds a deterministic-bounded random fraction (0..25%) of the
     * base delay to avoid thundering-herd reconnection storms.
     *
     * @param int  $attemptCount Number of attempts already made (>= 0).
     * @param bool $jitter       Whether to apply jitter.
     *
     * @return int Delay in seconds, or -1 when the schedule is exhausted.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function delayForAttempt(int $attemptCount, bool $jitter=false): int
    {
        if ($attemptCount < 0) {
            $attemptCount = 0;
        }

        if ($attemptCount >= count(self::BACKOFF_SCHEDULE)) {
            return -1;
        }

        $base = self::BACKOFF_SCHEDULE[$attemptCount];

        if ($jitter === false) {
            return $base;
        }

        // Bounded jitter: up to 25% of the base delay.
        $maxJitter = (int) floor($base * 0.25);
        if ($maxJitter < 1) {
            return $base;
        }

        return ($base + random_int(0, $maxJitter));
    }//end delayForAttempt()

    /**
     * Whether the operation still has retries left.
     *
     * @param int $attemptCount Number of attempts already made.
     *
     * @return bool True when another retry is permitted.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function hasRetriesRemaining(int $attemptCount): bool
    {
        return $attemptCount < count(self::BACKOFF_SCHEDULE);
    }//end hasRetriesRemaining()

    /**
     * Maximum number of retry attempts before an operation is failed.
     *
     * @return int The retry ceiling.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function maxAttempts(): int
    {
        return count(self::BACKOFF_SCHEDULE);
    }//end maxAttempts()

    /**
     * Apply the outcome of a single replay attempt to a queue operation.
     *
     * Returns a new operation array with updated status, attemptCount,
     * lastAttemptAt and lastError. The caller persists the result; this
     * method performs no I/O.
     *
     * @param array<string, mixed> $operation The current queue operation.
     * @param string               $outcome   One of: success, conflict,
     *                                        permission_lost, transient_error.
     * @param string|null          $error     Optional error detail for logging.
     * @param string|null          $now       ISO-8601 timestamp (defaults to now).
     *
     * @return array<string, mixed> The updated operation.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function applyAttemptOutcome(
        array $operation,
        string $outcome,
        ?string $error=null,
        ?string $now=null
    ): array {
        $timestamp = ($now ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $attempts  = ((int) ($operation['attemptCount'] ?? 0));

        $operation['lastAttemptAt'] = $timestamp;

        switch ($outcome) {
            case 'success':
                $operation['status']    = self::STATUS_SYNCED;
                $operation['syncedAt']  = $timestamp;
                $operation['lastError'] = null;
                break;

            case 'conflict':
                // Conflicts are not retried automatically; they await user
                // resolution, so the attempt count is not consumed.
                $operation['status']    = self::STATUS_CONFLICT;
                $operation['lastError'] = ($error ?? '409 Conflict');
                break;

            case 'permission_lost':
                // Permission loss is terminal and never retried.
                $operation['status']    = self::STATUS_FAILED;
                $operation['lastError'] = ($error ?? '403 Forbidden');
                break;

            case 'transient_error':
            default:
                $attempts++;
                $operation['attemptCount'] = $attempts;
                $operation['lastError']    = ($error ?? 'Transient error');
                $operation['status']       = self::STATUS_FAILED;
                if ($this->hasRetriesRemaining(attemptCount: $attempts) === true) {
                    $operation['status'] = self::STATUS_PENDING;
                }
                break;
        }//end switch

        return $operation;
    }//end applyAttemptOutcome()

    /**
     * Determine whether a synced operation is eligible for cleanup.
     *
     * Synced operations older than the 7-day retention window may be deleted.
     *
     * @param array<string, mixed> $operation The queue operation.
     * @param string|null          $now       ISO-8601 timestamp (defaults to now).
     *
     * @return bool True when the operation may be cleaned up.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function isEligibleForCleanup(array $operation, ?string $now=null): bool
    {
        if (($operation['status'] ?? '') !== self::STATUS_SYNCED) {
            return false;
        }

        $syncedAt = ($operation['syncedAt'] ?? null);
        if (is_string($syncedAt) === false || $syncedAt === '') {
            return false;
        }

        try {
            $syncedTime = new \DateTimeImmutable($syncedAt);
            $reference  = new \DateTimeImmutable($now ?? 'now');
        } catch (\Exception $e) {
            return false;
        }

        $ageSeconds = ($reference->getTimestamp() - $syncedTime->getTimestamp());

        return $ageSeconds >= (self::SYNCED_RETENTION_DAYS * 86400);
    }//end isEligibleForCleanup()

    /**
     * Sort queue operations ascending by their queuedAt timestamp.
     *
     * Operations without a queuedAt sort last (stable behaviour for
     * malformed records). This is the canonical replay order.
     *
     * @param array<int, array<string, mixed>> $operations The operations to order.
     *
     * @return array<int, array<string, mixed>> Operations ordered by queuedAt.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function orderByQueuedAt(array $operations): array
    {
        usort(
            $operations,
            static function (array $a, array $b): int {
                $aQueued = ($a['queuedAt'] ?? '');
                $bQueued = ($b['queuedAt'] ?? '');
                if ($aQueued === '' && $bQueued === '') {
                    return 0;
                }

                if ($aQueued === '') {
                    return 1;
                }

                if ($bQueued === '') {
                    return -1;
                }

                return ($aQueued <=> $bQueued);
            }
        );

        return array_values($operations);
    }//end orderByQueuedAt()
}//end class
