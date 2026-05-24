<?php

/**
 * Procest AI Controller
 *
 * Controller for AI-assisted case processing endpoints.
 * Provides document classification, data extraction, knowledge base Q&A,
 * summarization, routing suggestions, and audit trail access.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\AiService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AI-assisted processing API controller.
 *
 * All endpoints require authenticated Nextcloud user.
 * AI features must be enabled in settings.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AiController extends Controller
{
    /**
     * Constructor for AiController.
     *
     * @param string          $appName         The application name
     * @param IRequest        $request         The request object
     * @param AiService       $aiService       The AI service
     * @param SettingsService $settingsService The settings service
     * @param IUserSession    $userSession     The user session
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private AiService $aiService,
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Classify a document using AI.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function classify(): JSONResponse
    {
        $caseId     = $this->request->getParam('caseId', '');
        $documentId = $this->request->getParam('documentId', '');

        if (empty($caseId) === true || empty($documentId) === true) {
            return new JSONResponse(
                ['error' => 'caseId and documentId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->classifyDocument($caseId, $documentId, $userId);

        return new JSONResponse($result);
    }//end classify()

    /**
     * Extract structured data from case documents.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function extract(): JSONResponse
    {
        $caseId     = $this->request->getParam('caseId', '');
        $documentId = $this->request->getParam('documentId');

        if (empty($caseId) === true) {
            return new JSONResponse(
                ['error' => 'caseId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->extractData($caseId, $documentId, $userId);

        return new JSONResponse($result);
    }//end extract()

    /**
     * Ask a knowledge base question in case context.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function ask(): JSONResponse
    {
        $caseId   = $this->request->getParam('caseId', '');
        $question = $this->request->getParam('question', '');

        if (empty($caseId) === true || empty($question) === true) {
            return new JSONResponse(
                ['error' => 'caseId and question are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->askQuestion($caseId, $question, $userId);

        return new JSONResponse($result);
    }//end ask()

    /**
     * Generate a summary for a case, document, or timeline.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function summarize(): JSONResponse
    {
        $caseId     = $this->request->getParam('caseId', '');
        $type       = $this->request->getParam('type', 'case');
        $documentId = $this->request->getParam('documentId');

        if (empty($caseId) === true) {
            return new JSONResponse(
                ['error' => 'caseId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $validTypes = ['case', 'document', 'timeline'];
        if (in_array($type, $validTypes, true) === false) {
            return new JSONResponse(
                ['error' => 'type must be one of: '.implode(', ', $validTypes)],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->summarize($caseId, $type, $documentId, $userId);

        return new JSONResponse($result);
    }//end summarize()

    /**
     * Get case routing suggestions.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function suggestRouting(): JSONResponse
    {
        $caseId = $this->request->getParam('caseId', '');

        if (empty($caseId) === true) {
            return new JSONResponse(
                ['error' => 'caseId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->suggestRouting($caseId, $userId);

        return new JSONResponse($result);
    }//end suggestRouting()

    /**
     * Get next-step suggestions for a case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function suggestNext(): JSONResponse
    {
        $caseId = $this->request->getParam('caseId', '');

        if (empty($caseId) === true) {
            return new JSONResponse(
                ['error' => 'caseId is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->suggestNextStep($caseId, $userId);

        return new JSONResponse($result);
    }//end suggestNext()

    /**
     * Record a user action on an AI suggestion.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function recordAction(): JSONResponse
    {
        $caseId     = $this->request->getParam('caseId', '');
        $type       = $this->request->getParam('type', '');
        $userAction = $this->request->getParam('userAction', '');
        $suggestion = $this->request->getParam('suggestion', []);
        $actual     = $this->request->getParam('actualValue');
        $reason     = $this->request->getParam('reason');

        if (empty($caseId) === true || empty($type) === true || empty($userAction) === true) {
            return new JSONResponse(
                ['error' => 'caseId, type, and userAction are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $userId = $this->getCurrentUserId();
        $result = $this->aiService->recordUserAction(
            $caseId,
            $type,
            $userAction,
            $suggestion,
            $actual,
            $reason,
            $userId,
        );

        return new JSONResponse($result);
    }//end recordAction()

    /**
     * Get AI audit trail entries.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     */
    public function auditIndex(): JSONResponse
    {
        $filters = [
            'caseId' => $this->request->getParam('caseId'),
            'type'   => $this->request->getParam('type'),
            'limit'  => (int) $this->request->getParam('limit', '50'),
            'offset' => (int) $this->request->getParam('offset', '0'),
        ];

        return new JSONResponse(
                [
                    'success' => true,
                    'filters' => array_filter($filters),
                    'message' => 'Audit trail query — implement with OpenRegister object listing',
                ]
                );
    }//end auditIndex()

    /**
     * Get AI settings.
     *
     * @return JSONResponse
     */
    public function getSettings(): JSONResponse
    {
        $settings = $this->aiService->getAiSettings();

        return new JSONResponse($settings);
    }//end getSettings()

    /**
     * Update AI settings.
     *
     * @return JSONResponse
     */
    public function updateSettings(): JSONResponse
    {
        $data   = $this->request->getParams();
        $result = $this->settingsService->updateSettings($data);

        return new JSONResponse($result);
    }//end updateSettings()

    /**
     * Test AI model health/connectivity.
     *
     * @return JSONResponse
     */
    public function healthCheck(): JSONResponse
    {
        $result = $this->aiService->testHealth();

        return new JSONResponse($result);
    }//end healthCheck()

    /**
     * Get the current user ID from the session.
     *
     * @return string
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();

        if ($user !== null) {
            return $user->getUID();
        }

        return 'anonymous';
    }//end getCurrentUserId()
}//end class
