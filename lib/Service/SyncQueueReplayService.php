<?php

/**
 * Procest Sync Queue Replay Service.
 *
 * Server-side orchestration for the offline sync queue. Loads pending
 * syncQueue operations for a device (scoped to the owning inspector), orders
 * them by queuedAt, and exposes the replay outcome handling that updates each
 * operation's status via SyncBackoffService. The HTTP replay against
 * OpenRegister and conflict detection are delegated to the caller / the
 * SyncController so this service stays testable and free of network I/O for
 * the deterministic ordering and persistence logic.
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

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads, orders and persists offline sync-queue operations.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
 *
 * @psalm-suppress UnusedClass
 */
class SyncQueueReplayService
{
    /**
     * The syncQueue schema slug in OpenRegister.
     */
    private const SCHEMA_SYNC_QUEUE = 'syncQueue';

    /**
     * Constructor.
     *
     * @param SettingsService    $settingsService Bridge to OpenRegister.
     * @param SyncBackoffService $backoffService  Backoff and status logic.
     * @param LoggerInterface    $logger          Logger.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SyncBackoffService $backoffService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List pending sync-queue operations for a device, ordered by queuedAt.
     *
     * Only operations whose deviceId matches AND whose owning inspector is the
     * requesting user are returned, preventing one inspector from replaying or
     * inspecting another's queue (IDOR-safe).
     *
     * @param string $deviceId      The requesting device.
     * @param string $inspectorId   The requesting inspector's user UID.
     * @param bool   $includeFailed Whether to include failed operations.
     *
     * @return array<int, array<string, mixed>> Ordered pending operations.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function listPending(string $deviceId, string $inspectorId, bool $includeFailed=false): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $statuses = [SyncBackoffService::STATUS_PENDING, SyncBackoffService::STATUS_CONFLICT];
        if ($includeFailed === true) {
            $statuses[] = SyncBackoffService::STATUS_FAILED;
        }

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $register,
                        'schema'   => self::SCHEMA_SYNC_QUEUE,
                        'deviceId' => $deviceId,
                    ],
                    'limit'   => 500,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to list sync queue for device: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $operations = $this->normalizeResults(results: $results);

        // IDOR guard: drop operations not owned by this inspector and filter
        // to the requested statuses.
        $operations = array_filter(
            $operations,
            static function (array $op) use ($inspectorId, $statuses): bool {
                $owner = ((string) ($op['inspectorRef'] ?? ''));
                if ($owner !== '' && $owner !== $inspectorId) {
                    return false;
                }

                return in_array(($op['status'] ?? ''), $statuses, true);
            }
        );

        return $this->backoffService->orderByQueuedAt(array_values($operations));
    }//end listPending()

    /**
     * Persist the outcome of a single replay attempt.
     *
     * The caller performs the HTTP replay and passes the resulting outcome
     * ('success', 'conflict', 'permission_lost', 'transient_error'); this
     * method applies the status transition and saves the updated operation.
     *
     * @param array<string, mixed> $operation The queue operation.
     * @param string               $outcome   The replay outcome.
     * @param string|null          $error     Optional error detail.
     *
     * @return array<string, mixed> The updated, persisted operation.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function recordOutcome(array $operation, string $outcome, ?string $error=null): array
    {
        $updated = $this->backoffService->applyAttemptOutcome($operation, $outcome, $error);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $updated;
        }

        $register = $this->settingsService->getConfigValue('register');

        try {
            $saved = $objectService->saveObject(
                register: $register,
                schema: self::SCHEMA_SYNC_QUEUE,
                object: $updated,
            );
            if (is_array($saved) === true) {
                return $saved;
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to persist sync queue outcome: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }

        return $updated;
    }//end recordOutcome()

    /**
     * Delete synced operations older than the 7-day retention window.
     *
     * @param string $deviceId    The device whose queue to clean.
     * @param string $inspectorId The owning inspector (IDOR guard).
     *
     * @return int The number of operations deleted.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    public function cleanupSynced(string $deviceId, string $inspectorId): int
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return 0;
        }

        $register = $this->settingsService->getConfigValue('register');

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $register,
                        'schema'   => self::SCHEMA_SYNC_QUEUE,
                        'deviceId' => $deviceId,
                        'status'   => SyncBackoffService::STATUS_SYNCED,
                    ],
                    'limit'   => 500,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to query synced operations for cleanup: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return 0;
        }

        $deleted = 0;
        foreach ($this->normalizeResults(results: $results) as $operation) {
            $owner = ((string) ($operation['inspectorRef'] ?? ''));
            if ($owner !== '' && $owner !== $inspectorId) {
                continue;
            }

            if ($this->backoffService->isEligibleForCleanup($operation) === false) {
                continue;
            }

            $id = ((string) ($operation['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            try {
                $objectService->deleteObject(
                    register: $register,
                    schema: self::SCHEMA_SYNC_QUEUE,
                    id: $id,
                );
                $deleted++;
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to delete synced operation: '.$e->getMessage(),
                    ['app' => Application::APP_ID]
                );
            }
        }//end foreach

        return $deleted;
    }//end cleanupSynced()

    /**
     * Normalize an OpenRegister findAll result into a flat list of arrays.
     *
     * Handles both the bare-list and paginated ('results') shapes returned by
     * different OpenRegister versions, and object entities.
     *
     * @param mixed $results The raw findAll result.
     *
     * @return array<int, array<string, mixed>> The normalized operations.
     */
    private function normalizeResults(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        if (isset($results['results']) === true && is_array($results['results']) === true) {
            $results = $results['results'];
        }

        $operations = [];
        foreach ($results as $entry) {
            if (is_array($entry) === true) {
                $operations[] = $entry;
            } else if (is_object($entry) === true) {
                if (method_exists($entry, 'jsonSerialize') === true) {
                    $serialized = $entry->jsonSerialize();
                    if (is_array($serialized) === true) {
                        $operations[] = $serialized;
                        continue;
                    }
                }

                $operations[] = get_object_vars($entry);
            }
        }

        return $operations;
    }//end normalizeResults()
}//end class
