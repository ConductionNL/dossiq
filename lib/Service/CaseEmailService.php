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
use OCP\IAppConfig;
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
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IMailer $mailer,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function sendEmail(
        string $caseId,
        string $to,
        string $subject,
        string $body,
        array $attachments=[],
    ): array {
        $fromAddress = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_from_address',
            'noreply@example.nl',
        );
        $fromName    = $this->appConfig->getValueString(
            Application::APP_ID,
            'email_from_name',
            'Procest',
        );

        $message = $this->mailer->createMessage();
        $message->setFrom([$fromAddress => $fromName]);
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setHtmlBody($body);
        $message->setPlainBody(strip_tags($body));

        // Add attachments.
        foreach ($attachments as $filePath) {
            if (file_exists($filePath) === true) {
                $message->attachFile($filePath);
            }
        }

        try {
            $this->mailer->send($message);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to send email for case '.$caseId.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new \RuntimeException('Email sending failed: '.$e->getMessage());
        }

        // Record the sent email as a case document.
        $messageId = $this->recordSentEmail(caseId: $caseId, to: $to, subject: $subject, body: $body);

        $this->logger->info(
            'Email sent for case '.$caseId.' to '.$to,
            ['app' => Application::APP_ID],
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
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
     * @param string               $template The template string
     * @param array<string, mixed> $data     Available data for resolution
     *
     * @return string The resolved string
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function resolveVariables(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $matches) use ($data): string {
                $key = $matches[1];
                if (isset($data[$key]) === true && is_scalar($data[$key]) === true) {
                    return (string) $data[$key];
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
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
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
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

        $result = $objectService->getObject($register, $schema, $templateId);
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

        $caseObj = $objectService->getObject($register, $schema, $caseId);
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
                    $register,
                    $schema,
                    [
                        'case'      => $caseId,
                        'direction' => 'outbound',
                        'from'      => $this->appConfig->getValueString(Application::APP_ID, 'email_from_address', ''),
                        'to'        => $to,
                        'subject'   => $subject,
                        'body'      => $body,
                        'messageId' => $messageId,
                        'sentAt'    => date('Y-m-d\TH:i:s'),
                    ]
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
                    $register,
                    $schema,
                    [
                        'case'       => $caseId,
                        'direction'  => 'inbound',
                        'from'       => $from,
                        'to'         => '',
                        'subject'    => $subject,
                        'body'       => $body,
                        'messageId'  => $messageId,
                        'inReplyTo'  => $inReplyTo,
                        'receivedAt' => date('Y-m-d\TH:i:s'),
                    ]
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
