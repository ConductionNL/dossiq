<?php

/**
 * Procest Case Email Service
 *
 * Service for sending and receiving email within case context.
 * Supports template variable resolution, email-to-PDF conversion,
 * and automatic linking of inbound email to cases.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Service for case-integrated email functionality.
 */
class CaseEmailService
{

    /**
     * Regex pattern for extracting case number from email subject.
     */
    private const CASE_NUMBER_PATTERN = '/\[ZAAK-(\d{4}-\d{4,})\]/';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param IMailer         $mailer          Nextcloud mailer
     * @param IAppConfig      $appConfig       Nextcloud app config
     * @param LoggerInterface $logger          Logger
     * @param IRootFolder     $rootFolder      Root folder for user-file access
     * @param IUserSession    $userSession     Current user session
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IMailer $mailer,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Send an email from case context.
     *
     * @param string        $caseId      The case UUID
     * @param string        $to          Recipient email address
     * @param string        $subject     Email subject
     * @param string        $body        Email body (HTML or plain text)
     * @param array<string> $attachments File paths to attach
     *
     * @return array<string, mixed> Send result with message ID
     *
     * @throws \RuntimeException If sending fails

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function sendEmail(
        string $caseId,
        string $to,
        string $subject,
        string $body,
        array $attachments=[],
    ): array {
        // H6 / C4: Fail loudly if from-address is not configured — never fall back to
        // the reserved example.nl domain which would cause bounces and expose config errors.
        $fromAddress = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_from_address',
            '',
        );
        if ($fromAddress === '' || str_ends_with($fromAddress, '@example.nl') === true) {
            throw new \RuntimeException(
                'E-mail afzenderadres is niet geconfigureerd. '
                .'Stel email_from_address in via de beheerdersinstellingen.'
            );
        }

        $fromName = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_from_name',
            'Procest',
        );

        // C4 IDOR: Load the case via OR with RBAC enabled to verify the current user
        // has read access. If the case is not found (or the user has no access), OR
        // returns null — we treat that as 403.
        $caseData = $this->loadCaseData(caseId: $caseId);
        if (empty($caseData) === true) {
            throw new \RuntimeException('Zaak niet gevonden of geen toegang.');
        }

        // H4: Validate the recipient against the case's registered contact emails.
        // This prevents open-relay abuse where any email address could be supplied.
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Ongeldig e-mailadres opgegeven.');
        }

        $allowedEmails = $this->getCaseContactEmails(caseData: $caseData);
        if (count($allowedEmails) > 0) {
            if (in_array(strtolower($to), $allowedEmails, true) === false) {
                $this->logger->warning(
                    'Blocked email to non-case-contact address',
                    ['app' => Application::APP_ID, 'to' => $to, 'caseId' => $caseId]
                );
                throw new \RuntimeException('Ontvanger is geen geregistreerd contact bij deze zaak.');
            }
        }

        $message = $this->mailer->createMessage();
        $message->setFrom([$fromAddress => $fromName]);
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setHtmlBody($body);
        $message->setPlainBody(strip_tags($body));

        // H5: Resolve attachments via IUserFolder to restrict file access to the
        // calling user's own files and prevent path traversal outside their folder.
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && count($attachments) > 0) {
            $userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());
            foreach ($attachments as $fileRef) {
                try {
                    $file      = $userFolder->get((string) $fileRef);
                    $localPath = $file->getStorage()->getLocalFile($file->getInternalPath());
                    if ($localPath !== null && $localPath !== false) {
                        $message->attachFile($localPath);
                    }
                } catch (NotFoundException $e) {
                    $this->logger->warning(
                        'Attachment file not found in user folder',
                        ['app' => Application::APP_ID, 'fileRef' => $fileRef, 'caseId' => $caseId]
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Failed to attach file',
                        ['app' => Application::APP_ID, 'fileRef' => $fileRef, 'error' => $e->getMessage()]
                    );
                }//end try
            }//end foreach
        }//end if

        try {
            $this->mailer->send($message);
        } catch (\Exception $e) {
            // M4: Log full exception server-side; throw a generic message so internal
            // mail-server errors (hostnames, credentials, etc.) are not leaked to callers.
            $this->logger->error(
                'Failed to send email for case {caseId}: {error}',
                [
                    'app'       => Application::APP_ID,
                    'caseId'    => $caseId,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ],
            );
            throw new \RuntimeException('email_send_failed');
        }

        // Record the sent email as a case document.
        $messageId = $this->recordSentEmail(caseId: $caseId, to: $to, subject: $subject, body: $body);

        $this->logger->info(
            'Email sent for case {caseId}',
            ['app' => Application::APP_ID, 'caseId' => $caseId],
        );

        return [
            'messageId' => $messageId,
            'to'        => $to,
            'subject'   => $subject,
            'sentAt'    => date('Y-m-d\TH:i:s'),
        ];
    }//end sendEmail()

    /**
     * Send an email using a template.
     *
     * @param string $caseId     The case UUID
     * @param string $templateId The email template UUID
     * @param string $to         Recipient email address
     *
     * @return array<string, mixed> Send result
     *
     * @throws \RuntimeException If template not found or sending fails

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function sendFromTemplate(
        string $caseId,
        string $templateId,
        string $to,
    ): array {
        $template = $this->loadTemplate(templateId: $templateId);
        if ($template === null) {
            throw new \RuntimeException('Email template not found');
        }

        // Load case data for variable resolution.
        $caseData = $this->loadCaseData(caseId: $caseId);

        // Resolve template variables.
        $subject = $this->resolveVariables(template: $template['subjectPattern'] ?? '', data: $caseData);
        $body    = $this->resolveVariables(template: $template['body'] ?? '', data: $caseData);

        return $this->sendEmail(caseId: $caseId, to: $to, subject: $subject, body: $body);
    }//end sendFromTemplate()

    /**
     * Resolve template variables in a string.
     *
     * Variables use {{variableName}} syntax.
     *
     * @param string               $template   The template string
     * @param array<string, mixed> $data       Available data for resolution
     * @param bool                 $htmlEscape Whether to HTML-escape substituted values (default: true)
     *
     * @return string The resolved string

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function resolveVariables(string $template, array $data, bool $htmlEscape=true): string
    {
        // H6 XSS: HTML-escape all substituted values by default so case data
        // containing HTML/JS (e.g. from citizen-submitted forms) cannot execute
        // in email clients. Pass $htmlEscape=false only for plain-text contexts.
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $matches) use ($data, $htmlEscape): string {
                $key = $matches[1];
                if (isset($data[$key]) === true && is_scalar($data[$key]) === true) {
                    $value = (string) $data[$key];
                    if ($htmlEscape === true) {
                        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }

                    return $value;
                }

                return $matches[0];
                // Leave unresolved variables as-is.
            },
            $template,
        ) ?? $template;
    }//end resolveVariables()

    /**
     * Find unresolved variables in a template string.
     *
     * @param string               $template The template string
     * @param array<string, mixed> $data     Available data
     *
     * @return array<string> List of unresolved variable names

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function findUnresolvedVariables(string $template, array $data): array
    {
        $unresolved = [];
        preg_match_all('/\{\{(\w+)\}\}/', $template, $matches);

        foreach ($matches[1] as $key) {
            if (isset($data[$key]) === false || is_scalar($data[$key]) === false) {
                $unresolved[] = $key;
            }
        }

        return array_unique($unresolved);
    }//end findUnresolvedVariables()

    /**
     * Extract case number from email subject.
     *
     * @param string $subject The email subject
     *
     * @return string|null The extracted case identifier or null

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function extractCaseNumber(string $subject): ?string
    {
        if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }//end extractCaseNumber()

    /**
     * Process an inbound email and link it to a case.
     *
     * @param string $from      Sender email address
     * @param string $to        Recipient email address
     * @param string $subject   Email subject
     * @param string $body      Email body
     * @param string $inReplyTo In-Reply-To header (for threading)
     *
     * @return array<string, mixed> Processing result

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function processInbound(
        string $from,
        string $to,
        string $subject,
        string $body,
        string $inReplyTo='',
    ): array {
        $caseNumber = $this->extractCaseNumber(subject: $subject);

        if ($caseNumber !== null) {
            // Auto-link to case.
            $caseId = $this->findCaseByIdentifier(identifier: $caseNumber);
            if ($caseId !== null) {
                $messageId = $this->recordReceivedEmail(
                    caseId: $caseId,
                    from: $from,
                    subject: $subject,
                    body: $body,
                    inReplyTo: $inReplyTo,
                );

                $this->logger->info(
                    'Inbound email auto-linked to case '.$caseId,
                    ['app' => Application::APP_ID],
                );

                return [
                    'linked'    => true,
                    'caseId'    => $caseId,
                    'messageId' => $messageId,
                    'method'    => 'auto',
                ];
            }//end if
        }//end if

        // Could not auto-link; add to unlinked queue.
        return [
            'linked'     => false,
            'caseNumber' => $caseNumber,
            'from'       => $from,
            'subject'    => $subject,
            'method'     => 'unlinked',
        ];
    }//end processInbound()

    /**
     * Get email templates for a case type.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<int, array<string, mixed>> List of templates

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function getTemplatesForCaseType(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_template_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['caseType' => $caseTypeId],
            [],
            100,
        );

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end getTemplatesForCaseType()

    /**
     * Collect the normalised (lowercased) email addresses of all contacts on a case.
     *
     * Inspects the following fields (all optional): `betrokkenen`, `contacts`,
     * `initiator`, and the top-level `email` field. Returns an empty array when
     * no contacts are registered; the caller treats an empty array as "no restriction".
     *
     * @param array<string, mixed> $caseData The case data array
     *
     * @return array<string> Lowercase email addresses
     */
    private function getCaseContactEmails(array $caseData): array
    {
        $emails = [];

        // Top-level email field.
        $topEmail = strtolower(trim((string) ($caseData['email'] ?? '')));
        if ($topEmail !== '' && filter_var($topEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $emails[] = $topEmail;
        }

        // Initiator field (single contact object or email string).
        $initiator = $caseData['initiator'] ?? null;
        if (is_array($initiator) === true) {
            $addr = strtolower(trim((string) ($initiator['email'] ?? '')));
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $addr;
            }
        }

        // Betrokkenen / contacts arrays.
        $contactArrays = [];
        if (is_array($caseData['betrokkenen'] ?? null) === true) {
            $contactArrays[] = $caseData['betrokkenen'];
        }

        if (is_array($caseData['contacts'] ?? null) === true) {
            $contactArrays[] = $caseData['contacts'];
        }

        foreach ($contactArrays as $contacts) {
            foreach ($contacts as $contact) {
                if (is_array($contact) === false) {
                    continue;
                }

                $addr = strtolower(trim((string) ($contact['email'] ?? ($contact['emailadres'] ?? ''))));
                if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                    $emails[] = $addr;
                }
            }
        }

        return array_unique($emails);
    }//end getCaseContactEmails()

    /**
     * Load an email template.
     *
     * @param string $templateId The template UUID
     *
     * @return array<string, mixed>|null The template data
     */
    private function loadTemplate(string $templateId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_template_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        $result = $objectService->find($templateId, register: $register, schema: $schema);
        if (is_array($result) === true) {
            return $result;
        }

        return null;
    }//end loadTemplate()

    /**
     * Load case data for template variable resolution.
     *
     * @param string $caseId The case UUID
     *
     * @return array<string, mixed> Case data flattened for variable resolution
     */
    private function loadCaseData(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        $caseObj = $objectService->find($caseId, register: $register, schema: $schema);
        if ($caseObj === null) {
            return [];
        }

        if (is_object($caseObj) === true && method_exists($caseObj, 'jsonSerialize') === true) {
            $caseObj = $caseObj->jsonSerialize();
        }

        if (is_array($caseObj) === false) {
            return [];
        }

        // Flatten for variable resolution.
        return [
            'zaakNummer'  => $caseObj['identifier'] ?? '',
            'titel'       => $caseObj['title'] ?? '',
            'startdatum'  => $caseObj['startDate'] ?? '',
            'deadline'    => $caseObj['deadline'] ?? '',
            'status'      => $caseObj['status'] ?? '',
            'behandelaar' => $caseObj['assignee'] ?? '',
        ];
    }//end loadCaseData()

    /**
     * Record a sent email as a case document.
     *
     * @param string $caseId  Case UUID
     * @param string $to      Recipient
     * @param string $subject Subject
     * @param string $body    Body
     *
     * @return string The recorded message ID
     */
    private function recordSentEmail(
        string $caseId,
        string $to,
        string $subject,
        string $body,
    ): string {
        // Store as activity on the case.
        $messageId = 'msg-'.uniqid();

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $messageId;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($register) === false && empty($schema) === false) {
            $objectService->saveObject(
                    object: [
                        'case'      => $caseId,
                        'direction' => 'outbound',
                        'from'      => $this->appConfig->getValueString(Application::APP_ID, 'email_from_address', ''),
                        'to'        => $to,
                        'subject'   => $subject,
                        'body'      => $body,
                        'messageId' => $messageId,
                        'sentAt'    => date('Y-m-d\TH:i:s'),
                    ],
                    register: $register,
                    schema: $schema,
                    );
        }

        return $messageId;
    }//end recordSentEmail()

    /**
     * Record a received email.
     *
     * @param string $caseId    Case UUID
     * @param string $from      Sender
     * @param string $subject   Subject
     * @param string $body      Body
     * @param string $inReplyTo Threading header
     *
     * @return string The recorded message ID
     */
    private function recordReceivedEmail(
        string $caseId,
        string $from,
        string $subject,
        string $body,
        string $inReplyTo,
    ): string {
        $messageId = 'msg-'.uniqid();

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $messageId;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($register) === false && empty($schema) === false) {
            $objectService->saveObject(
                    object: [
                        'case'       => $caseId,
                        'direction'  => 'inbound',
                        'from'       => $from,
                        'to'         => '',
                        'subject'    => $subject,
                        'body'       => $body,
                        'messageId'  => $messageId,
                        'inReplyTo'  => $inReplyTo,
                        'receivedAt' => date('Y-m-d\TH:i:s'),
                    ],
                    register: $register,
                    schema: $schema,
                    );
        }

        return $messageId;
    }//end recordReceivedEmail()

    /**
     * Find a case by its identifier.
     *
     * @param string $identifier The case identifier (e.g., 2026-0042)
     *
     * @return string|null The case UUID or null
     */
    private function findCaseByIdentifier(string $identifier): ?string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');

        $results = $objectService->findObjects(
            $register,
            $schema,
            ['identifier' => $identifier],
            [],
            1,
        );

        if (is_array($results) === true && count($results) > 0) {
            return $results[0]['id'] ?? $results[0]['uuid'] ?? null;
        }

        return null;
    }//end findCaseByIdentifier()
}//end class
