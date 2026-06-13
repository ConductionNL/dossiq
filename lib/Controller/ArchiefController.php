<?php

/**
 * Procest ArchiefController.
 *
 * REST surface for the archief-edepot handover: CRUD on retention
 * rules, trigger inspection, batch initiation, audit-log retrieval,
 * dashboard stats. Defers all business logic to the archief services
 * (ADR-022).
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
 * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\ArchivalBatchService;
use OCA\Procest\Service\RollbackManager;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Archief-edepot REST surface.
 *
 * @psalm-suppress UnusedClass
 */
class ArchiefController extends Controller
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param string          $appName  App id.
     * @param IRequest        $request  Request.
     * @param SettingsService $settings Settings.
     * @param LoggerInterface $logger   Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SettingsService $settings,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly ArchivalBatchService $batchService,
        private readonly RollbackManager $rollbackManager,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Per-object authorization guard for archival operations.
     *
     * Archival/e-Depot handover is a DIV / records-management duty. A caller
     * may only retry a failed trigger when they hold the configured archief
     * role group (defaulting to `admin`). Fails closed: returns a 403
     * JSONResponse when the session is anonymous or the user is not in the
     * archief role group, otherwise null.
     *
     * @return JSONResponse|null
     */
    private function ensureArchiefRole(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        $uid   = $user->getUID();
        $group = (string) $this->settings->getConfigValue('archief_role_group');
        if ($group === '') {
            $group = 'admin';
        }
        $authorised = $this->groupManager->isAdmin($uid)
            || $this->groupManager->isInGroup($uid, $group);
        if ($authorised === false) {
            return new JSONResponse(
                ['message' => 'Not authorised for archief operations'],
                Http::STATUS_FORBIDDEN
            );
        }
        return null;
    }//end ensureArchiefRole()

    /**
     * Retry a failed e-Depot handover after the case has been corrected.
     *
     * Re-bundles with the current case state and re-submits, retaining both
     * the old failed and the new transaction in the audit log. Security: this
     * is the first user-facing HTTP surface in the chain — it carries an
     * explicit `@NoAdminRequired` posture plus a per-trigger IDOR guard. The
     * caller MUST hold the archief role group ({@see self::ensureArchiefRole})
     * and the trigger MUST exist and be in status `gefaald`; an unknown
     * trigger returns 404 and any other status returns 409. Retry on an
     * arbitrary or out-of-state trigger is rejected (fail closed).
     *
     * @NoAdminRequired
     *
     * @param string $triggerId Trigger id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function retry(string $triggerId): JSONResponse
    {
        if (($denied = $this->ensureArchiefRole()) !== null) {
            return $denied;
        }
        if ($triggerId === '') {
            return new JSONResponse(['message' => 'triggerId is required'], Http::STATUS_BAD_REQUEST);
        }

        // IDOR guard: resolve the trigger first; reject unknown ids (404) and
        // reject any trigger that is not in the recoverable `gefaald` state
        // (409) before performing any side effect.
        $trigger = $this->rollbackManager->findTrigger($triggerId);
        if ($trigger === null) {
            return new JSONResponse(['message' => 'trigger not found'], Http::STATUS_NOT_FOUND);
        }
        if ((string) ($trigger['status'] ?? '') !== 'gefaald') {
            return new JSONResponse(
                ['message' => 'retry only allowed on triggers in status gefaald'],
                Http::STATUS_CONFLICT
            );
        }

        try {
            $result = $this->rollbackManager->retryAfterCorrection($triggerId);
        } catch (Throwable $e) {
            $this->logger->warning('Archief retry failed', ['triggerId' => $triggerId, 'error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Retry failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $status = $result['ok'] === true ? Http::STATUS_ACCEPTED : Http::STATUS_OK;
        return new JSONResponse($result, $status);
    }//end retry()

    /**
     * Per-object authorization guard.
     *
     * @return JSONResponse|null
     */
    private function ensureAuthenticated(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }//end ensureAuthenticated()

    /**
     * List retention rules.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
     */
    public function listRules(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        return new JSONResponse($this->fetchAll('bewaar_termijn_regel_schema'));
    }//end listRules()

    /**
     * Create a retention rule.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
     */
    public function createRule(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $body = $this->jsonBody();
        if ((string) ($body['zaaktypeKey'] ?? '') === '') {
            return new JSONResponse(['message' => 'zaaktypeKey is required'], Http::STATUS_BAD_REQUEST);
        }
        $jaren = (int) ($body['bewaartermijnJaren'] ?? 0);
        if ($jaren < 1) {
            return new JSONResponse(['message' => 'bewaartermijnJaren must be >= 1 or 9999 (permanent)'], Http::STATUS_BAD_REQUEST);
        }
        $body['isActive'] = $body['isActive'] ?? true;
        return new JSONResponse($this->saveOne('bewaar_termijn_regel_schema', $body), Http::STATUS_CREATED);
    }//end createRule()

    /**
     * Update a retention rule.
     *
     * @NoAdminRequired
     *
     * @param string $ruleId Rule id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
     */
    public function updateRule(string $ruleId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        $body = $this->jsonBody();
        $body['id'] = $ruleId;
        if (isset($body['bewaartermijnJaren']) === true) {
            $jaren = (int) $body['bewaartermijnJaren'];
            if ($jaren < 1) {
                return new JSONResponse(['message' => 'bewaartermijnJaren must be >= 1 or 9999 (permanent)'], Http::STATUS_BAD_REQUEST);
            }
        }
        if (isset($body['zaaktypeKey']) === true && (string) $body['zaaktypeKey'] === '') {
            return new JSONResponse(['message' => 'zaaktypeKey cannot be empty'], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($this->saveOne('bewaar_termijn_regel_schema', $body));
    }//end updateRule()

    /**
     * Delete a retention rule.
     *
     * @NoAdminRequired
     *
     * @param string $ruleId Rule id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
     */
    public function deleteRule(string $ruleId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        $objectService = $this->settings->getObjectService();
        $register      = (string) $this->settings->getConfigValue('register');
        $schema        = (string) $this->settings->getConfigValue('bewaar_termijn_regel_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return new JSONResponse(['message' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }
        try {
            if (method_exists($objectService, 'deleteObject') === true) {
                $objectService->deleteObject($register, $schema, $ruleId);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Archief deleteRule failed', ['ruleId' => $ruleId, 'error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Delete failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['success' => true]);
    }//end deleteRule()

    /**
     * Dashboard stats.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-08-admin-ui-docs/tasks.md
     */
    public function dashboardStats(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $triggers = $this->fetchAll('overdracht_trigger_schema');
        $stats = ['ready' => 0, 'inProgress' => 0, 'failed' => 0, 'completed' => 0, 'totalTransferred' => 0];
        foreach ($triggers as $t) {
            $s = (string) ($t['status'] ?? '');
            $stats['totalTransferred'] += ($s === 'geslaagd' ? 1 : 0);
            $stats['completed']       += ($s === 'geslaagd' ? 1 : 0);
            $stats['ready']           += ($s === 'gereed-voor-overdracht' ? 1 : 0);
            $stats['inProgress']      += (in_array($s, ['in-bundling', 'in-overdracht'], true) ? 1 : 0);
            $stats['failed']          += ($s === 'gefaald' ? 1 : 0);
        }
        return new JSONResponse($stats);
    }//end dashboardStats()

    /**
     * Get audit log entries.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function auditLog(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) { return $denied; }
        $zaakId = (string) $this->request->getParam('zaakId', '');
        $rows = $this->fetchAll('overdracht_audit_log_schema');
        if ($zaakId !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['zaakId'] ?? '') === $zaakId));
        }
        // Reverse-chronological.
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));
        return new JSONResponse($rows);
    }//end auditLog()

    /**
     * Initiate an archival batch run.
     *
     * Body: `{caseIds: string[], rateLimit?: int, eDepotId?: string, batchId?: string}`.
     * Delegates to {@see ArchivalBatchService::initiateBatch}; returns the
     * batch summary (state + counters) so the admin UI can render the
     * post-run dashboard tile without a follow-up audit-log scan.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function batchInitiate(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        $body    = $this->jsonBody();
        $caseIds = (array) ($body['caseIds'] ?? []);
        if ($caseIds === []) {
            return new JSONResponse(['message' => 'caseIds is required'], Http::STATUS_BAD_REQUEST);
        }
        $caseIds = array_values(array_map('strval', $caseIds));
        $rateLimit = (int) ($body['rateLimit'] ?? 4);
        if ($rateLimit < 1) {
            $rateLimit = 4;
        }
        $eDepotId = (string) ($body['eDepotId'] ?? '');
        $batchId  = isset($body['batchId']) === true ? (string) $body['batchId'] : null;
        try {
            $summary = $this->batchService->initiateBatch($caseIds, $rateLimit, $eDepotId, $batchId);
        } catch (Throwable $e) {
            $this->logger->warning('Archief batchInitiate failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Batch initiation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse($summary, Http::STATUS_ACCEPTED);
    }//end batchInitiate()

    /**
     * Replay batch state from the audit log.
     *
     * Returns `{batchId, state, counters, events: []}` reconstructed from
     * the append-only `overdrachtAuditLog` rows whose `details` string
     * carries `batchId=<jobId>`. The audit log is the source of truth per
     * the spec; this endpoint is a thin filtered projection.
     *
     * @NoAdminRequired
     *
     * @param string $jobId Batch id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function batchStatus(string $jobId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        if ($jobId === '') {
            return new JSONResponse(['message' => 'jobId is required'], Http::STATUS_BAD_REQUEST);
        }
        $events = $this->batchAuditEvents($jobId);
        $summary = $this->summariseBatchEvents($jobId, $events);
        if ($summary['events'] === 0) {
            return new JSONResponse(['message' => 'batch not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse($summary);
    }//end batchStatus()

    /**
     * Compose a batch report (JSON) from audit-log events + per-case bewijzen.
     *
     * Returns a flat report payload (no ZIP wrapper) so the admin UI can
     * stream it directly into a download. A ZIP wrapper remains a deferred
     * follow-up — the payload already carries every row a ZIP would.
     *
     * @NoAdminRequired
     *
     * @param string $jobId Batch id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function batchReport(string $jobId): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        if ($jobId === '') {
            return new JSONResponse(['message' => 'jobId is required'], Http::STATUS_BAD_REQUEST);
        }
        $events  = $this->batchAuditEvents($jobId);
        $summary = $this->summariseBatchEvents($jobId, $events);
        if ($summary['events'] === 0) {
            return new JSONResponse(['message' => 'batch not found'], Http::STATUS_NOT_FOUND);
        }
        // Attach bewijzen rows correlated by zaakId.
        $bewijzen   = $this->fetchAll('archief_bewijs_schema');
        $caseIds    = $summary['caseIds'];
        $caseIdMap  = array_flip($caseIds);
        $report     = [
            'batchId'    => $jobId,
            'state'      => $summary['state'],
            'counters'   => $summary['counters'],
            'cases'      => $caseIds,
            'events'     => $events,
            'bewijzen'   => array_values(array_filter(
                $bewijzen,
                static fn (array $row): bool => isset($caseIdMap[(string) ($row['zaakId'] ?? '')])
            )),
            'generatedAt' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];
        return new JSONResponse($report);
    }//end batchReport()

    /**
     * Inspection-year export.
     *
     * Delegates to {@see ArchivalBatchService::generateInspectionExport};
     * accepts `year=` (required) and optional `zaaktypeKey` / `archiefId`
     * query filters.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function inspectionExport(): JSONResponse
    {
        if (($denied = $this->ensureAuthenticated()) !== null) {
            return $denied;
        }
        $year = (int) $this->request->getParam('year', 0);
        if ($year < 1970 || $year > 9999) {
            return new JSONResponse(['message' => 'year is required (YYYY)'], Http::STATUS_BAD_REQUEST);
        }
        $filters = [];
        foreach (['zaaktypeKey', 'archiefId'] as $key) {
            $val = (string) $this->request->getParam($key, '');
            if ($val !== '') {
                $filters[$key] = $val;
            }
        }
        try {
            $payload = $this->batchService->generateInspectionExport($year, $filters);
        } catch (Throwable $e) {
            $this->logger->warning('Archief inspectionExport failed', ['error' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse($payload);
    }//end inspectionExport()

    /**
     * Pull audit-log rows whose details mention the batch id.
     *
     * @param string $jobId Batch id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function batchAuditEvents(string $jobId): array
    {
        $rows  = $this->fetchAll('overdracht_audit_log_schema');
        $token = 'batchId='.$jobId;
        $hits  = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_contains((string) ($row['details'] ?? ''), $token)
        ));
        usort($hits, static fn (array $a, array $b): int => strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? '')));
        return $hits;
    }

    /**
     * Reconstruct batch summary from audit events.
     *
     * @param string                            $jobId  Batch id.
     * @param array<int, array<string, mixed>>  $events Audit rows.
     *
     * @return array{
     *     batchId: string,
     *     state: string,
     *     counters: array<string, int>,
     *     caseIds: array<int, string>,
     *     events: int,
     *     timeline: array<int, array<string, mixed>>
     * }
     */
    private function summariseBatchEvents(string $jobId, array $events): array
    {
        $state    = 'unknown';
        $counters = ['succeeded' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0];
        $caseIds  = [];
        foreach ($events as $event) {
            $type    = (string) ($event['eventType'] ?? '');
            $details = (string) ($event['details'] ?? '');
            $zaakId  = (string) ($event['zaakId'] ?? '');
            if ($zaakId !== '' && in_array($zaakId, $caseIds, true) === false) {
                $caseIds[] = $zaakId;
            }
            if ($type === 'batch-initiated' && $state === 'unknown') {
                $state = 'processing';
            }
            if ($type === 'batch-completed') {
                foreach (['succeeded', 'failed', 'deferred', 'skipped'] as $bucket) {
                    if (preg_match('/'.$bucket.'=(\d+)/', $details, $m) === 1) {
                        $counters[$bucket] = (int) $m[1];
                    }
                }
                if (preg_match('/state=([a-z\-]+)/', $details, $m) === 1) {
                    $state = $m[1];
                }
            }
        }
        return [
            'batchId'  => $jobId,
            'state'    => $state,
            'counters' => $counters,
            'caseIds'  => $caseIds,
            'events'   => count($events),
            'timeline' => $events,
        ];
    }

    /**
     * @param string $schemaConfigKey Schema config key.
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $schemaConfigKey): array
    {
        $objectService = $this->settings->getObjectService();
        $register      = (string) $this->settings->getConfigValue('register');
        $schema        = (string) $this->settings->getConfigValue($schemaConfigKey);
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }
        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
            return is_array($rows) === true ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param string               $schemaConfigKey Schema config key.
     * @param array<string, mixed> $object          Payload.
     * @return array<string, mixed>
     */
    private function saveOne(string $schemaConfigKey, array $object): array
    {
        $objectService = $this->settings->getObjectService();
        $register      = (string) $this->settings->getConfigValue('register');
        $schema        = (string) $this->settings->getConfigValue($schemaConfigKey);
        if ($objectService === null || $register === '' || $schema === '') {
            return $object;
        }
        try {
            $saved = $objectService->saveObject($register, $schema, $object);
            return is_array($saved) === true ? $saved : $object;
        } catch (Throwable $e) {
            return $object;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        // OCP\IRequest::getContent() is protected on the concrete OC
        // request; read raw payload from php://input instead.
        $raw  = (string) file_get_contents('php://input');
        $body = json_decode($raw, true);
        return is_array($body) === true ? $body : [];
    }
}//end class
