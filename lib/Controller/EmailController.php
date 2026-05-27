<?php

/**
 * Procest Email Controller
 *
 * REST API for case email integration.
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\CaseEmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for case email operations.
 */
class EmailController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName      The app name
     * @param IRequest         $request      The request
     * @param CaseEmailService $emailService The email service
     * @param IUserSession     $userSession  The user session
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CaseEmailService $emailService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Send an email from case context.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Send result
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function send(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) === true) {
                $data = $decoded;
            } else {
                $data = [];
            }

            $result = $this->emailService->sendEmail(
                $caseId,
                $data['to'] ?? '',
                $data['subject'] ?? '',
                $data['body'] ?? '',
                $data['attachments'] ?? [],
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end send()

    /**
     * Send email using a template.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Send result
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function sendFromTemplate(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $content = $this->request->getContent();
            if ($content === '' || $content === false) {
                $content = '{}';
            }

            $decoded = json_decode($content, true);
            if (is_array($decoded) === true) {
                $data = $decoded;
            } else {
                $data = [];
            }

            $result = $this->emailService->sendFromTemplate(
                $caseId,
                $data['templateId'] ?? '',
                $data['to'] ?? '',
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end sendFromTemplate()

    /**
     * Preview a template with case data.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Resolved template preview
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function preview(string $caseId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            $content = '{}';
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            $data = $decoded;
        } else {
            $data = [];
        }

        $template = $data['body'] ?? '';
        $caseData = [];
        // Would load from case.
        $resolved   = $this->emailService->resolveVariables($template, $caseData);
        $unresolved = $this->emailService->findUnresolvedVariables($template, $caseData);

        return new JSONResponse(
                [
                    'resolved'   => $resolved,
                    'unresolved' => $unresolved,
                ]
                );
    }//end preview()

    /**
     * Get email templates for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return JSONResponse List of templates
     *
     * @NoAdminRequired

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function templates(string $caseTypeId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $templates = $this->emailService->getTemplatesForCaseType($caseTypeId);
        return new JSONResponse(['results' => $templates]);
    }//end templates()
}//end class
