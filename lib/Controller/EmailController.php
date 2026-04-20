<?php

/**
 * Procest Email Controller
 *
 * REST API for case email integration.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\CaseEmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CaseEmailService $emailService,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Send an email from case context.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Send result
     *
     * @NoAdminRequired
     */
    public function send(string $caseId): JSONResponse
    {
        try {
            $data   = json_decode($this->request->getContent() ?: '{}', true) ?: [];
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
        }
    }//end send()

    /**
     * Send email using a template.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Send result
     *
     * @NoAdminRequired
     */
    public function sendFromTemplate(string $caseId): JSONResponse
    {
        try {
            $data   = json_decode($this->request->getContent() ?: '{}', true) ?: [];
            $result = $this->emailService->sendFromTemplate(
                $caseId,
                $data['templateId'] ?? '',
                $data['to'] ?? '',
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end sendFromTemplate()

    /**
     * Preview a template with case data.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Resolved template preview
     *
     * @NoAdminRequired
     */
    public function preview(string $caseId): JSONResponse
    {
        $data     = json_decode($this->request->getContent() ?: '{}', true) ?: [];
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
     */
    public function templates(string $caseTypeId): JSONResponse
    {
        $templates = $this->emailService->getTemplatesForCaseType($caseTypeId);
        return new JSONResponse(['results' => $templates]);
    }//end templates()
}//end class
