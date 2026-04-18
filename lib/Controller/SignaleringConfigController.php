<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * API Controller for signalering (alerting) configuration.
 *
 * Manages per-zaaktype deadline alert thresholds and notification channel preferences.
 *
 * @spec openspec/changes/signalering-widgets/tasks.md#T05
 */
class SignaleringConfigController extends Controller
{
    public function __construct(
        IRequest $request,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * List all signalering configurations.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T05
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        // Admin only
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        try {
            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['configs' => []]);
            }

            // Get all signalering configurations from OpenRegister
            $register = $this->getRegisterValue();
            $schema = $this->getSchemaValue('signalering_config_schema');

            if ($register === null || $schema === null) {
                return new JSONResponse(['configs' => []]);
            }

            $result = $objectService->getObjects(
                (int) $register,
                (int) $schema,
                [],
            );

            $configs = $result['objects'] ?? [];

            return new JSONResponse(['configs' => $configs]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Error listing signalering configs', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create or update a signalering configuration for a zaaktype.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T05
     *
     * @return JSONResponse
     */
    public function create(): JSONResponse
    {
        // Admin only
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        try {
            $zaaktypeId = $this->request->getParam('zaaktypeId');
            if (empty($zaaktypeId)) {
                return new JSONResponse(['error' => 'zaaktypeId is required'], 400);
            }

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister is not available'], 503);
            }

            $register = $this->getRegisterValue();
            $schema = $this->getSchemaValue('signalering_config_schema');

            if ($register === null || $schema === null) {
                return new JSONResponse(['error' => 'Configuration not available'], 503);
            }

            $configData = [
                'zaaktypeId' => $zaaktypeId,
                'warningDaysStreef' => (int) $this->request->getParam('warningDaysStreef', '7'),
                'warningDaysFatale' => (int) $this->request->getParam('warningDaysFatale', '0'),
                'notificationChannels' => $this->request->getParam('notificationChannels', ['in-app']),
                'enabled' => (bool) $this->request->getParam('enabled', true),
            ];

            $result = $objectService->saveObject(
                (int) $register,
                (int) $schema,
                $configData,
            );

            $this->logger->info('Procest: Signalering config created/updated', [
                'zaaktypeId' => $zaaktypeId,
            ]);

            return new JSONResponse($result->jsonSerialize());
        } catch (\Exception $e) {
            $this->logger->error('Procest: Error creating signalering config', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a signalering configuration for a zaaktype.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T05
     *
     * @param string $zaaktypeId The zaaktype ID
     * @return JSONResponse
     */
    public function delete(string $zaaktypeId): JSONResponse
    {
        // Admin only
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        try {
            if (empty($zaaktypeId)) {
                return new JSONResponse(['error' => 'zaaktypeId is required'], 400);
            }

            $objectService = $this->getObjectService();
            if ($objectService === null) {
                return new JSONResponse(['error' => 'OpenRegister is not available'], 503);
            }

            $register = $this->getRegisterValue();
            $schema = $this->getSchemaValue('signalering_config_schema');

            if ($register === null || $schema === null) {
                return new JSONResponse(['error' => 'Configuration not available'], 503);
            }

            // Find and delete the configuration
            $result = $objectService->getObjects(
                (int) $register,
                (int) $schema,
                ['zaaktypeId' => $zaaktypeId],
            );

            $configs = $result['objects'] ?? [];
            if (empty($configs)) {
                return new JSONResponse(['error' => 'Configuration not found'], 404);
            }

            $config = reset($configs);
            $configId = is_object($config) ? $config->getId() : ($config['id'] ?? $config['uuid'] ?? null);

            if ($configId === null) {
                return new JSONResponse(['error' => 'Could not determine config ID'], 500);
            }

            // Delete via object ID
            $objectService->deleteObject(
                (int) $register,
                (int) $schema,
                (string) $configId,
            );

            $this->logger->info('Procest: Signalering config deleted', [
                'zaaktypeId' => $zaaktypeId,
            ]);

            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Error deleting signalering config', [
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
