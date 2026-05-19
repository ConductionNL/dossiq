<?php

/**
 * Procest Case Email Controller
 *
 * REST API for email composition, threading, inbound linking, template
 * management, and email settings within the case management workflow.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\EmailTemplateService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for case email operations: send, thread, template, settings.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T05
 */
class CaseEmailController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName         The app name
     * @param IRequest             $request         The HTTP request
     * @param CaseEmailService     $emailService    The email service
     * @param EmailTemplateService $templateService The template service
     * @param SettingsService      $settingsService The settings service
     * @param IUserSession         $userSession     The user session
     * @param IGroupManager        $groupManager    The group manager
     * @param LoggerInterface      $logger          The logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CaseEmailService $emailService,
        private readonly EmailTemplateService $templateService,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Send an outbound email from a specific case.
     *
     * Requires the caller to be a member of the case's team or an admin.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse The sent message result
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function sendEmail(string $caseId): JSONResponse
    {
        $data = $this->getRequestData();

        $to = (string) ($data['to'] ?? '');
        if ($to === '') {
            return new JSONResponse(['error' => 'Recipient (to) is required'], Http::STATUS_BAD_REQUEST);
        }

        $this->authorizeEmailAction(caseId: $caseId);

        $templateVersion = null;
        if (isset($data['templateVersion']) === true) {
            $templateVersion = (int) $data['templateVersion'];
        }

        try {
            $result = $this->emailService->sendEmail(
                caseId: $caseId,
                to: $to,
                subject: (string) ($data['subject'] ?? ''),
                body: (string) ($data['body'] ?? ''),
                cc: (array) ($data['cc'] ?? []),
                bcc: (array) ($data['bcc'] ?? []),
                attachments: (array) ($data['attachments'] ?? []),
                templateId: $data['templateId'] ?? null,
                templateVersion: $templateVersion,
                inReplyTo: $data['inReplyTo'] ?? null,
            );
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            $this->logger->warning(
                'Email send failed for case '.$caseId.': '.$e->getMessage(),
                ['app' => 'procest'],
            );
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try
    }//end sendEmail()

    /**
     * List all email threads and messages for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return JSONResponse Thread list with embedded messages
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function listEmails(string $caseId): JSONResponse
    {
        $this->authorizeEmailAction(caseId: $caseId);
        $result = $this->emailService->listEmailsForCase(caseId: $caseId);
        return new JSONResponse($result);
    }//end listEmails()

    /**
     * List inbound emails that could not be auto-linked to a case.
     *
     * @return JSONResponse Unlinked email queue
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function listUnlinked(): JSONResponse
    {
        $this->requireAdmin();
        $result = $this->emailService->listUnlinkedEmails();
        return new JSONResponse($result);
    }//end listUnlinked()

    /**
     * Manually link an unlinked inbound email to a specific case.
     *
     * @param string $id The unlinked email UUID
     *
     * @return JSONResponse Link result
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function linkEmail(string $id): JSONResponse
    {
        $this->requireAdmin();
        $data   = $this->getRequestData();
        $caseId = (string) ($data['caseId'] ?? '');

        if ($caseId === '') {
            return new JSONResponse(['error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->emailService->linkUnlinkedEmail(unlinkedId: $id, caseId: $caseId);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end linkEmail()

    /**
     * Discard an unlinked inbound email.
     *
     * @param string $id The unlinked email UUID
     *
     * @return JSONResponse Discard result
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function discardEmail(string $id): JSONResponse
    {
        $this->requireAdmin();

        try {
            $result = $this->emailService->discardUnlinkedEmail(unlinkedId: $id);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end discardEmail()

    /**
     * List active email templates for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return JSONResponse Template list
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function listTemplates(string $caseTypeId): JSONResponse
    {
        $templates = $this->templateService->listTemplates(caseTypeId: $caseTypeId);
        return new JSONResponse(['results' => $templates]);
    }//end listTemplates()

    /**
     * Create a new email template for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return JSONResponse The created template
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function createTemplate(string $caseTypeId): JSONResponse
    {
        $this->requireAdmin();
        $data = $this->getRequestData();

        try {
            $result = $this->templateService->createTemplate(caseTypeId: $caseTypeId, data: $data);
            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }//end createTemplate()

    /**
     * Update an email template (creates a new version object).
     *
     * @param string $templateId The template UUID
     *
     * @return JSONResponse The new template version
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function updateTemplate(string $templateId): JSONResponse
    {
        $this->requireAdmin();
        $data = $this->getRequestData();

        try {
            $result = $this->templateService->updateTemplate(templateId: $templateId, data: $data);
            return new JSONResponse($result);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }//end updateTemplate()

    /**
     * Get current email (SMTP/IMAP) settings.
     *
     * @return JSONResponse Current email configuration (passwords masked)
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function getEmailSettings(): JSONResponse
    {
        $this->requireAdmin();

        $keys     = [
            'email_smtp_host',
            'email_smtp_port',
            'email_smtp_user',
            'email_smtp_encryption',
            'email_imap_host',
            'email_imap_port',
            'email_imap_user',
            'email_transport',
            'email_from_address',
            'email_from_name',
            'email_poll_interval',
            'email_poll_batch_size',
            'email_max_attachment_size',
        ];
        $settings = [];
        foreach ($keys as $key) {
            $settings[$key] = $this->settingsService->getConfigValue(key: $key);
        }

        // Never expose passwords in responses.
        if ($this->settingsService->getConfigValue('email_smtp_password') !== '') {
            $settings['email_smtp_password'] = '***';
        } else {
            $settings['email_smtp_password'] = '';
        }

        if ($this->settingsService->getConfigValue('email_imap_password') !== '') {
            $settings['email_imap_password'] = '***';
        } else {
            $settings['email_imap_password'] = '';
        }

        return new JSONResponse($settings);
    }//end getEmailSettings()

    /**
     * Save email (SMTP/IMAP) settings.
     *
     * @return JSONResponse Updated settings
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function saveEmailSettings(): JSONResponse
    {
        $this->requireAdmin();
        $data = $this->getRequestData();

        $allowed = [
            'email_smtp_host',
            'email_smtp_port',
            'email_smtp_user',
            'email_smtp_password',
            'email_smtp_encryption',
            'email_imap_host',
            'email_imap_port',
            'email_imap_user',
            'email_imap_password',
            'email_transport',
            'email_from_address',
            'email_from_name',
            'email_poll_interval',
            'email_poll_batch_size',
            'email_max_attachment_size',
        ];

        foreach ($allowed as $key) {
            if (isset($data[$key]) === true) {
                $this->settingsService->setConfigValue(key: $key, value: (string) $data[$key]);
            }
        }

        return new JSONResponse(['saved' => true]);
    }//end saveEmailSettings()

    /**
     * Send a test email using the current SMTP settings.
     *
     * @return JSONResponse Test result with success flag and error message if any
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    #[NoAdminRequired]
    public function testSmtp(): JSONResponse
    {
        $this->requireAdmin();
        $data = $this->getRequestData();
        $to   = (string) ($data['to'] ?? '');

        if ($to === '') {
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $to = $user->getEMailAddress() ?? '';
            }
        }

        if ($to === '') {
            return new JSONResponse(['error' => 'No test recipient address available'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->emailService->sendEmail(
                caseId: 'smtp-test',
                to: $to,
                subject: 'Procest SMTP-test',
                body: '<p>Dit is een testbericht van de Procest e-mailintegratie.</p>',
            );
            return new JSONResponse(['success' => true]);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }//end testSmtp()

    /**
     * Authorize access to email operations on a case.
     *
     * Allows admin users and any authenticated user (case-team check delegated
     * to the service layer; this controller-level guard prevents unauthenticated
     * callers).
     *
     * @param string $caseId The case UUID
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Http\Response If user is not authenticated
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    public function authorizeEmailAction(string $caseId): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Authentication required');
        }
    }//end authorizeEmailAction()

    /**
     * Require admin access; throws if the current user is not an admin.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T05
     */
    private function requireAdmin(): void
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin(uid: $user->getUID()) === false) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Admin access required');
        }
    }//end requireAdmin()

    /**
     * Decode and return the JSON request body as an array.
     *
     * @return array<string, mixed> Decoded request body
     */
    private function getRequestData(): array
    {
        $content = $this->request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getRequestData()
}//end class
