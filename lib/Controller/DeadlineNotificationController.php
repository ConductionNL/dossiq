<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SignaleringService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * API Controller for deadline notifications and notifications.
 *
 * Provides endpoints for retrieving deadline status and handling n8n webhook callbacks.
 *
 * @spec openspec/changes/signalering-widgets/tasks.md#T06
 */
class DeadlineNotificationController extends Controller
{
    public function __construct(
        IRequest $request,
        private SignaleringService $signaleringService,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * Get deadline status for a specific case.
     *
     * Returns streeftermijn, fatale termijn, opschorting info, and overall status.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T06
     *
     * @param string $caseId The case UUID
     * @return JSONResponse
     */
    public function getDeadlines(string $caseId): JSONResponse
    {
        try {
            if (empty($caseId)) {
                return new JSONResponse(['error' => 'caseId is required'], 400);
            }

            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['error' => 'Not authenticated'], 401);
            }

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister is not available'], 503);
            }

            // Fetch case and case type
            $register = $this->getRegisterValue();
            $caseSchema = $this->getSchemaValue('case_schema');

            if ($register === null || $caseSchema === null) {
                return new JSONResponse(['error' => 'Configuration not available'], 503);
            }

            $case = $objectService->getObject((int) $register, (int) $caseSchema, $caseId);
            if ($case === null) {
                return new JSONResponse(['error' => 'Case not found'], 404);
            }

            $caseData = is_object($case) ? $case->jsonSerialize() : $case;

            // Get the case type
            $caseTypeSchema = $this->getSchemaValue('case_type_schema');
            $caseTypeId = $caseData['caseType'] ?? $caseData['zaaktype'] ?? null;

            if ($caseTypeId === null || $caseTypeSchema === null) {
                return new JSONResponse(['error' => 'Case type not found'], 404);
            }

            $caseType = $objectService->getObject((int) $register, (int) $caseTypeSchema, $caseTypeId);
            if ($caseType === null) {
                return new JSONResponse(['error' => 'Case type not found'], 404);
            }

            $caseTypeData = is_object($caseType) ? $caseType->jsonSerialize() : $caseType;

            // Calculate deadline status
            $deadlineStatus = $this->signaleringService->calculateDeadlineStatus($caseData, $caseTypeData);

            return new JSONResponse([
                'caseId' => $caseId,
                'zaaktypeId' => $caseTypeId,
                'streeftermijn' => $deadlineStatus['streeftermijn'],
                'fatalTermijn' => $deadlineStatus['fatalTermijn'],
                'opschorting' => $deadlineStatus['opschorting'],
                'overallStatus' => $deadlineStatus['overallStatus'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Error getting deadline status', [
                'caseId' => $caseId,
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle webhook callback from n8n for notification delivery confirmation.
     *
     * This endpoint is called by n8n after it sends an email notification,
     * allowing us to track notification state.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T06
     *
     * @return JSONResponse
     */
    public function notifyWebhook(): JSONResponse
    {
        try {
            // Get webhook payload from POST parameters
            $type = $this->request->getParam('type');
            $caseId = $this->request->getParam('caseId');
            $status = $this->request->getParam('status');

            if (empty($type) || empty($caseId)) {
                return new JSONResponse(['error' => 'Missing required fields'], 400);
            }

            // Log the notification event
            $this->logger->info('Procest: Notification webhook received', [
                'type' => $type,
                'caseId' => $caseId,
                'status' => $status ?? 'unknown',
            ]);

            // Here you could update the case notification history,
            // but for now we just acknowledge the webhook
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Error processing notification webhook', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get ObjectService from OpenRegister app.
     *
     * @return ?\OCA\OpenRegister\Service\ObjectService
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('Procest: Could not get ObjectService', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get the configured register ID.
     *
     * @return ?string
     */
    private function getRegisterValue(): ?string
    {
        try {
            $settingsService = $this->container->get('OCA\Procest\Service\SettingsService');
            return $settingsService->getConfigValue('register');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get a schema ID by config key.
     *
     * @param string $configKey The settings key
     * @return ?string
     */
    private function getSchemaValue(string $configKey): ?string
    {
        try {
            $settingsService = $this->container->get('OCA\Procest\Service\SettingsService');
            return $settingsService->getConfigValue($configKey);
        } catch (\Exception) {
            return null;
        }
    }
}
