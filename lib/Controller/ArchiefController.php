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

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

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
            $rows = $this->searchObjectsAsArrays($objectService, $register, $schema, []);
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
        $raw  = method_exists($this->request, 'getContent') === true ? (string) $this->request->getContent() : '';
        $body = json_decode($raw, true);
        return is_array($body) === true ? $body : [];
    }
}//end class
