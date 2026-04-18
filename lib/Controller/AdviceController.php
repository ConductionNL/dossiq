<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\AdviceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice request REST endpoints.
 *
 * @spec openspec/changes/advice-management/tasks.md#task-4
 */
class AdviceController extends Controller
{
    public function __construct(
        IRequest $request,
        private AdviceService $adviceService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * List advice requests for a case.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function index(): JSONResponse
    {
        $caseId = $this->request->getParam('case');
        if (empty($caseId)) {
            return new JSONResponse(
                ['error' => 'case parameter is required'],
                400
            );
        }

        $advice = $this->adviceService->getAdviceForCase($caseId);
        return new JSONResponse(['success' => true, 'data' => $advice]);
    }

    /**
     * Create a new advice request.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function create(): JSONResponse
    {
        $caseId = $this->request->getParam('case');
        if (empty($caseId)) {
            return new JSONResponse(
                ['error' => 'case is required'],
                400
            );
        }

        $data = [
            'adviseur' => $this->request->getParam('adviseur'),
            'type' => $this->request->getParam('type'),
            'onderwerp' => $this->request->getParam('onderwerp'),
            'deadline' => $this->request->getParam('deadline'),
            'questions' => $this->request->getParam('questions'),
        ];

        $result = $this->adviceService->createAdvice($caseId, $data);

        if (isset($result['error'])) {
            return new JSONResponse(
                ['error' => $result['error']],
                400
            );
        }

        return new JSONResponse(['success' => true, 'data' => $result]);
    }

    /**
     * Get a single advice request.
     *
     * @NoAdminRequired
     *
     * @param string $id The advice request UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = \OCP\Server::get('OCA\OpenRegister\Service\ObjectService');
            $settingsService = \OCP\Server::get('OCA\Procest\Service\SettingsService');

            $register = (string) $settingsService->getConfigValue('register');
            $schema = (string) $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 500);
            }

            $advice = $objectService->findObject($register, $schema, $id);
            if ($advice === null) {
                return new JSONResponse(['error' => 'Advice request not found'], 404);
            }

            $adviceData = is_object($advice) ? $advice->jsonSerialize() : $advice;
            return new JSONResponse(['success' => true, 'data' => $adviceData]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: Failed to get advice', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to retrieve advice request'],
                500
            );
        }
    }

    /**
     * Update an advice request (mark as received).
     *
     * @NoAdminRequired
     *
     * @param string $id The advice request UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function update(string $id): JSONResponse
    {
        $fileId = $this->request->getParam('adviesDocument');

        $result = $this->adviceService->receiveAdvice($id, $fileId ?? '');

        if (isset($result['error'])) {
            return new JSONResponse(
                ['error' => $result['error']],
                400
            );
        }

        return new JSONResponse(['success' => true, 'data' => $result]);
    }

    /**
     * Delete an advice request.
     *
     * @NoAdminRequired
     *
     * @param string $id The advice request UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $objectService = \OCP\Server::get('OCA\OpenRegister\Service\ObjectService');
            $settingsService = \OCP\Server::get('OCA\Procest\Service\SettingsService');

            $register = (string) $settingsService->getConfigValue('register');
            $schema = (string) $settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return new JSONResponse(['error' => 'Advice schema not configured'], 500);
            }

            $objectService->deleteObject($register, $schema, $id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: Failed to delete advice', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => 'Failed to delete advice request'],
                500
            );
        }
    }

    /**
     * Send a reminder for an advice request.
     *
     * @NoAdminRequired
     *
     * @param string $id The advice request UUID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/advice-management/tasks.md#task-4
     */
    public function remind(string $id): JSONResponse
    {
        $this->adviceService->sendReminder($id);
        return new JSONResponse(['success' => true]);
    }
}
