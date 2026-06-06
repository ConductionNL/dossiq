<?php

/**
 * Procest Sync Controller.
 *
 * REST endpoints for the offline mobiel-inspectie PWA: the daily-planning
 * pre-download payload, listing the device's pending sync-queue operations,
 * recording the outcome of a replayed operation, and submitting a conflict
 * resolution choice.
 *
 * Every endpoint is #[NoAdminRequired] (normal field inspectors) and IDOR-safe:
 * each request is scoped to the authenticated user and their own device queue;
 * an inspector can never read or mutate another inspector's queue.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-17
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ConflictDetectionService;
use OCA\Procest\Service\DailySyncService;
use OCA\Procest\Service\SyncQueueReplayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for offline sync-queue replay and conflict resolution.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-17
 *
 * @psalm-suppress UnusedClass
 */
class SyncController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                   $appName          The app name.
     * @param IRequest                 $request          The request.
     * @param DailySyncService         $dailySyncService Daily-planning payload builder.
     * @param SyncQueueReplayService   $replayService    Sync-queue orchestration.
     * @param ConflictDetectionService $conflictService  Conflict classification/resolution.
     * @param IUserSession             $userSession      The user session.
     * @param LoggerInterface          $logger           Logger.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-17
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DailySyncService $dailySyncService,
        private readonly SyncQueueReplayService $replayService,
        private readonly ConflictDetectionService $conflictService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return the offline daily-planning payload for the current inspector.
     *
     * @return JSONResponse The daily sync payload (cases, checklists, manifest).
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
     */
    #[NoAdminRequired]
    public function daily(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $date = (string) ($this->request->getParam(key: 'date') ?? '');
            if ($date === '') {
                $date = null;
            }

            $payload = $this->dailySyncService->getDailyPayload(
                inspectorId: $user->getUID(),
                date: $date
            );
            return new JSONResponse(data: $payload, statusCode: Http::STATUS_OK);
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to build daily sync payload: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['error' => 'Failed to build daily sync payload'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end daily()

    /**
     * List the current inspector's pending sync-queue operations for a device.
     *
     * @return JSONResponse The ordered pending operations.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    #[NoAdminRequired]
    public function queue(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $deviceId = (string) ($this->request->getParam(key: 'deviceId') ?? '');
        if ($deviceId === '') {
            return new JSONResponse(['error' => 'deviceId is required'], Http::STATUS_BAD_REQUEST);
        }

        $includeFailed = ($this->request->getParam(key: 'includeFailed') === 'true');

        try {
            $operations = $this->replayService->listPending(
                deviceId: $deviceId,
                inspectorId: $user->getUID(),
                includeFailed: $includeFailed
            );
            return new JSONResponse(
                data: ['results' => $operations, 'total' => count($operations)],
                statusCode: Http::STATUS_OK
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to list sync queue: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['error' => 'Failed to list sync queue'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end queue()

    /**
     * Record the outcome of a replayed sync-queue operation.
     *
     * The PWA replays the operation against OpenRegister and reports the HTTP
     * status code; this endpoint classifies it and applies the resulting
     * status transition (synced / pending-retry / conflict / failed).
     *
     * @param string $id The sync-queue operation id.
     *
     * @return JSONResponse The updated operation, with conflict info when applicable.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    #[NoAdminRequired]
    public function recordOutcome(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $deviceId = (string) ($this->request->getParam(key: 'deviceId') ?? '');
        if ($deviceId === '') {
            return new JSONResponse(['error' => 'deviceId is required'], Http::STATUS_BAD_REQUEST);
        }

        // Resolve the operation from the inspector's own queue (IDOR guard):
        // an operation not present in the user's queue is treated as not found.
        $operation = $this->resolveOwnedOperation(
            id: $id,
            deviceId: $deviceId,
            inspectorId: $user->getUID()
        );
        if ($operation === null) {
            return new JSONResponse(['error' => 'Operation not found'], Http::STATUS_NOT_FOUND);
        }

        $statusCode   = (int) ($this->request->getParam(key: 'statusCode') ?? 0);
        $serverObject = $this->request->getParam(key: 'serverObject');
        if (is_array($serverObject) === false) {
            $serverObject = null;
        }

        $conflictType = $this->conflictService->classify(
            statusCode: $statusCode,
            serverObject: $serverObject
        );
        $outcome      = $this->outcomeForStatus(statusCode: $statusCode, conflictType: $conflictType);

        $error = null;
        if ($outcome === 'transient_error') {
            $error = $statusCode.' error';
        }

        try {
            $updated = $this->replayService->recordOutcome(
                operation: $operation,
                outcome: $outcome,
                error: $error
            );

            $response = ['operation' => $updated];
            if ($conflictType !== null) {
                $response['conflict'] = $this->conflictService->buildConflictRecord(
                    syncQueueRef: $id,
                    conflictType: $conflictType,
                    clientVersion: (array) ($operation['payload'] ?? []),
                    serverVersion: $serverObject
                );
            }

            return new JSONResponse(data: $response, statusCode: Http::STATUS_OK);
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to record sync outcome: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['error' => 'Failed to record sync outcome'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end recordOutcome()

    /**
     * Resolve a conflict on a sync-queue operation.
     *
     * @param string $id The sync-queue operation id whose conflict to resolve.
     *
     * @return JSONResponse The resolved operation status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-14
     */
    #[NoAdminRequired]
    public function resolveConflict(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $deviceId = (string) ($this->request->getParam(key: 'deviceId') ?? '');
        if ($deviceId === '') {
            return new JSONResponse(['error' => 'deviceId is required'], Http::STATUS_BAD_REQUEST);
        }

        $operation = $this->resolveOwnedOperation(
            id: $id,
            deviceId: $deviceId,
            inspectorId: $user->getUID(),
            includeFailed: true
        );
        if ($operation === null) {
            return new JSONResponse(['error' => 'Operation not found'], Http::STATUS_NOT_FOUND);
        }

        $resolution = (string) ($this->request->getParam(key: 'resolution') ?? '');

        try {
            $conflictRecord = $this->conflictService->applyResolution(
                conflictRecord: ['syncQueueRef' => $id],
                resolution: $resolution,
                resolvedBy: $user->getUID()
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => 'Invalid resolution choice'], Http::STATUS_BAD_REQUEST);
        }

        // The server_wins choice discards the local change and marks the
        // operation synced; client_wins and manual_merge re-queue it for a
        // forced retry.
        $outcome = 'transient_error';
        if ($resolution === 'server_wins') {
            $outcome = 'success';
        }

        $error = null;
        if ($outcome === 'transient_error') {
            $error = 'Re-queued after conflict resolution';
        }

        try {
            $updated = $this->replayService->recordOutcome(
                operation: $operation,
                outcome: $outcome,
                error: $error
            );
            return new JSONResponse(
                data: ['operation' => $updated, 'resolution' => $conflictRecord],
                statusCode: Http::STATUS_OK
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to resolve conflict: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return new JSONResponse(
                ['error' => 'Failed to resolve conflict'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end resolveConflict()

    /**
     * Map a replayed HTTP status (and detected conflict) to a queue outcome.
     *
     * @param int         $statusCode   The HTTP status returned by the replay.
     * @param string|null $conflictType The detected conflict type, when any.
     *
     * @return string One of success/conflict/permission_lost/transient_error.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-12
     */
    private function outcomeForStatus(int $statusCode, ?string $conflictType): string
    {
        if ($conflictType === ConflictDetectionService::TYPE_PERMISSION_LOST) {
            return 'permission_lost';
        }

        if ($conflictType !== null) {
            return 'conflict';
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return 'success';
        }

        return 'transient_error';
    }//end outcomeForStatus()

    /**
     * Resolve an operation from the inspector's own queue by id.
     *
     * Returns null when the operation is not owned by the requesting inspector
     * or does not exist, enforcing the IDOR guard at the data layer.
     *
     * @param string $id            The operation id.
     * @param string $deviceId      The requesting device.
     * @param string $inspectorId   The requesting inspector.
     * @param bool   $includeFailed Whether to consider failed operations too.
     *
     * @return array<string, mixed>|null The owned operation, or null.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Toggles the ownership-lookup
     * scope between active-only and active-plus-failed; both call sites are
     * IDOR-guarded and the flag selects a single, well-defined query filter.
     */
    private function resolveOwnedOperation(
        string $id,
        string $deviceId,
        string $inspectorId,
        bool $includeFailed=false
    ): ?array {
        $operations = $this->replayService->listPending(
            deviceId: $deviceId,
            inspectorId: $inspectorId,
            includeFailed: $includeFailed
        );

        foreach ($operations as $operation) {
            if (((string) ($operation['id'] ?? '')) === $id) {
                return $operation;
            }
        }

        return null;
    }//end resolveOwnedOperation()
}//end class
