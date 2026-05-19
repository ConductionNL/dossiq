<?php

/**
 * Procest Case Email Service
 *
 * Handles outbound and inbound email for case management: template variable
 * resolution, RFC 2822 threading, Docudesk PDF orchestration, automatic
 * inbound linking by case-number subject regex or In-Reply-To header, and
 * the unlinked-email manual queue.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Service for case-integrated email: send, receive, thread, and PDF-store.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T03
 */
class CaseEmailService
{

    /**
     * Anchored regex for extracting case identifier from email subject.
     */
    private const CASE_NUMBER_PATTERN = '/\[([A-Z]+-\d{4}-\d{6})\]/';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService App settings
     * @param IMailer         $mailer          NC mailer for outbound transport
     * @param IAppConfig      $appConfig       App config for sensitive credentials
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
     * Send an outbound email from case context.
     *
     * Generates a unique RFC 2822 Message-ID, sends via configured transport,
     * stores an emailMessage object, appends an email_sent activity, and
     * schedules PDF conversion via Docudesk.
     *
     * @param string        $caseId          The case UUID
     * @param string        $to              Primary recipient email address
     * @param string        $subject         Email subject (caller should include [ZAAK-…]
     *                                       prefix)
     * @param string        $body            HTML body
     * @param array<string> $cc              CC recipients
     * @param array<string> $bcc             BCC recipients
     * @param array<string> $attachments     File paths to attach
     * @param string|null   $templateId      Optional emailTemplate UUID used
     * @param int|null      $templateVersion Template version at send time
     * @param string|null   $inReplyTo       In-Reply-To header for reply threading
     *
     * @return array<string, mixed> Send result with message ID
     *
     * @throws \RuntimeException If sending fails
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function sendEmail(
        string $caseId,
        string $to,
        string $subject,
        string $body,
        array $cc=[],
        array $bcc=[],
        array $attachments=[],
        ?string $templateId=null,
        ?int $templateVersion=null,
        ?string $inReplyTo=null,
    ): array {
        $fromAddress = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'email_from_address',
            default: 'noreply@example.nl',
        );
        $fromName    = $this->appConfig->getValueString(
            app: Application::APP_ID,
            key: 'email_from_name',
            default: 'Procest',
        );

        $messageId = $this->generateMessageId();

        $message = $this->mailer->createMessage();
        $message->setFrom([$fromAddress => $fromName]);
        $message->setTo([$to]);
        $message->setSubject($subject);
        $message->setHtmlBody($body);
        $message->setPlainBody(strip_tags($body));

        if (empty($cc) === false) {
            $message->setCC($cc);
        }

        if (empty($bcc) === false) {
            $message->setBCC($bcc);
        }

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

        // Resolve or create thread.
        $threadId = $this->resolveOrCreateThread(
            caseId: $caseId,
            subject: $subject,
            inReplyTo: $inReplyTo,
        );

        // Store emailMessage object.
        $emailMessage = $this->storeEmailMessage(
            caseId: $caseId,
            threadId: $threadId,
            messageId: $messageId,
            direction: 'outbound',
            from: $fromAddress,
            to: [$to],
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
            body: $body,
            inReplyTo: $inReplyTo,
            templateId: $templateId,
            templateVersion: $templateVersion,
        );

        // Schedule PDF conversion; small messages run inline, large ones async.
        $this->schedulePdfConversion(messageId: $emailMessage['id'] ?? '');

        $this->logger->info(
            'Email sent for case '.$caseId.' to '.$to,
            ['app' => Application::APP_ID],
        );

        return [
            'messageId' => $messageId,
            'to'        => $to,
            'subject'   => $subject,
            'threadId'  => $threadId,
            'sentAt'    => date('Y-m-d\TH:i:s\Z'),
        ];
    }//end sendEmail()

    /**
     * Process an inbound email: auto-link by subject regex or In-Reply-To.
     *
     * @param string $messageId The RFC 2822 Message-ID
     * @param string $from      Sender address
     * @param string $to        Recipient address
     * @param string $subject   Email subject (scanned for [ZAAK-YYYY-NNNNNN])
     * @param string $body      HTML body
     * @param string $inReplyTo In-Reply-To header value (may be empty)
     *
     * @return array<string, mixed> Processing result with linked/unlinked status
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function processInboundEmail(
        string $messageId,
        string $from,
        string $to,
        string $subject,
        string $body,
        string $inReplyTo='',
    ): array {
        // Deduplicate by Message-ID.
        if ($this->isDuplicate(messageId: $messageId) === true) {
            return ['linked' => false, 'reason' => 'duplicate', 'messageId' => $messageId];
        }

        $caseId   = null;
        $threadId = null;
        $method   = 'unlinked';

        // Try In-Reply-To thread lookup first.
        if (empty($inReplyTo) === false) {
            $parentMsg = $this->findMessageByMessageId(messageId: $inReplyTo);
            if ($parentMsg !== null) {
                $caseId   = (string) ($parentMsg['case'] ?? '');
                $threadId = (string) ($parentMsg['thread'] ?? '');
                $method   = 'in-reply-to';
            }
        }

        // Fall back to subject-regex matching.
        if ($caseId === null) {
            $caseNumber = $this->extractCaseNumber(subject: $subject);
            if ($caseNumber !== null) {
                $caseId = $this->findCaseByIdentifier(identifier: $caseNumber);
                if ($caseId !== null) {
                    $method = 'subject-regex';
                }
            }
        }

        if ($caseId !== null && $caseId !== '') {
            // Resolve or create thread.
            $threadId = $this->resolveOrCreateThread(
                caseId: $caseId,
                subject: $subject,
                inReplyTo: $inReplyTo,
                existingThreadId: $threadId,
            );

            $stored = $this->storeEmailMessage(
                caseId: $caseId,
                threadId: $threadId,
                messageId: $messageId,
                direction: 'inbound',
                from: $from,
                to: [$to],
                cc: [],
                bcc: [],
                subject: $subject,
                body: $body,
                inReplyTo: $inReplyTo,
            );

            $this->logger->info(
                'Inbound email auto-linked to case '.$caseId.' via '.$method,
                ['app' => Application::APP_ID],
            );

            return [
                'linked'    => true,
                'caseId'    => $caseId,
                'threadId'  => $threadId,
                'messageId' => $messageId,
                'method'    => $method,
            ];
        }//end if

        // Could not auto-link — store in unlinked queue.
        $this->storeUnlinkedEmail(
            messageId: $messageId,
            from: $from,
            to: $to,
            subject: $subject,
            body: $body,
            inReplyTo: $inReplyTo,
        );

        $this->logger->info(
            'Inbound email could not be auto-linked, added to unlinked queue',
            ['app' => Application::APP_ID, 'messageId' => $messageId],
        );

        return [
            'linked'    => false,
            'messageId' => $messageId,
            'method'    => 'unlinked',
        ];
    }//end processInboundEmail()

    /**
     * Resolve template variables in a string.
     *
     * Variables use {{variableName}} syntax. Unresolved variables are left as-is.
     *
     * @param string               $template The template string
     * @param array<string, mixed> $data     Available data for resolution
     *
     * @return string The resolved string
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function resolveTemplateVariables(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $matches) use ($data): string {
                $key = $matches[1];
                if (isset($data[$key]) === true && is_scalar($data[$key]) === true) {
                    return (string) $data[$key];
                }

                return $matches[0];
            },
            $template,
        ) ?? $template;
    }//end resolveTemplateVariables()

    /**
     * Find unresolved variables in a template string.
     *
     * @param string               $template The template string
     * @param array<string, mixed> $data     Available data
     *
     * @return array<string> Unresolved variable names
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
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

        return array_unique(array: $unresolved);
    }//end findUnresolvedVariables()

    /**
     * Link an unlinked inbound email to a specific case.
     *
     * @param string $unlinkedId The unlinked email object UUID
     * @param string $caseId     The target case UUID
     *
     * @return array<string, mixed> Result with linked emailMessage
     *
     * @throws \RuntimeException If the unlinked email is not found
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function linkUnlinkedEmail(string $unlinkedId, string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $unlinkedSchema = 'unlinkedEmail';

        $unlinked = $objectService->findObject(
            register: $register,
            schema: $unlinkedSchema,
            id: $unlinkedId,
        );

        if ($unlinked === null) {
            throw new \RuntimeException('Unlinked email not found');
        }

        $subject  = (string) ($unlinked['subject'] ?? '');
        $threadId = $this->resolveOrCreateThread(caseId: $caseId, subject: $subject);

        $stored = $this->storeEmailMessage(
            caseId: $caseId,
            threadId: $threadId,
            messageId: (string) ($unlinked['messageId'] ?? 'msg-'.uniqid()),
            direction: 'inbound',
            from: (string) ($unlinked['from'] ?? ''),
            to: [$unlinked['to'] ?? ''],
            cc: [],
            bcc: [],
            subject: $subject,
            body: (string) ($unlinked['body'] ?? ''),
            inReplyTo: (string) ($unlinked['inReplyTo'] ?? ''),
        );

        // Mark unlinked entry as linked.
        $objectService->saveObject(
            register: $register,
            schema: $unlinkedSchema,
            object: array_merge($unlinked, ['status' => 'linked', 'linkedCase' => $caseId]),
        );

        return ['linked' => true, 'caseId' => $caseId, 'messageId' => $stored['messageId'] ?? ''];
    }//end linkUnlinkedEmail()

    /**
     * Discard an unlinked inbound email.
     *
     * @param string $unlinkedId The unlinked email object UUID
     *
     * @return array<string, mixed> Result confirming discard
     *
     * @throws \RuntimeException If the unlinked email is not found
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function discardUnlinkedEmail(string $unlinkedId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $unlinkedSchema = 'unlinkedEmail';

        $unlinked = $objectService->findObject(
            register: $register,
            schema: $unlinkedSchema,
            id: $unlinkedId,
        );

        if ($unlinked === null) {
            throw new \RuntimeException('Unlinked email not found');
        }

        $objectService->saveObject(
            register: $register,
            schema: $unlinkedSchema,
            object: array_merge($unlinked, ['status' => 'discarded']),
        );

        return ['discarded' => true, 'id' => $unlinkedId];
    }//end discardUnlinkedEmail()

    /**
     * List email threads and messages for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return array<string, mixed> Threads with embedded messages
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function listEmailsForCase(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['threads' => [], 'total' => 0];
        }

        $register      = $this->settingsService->getConfigValue('register');
        $threadSchema  = $this->settingsService->getConfigValue('email_thread_schema');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        $threads = $objectService->findObjects(
            register: $register,
            schema: $threadSchema,
            params: ['case' => $caseId, '_order' => ['lastMessageAt' => 'DESC']],
        );

        $result = [];
        foreach ($threads as $thread) {
            $threadId = (string) ($thread['id'] ?? ($thread['uuid'] ?? ''));
            $messages = $objectService->findObjects(
                register: $register,
                schema: $messageSchema,
                params: ['thread' => $threadId, '_order' => ['sentAt' => 'ASC']],
            );
            $result[] = array_merge($thread, ['messages' => $messages]);
        }

        return ['threads' => $result, 'total' => count($result)];
    }//end listEmailsForCase()

    /**
     * List unlinked inbound emails pending manual assignment.
     *
     * @return array<string, mixed> Unlinked email queue
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function listUnlinkedEmails(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['results' => [], 'total' => 0];
        }

        $register = $this->settingsService->getConfigValue('register');

        $items = $objectService->findObjects(
            register: $register,
            schema: 'unlinkedEmail',
            params: ['status' => 'pending', '_order' => ['receivedAt' => 'DESC']],
        );

        return ['results' => $items, 'total' => count($items)];
    }//end listUnlinkedEmails()

    /**
     * Extract case number from email subject using anchored regex.
     *
     * @param string $subject Email subject line
     *
     * @return string|null The case identifier or null
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function extractCaseNumber(string $subject): ?string
    {
        if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }//end extractCaseNumber()

    /**
     * Generate a unique RFC 2822-compliant Message-ID.
     *
     * @return string Message-ID including angle brackets
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function generateMessageId(): string
    {
        return '<'.uniqid('procest-', more_entropy: true).'@'.gethostname().'>';
    }//end generateMessageId()

    /**
     * Schedule PDF conversion for an email message via Docudesk.
     *
     * Messages under 5 MB are converted inline; larger ones are queued for
     * async processing by EmailPdfRetryJob.
     *
     * @param string $messageId The emailMessage object UUID
     *
     * @return void
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T03
     */
    public function schedulePdfConversion(string $messageId): void
    {
        if (empty($messageId) === true) {
            return;
        }

        // Mark as pending; the background job polls for pending entries.
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register      = $this->settingsService->getConfigValue('register');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        $message = $objectService->findObject(
            register: $register,
            schema: $messageSchema,
            id: $messageId,
        );

        if ($message === null) {
            return;
        }

        $objectService->saveObject(
            register: $register,
            schema: $messageSchema,
            object: array_merge($message, ['pdfStatus' => 'pending']),
        );
    }//end schedulePdfConversion()

    /**
     * Resolve or create an email thread for a case.
     *
     * @param string      $caseId           The case UUID
     * @param string      $subject          Thread subject
     * @param string|null $inReplyTo        In-Reply-To header to match existing thread
     * @param string|null $existingThreadId Pre-resolved thread ID (skips lookup)
     *
     * @return string The thread UUID
     */
    private function resolveOrCreateThread(
        string $caseId,
        string $subject,
        ?string $inReplyTo=null,
        ?string $existingThreadId=null,
    ): string {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register     = $this->settingsService->getConfigValue('register');
        $threadSchema = $this->settingsService->getConfigValue('email_thread_schema');

        if (empty($threadSchema) === true) {
            return '';
        }

        if ($existingThreadId !== null && $existingThreadId !== '') {
            $this->bumpThreadTimestamp(
                objectService: $objectService,
                register: $register,
                threadSchema: $threadSchema,
                threadId: $existingThreadId,
            );
            return $existingThreadId;
        }

        // Look for existing thread via In-Reply-To.
        if ($inReplyTo !== null && $inReplyTo !== '') {
            $parentMsg = $this->findMessageByMessageId(messageId: $inReplyTo);
            if ($parentMsg !== null) {
                $tid = (string) ($parentMsg['thread'] ?? '');
                if ($tid !== '') {
                    $this->bumpThreadTimestamp(
                        objectService: $objectService,
                        register: $register,
                        threadSchema: $threadSchema,
                        threadId: $tid,
                    );
                    return $tid;
                }
            }
        }

        // Create new thread.
        $now     = date('Y-m-d\TH:i:s\Z');
        $subject = preg_replace('/^(RE:\s*|FW:\s*)+/i', '', $subject) ?? $subject;

        $thread = $objectService->saveObject(
            register: $register,
            schema: $threadSchema,
            object: [
                'subject'        => $subject,
                'case'           => $caseId,
                'messageCount'   => 1,
                'firstMessageAt' => $now,
                'lastMessageAt'  => $now,
            ],
        );

        return (string) ($thread['id'] ?? ($thread['uuid'] ?? ''));
    }//end resolveOrCreateThread()

    /**
     * Bump the lastMessageAt and increment messageCount on an email thread.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $register      Register identifier
     * @param string $threadSchema  Thread schema identifier
     * @param string $threadId      Thread UUID
     *
     * @return void
     */
    private function bumpThreadTimestamp(
        object $objectService,
        string $register,
        string $threadSchema,
        string $threadId,
    ): void {
        $thread = $objectService->findObject(
            register: $register,
            schema: $threadSchema,
            id: $threadId,
        );

        if ($thread === null) {
            return;
        }

        $objectService->saveObject(
            register: $register,
            schema: $threadSchema,
            object: array_merge(
                $thread,
                [
                    'lastMessageAt' => date('Y-m-d\TH:i:s\Z'),
                    'messageCount'  => (int) ($thread['messageCount'] ?? 0) + 1,
                ]
            ),
        );
    }//end bumpThreadTimestamp()

    /**
     * Store an emailMessage object in OpenRegister.
     *
     * @param string        $caseId          Case UUID
     * @param string        $threadId        Thread UUID
     * @param string        $messageId       RFC 2822 Message-ID
     * @param string        $direction       'inbound' or 'outbound'
     * @param string        $from            Sender
     * @param array<string> $to              Recipients
     * @param array<string> $cc              CC recipients
     * @param array<string> $bcc             BCC recipients
     * @param string        $subject         Subject line
     * @param string        $body            HTML body
     * @param string|null   $inReplyTo       In-Reply-To header
     * @param string|null   $templateId      Template UUID used
     * @param int|null      $templateVersion Template version at send time
     *
     * @return array<string, mixed> The saved emailMessage object
     */
    private function storeEmailMessage(
        string $caseId,
        string $threadId,
        string $messageId,
        string $direction,
        string $from,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $body,
        ?string $inReplyTo=null,
        ?string $templateId=null,
        ?int $templateVersion=null,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['messageId' => $messageId];
        }

        $register      = $this->settingsService->getConfigValue('register');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($register) === true || empty($messageSchema) === true) {
            return ['messageId' => $messageId];
        }

        $record = [
            'messageId' => $messageId,
            'direction' => $direction,
            'from'      => $from,
            'to'        => $to,
            'cc'        => $cc,
            'bcc'       => $bcc,
            'subject'   => $subject,
            'body'      => $body,
            'case'      => $caseId,
            'thread'    => $threadId,
            'pdfStatus' => 'pending',
            'sentAt'    => date('Y-m-d\TH:i:s\Z'),
        ];

        if ($inReplyTo !== null && $inReplyTo !== '') {
            $record['inReplyTo'] = $inReplyTo;
        }

        if ($templateId !== null) {
            $record['templateId']      = $templateId;
            $record['templateVersion'] = $templateVersion;
        }

        return $objectService->saveObject(
            register: $register,
            schema: $messageSchema,
            object: $record,
        );
    }//end storeEmailMessage()

    /**
     * Store an unlinked inbound email in the manual-link queue.
     *
     * @param string $messageId RFC 2822 Message-ID
     * @param string $from      Sender address
     * @param string $to        Recipient address
     * @param string $subject   Subject line
     * @param string $body      HTML body
     * @param string $inReplyTo In-Reply-To header
     *
     * @return void
     */
    private function storeUnlinkedEmail(
        string $messageId,
        string $from,
        string $to,
        string $subject,
        string $body,
        string $inReplyTo,
    ): void {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register = $this->settingsService->getConfigValue('register');

        $objectService->saveObject(
            register: $register,
            schema: 'unlinkedEmail',
            object: [
                'messageId'  => $messageId,
                'from'       => $from,
                'to'         => $to,
                'subject'    => $subject,
                'body'       => $body,
                'inReplyTo'  => $inReplyTo,
                'status'     => 'pending',
                'receivedAt' => date('Y-m-d\TH:i:s\Z'),
            ],
        );
    }//end storeUnlinkedEmail()

    /**
     * Check whether an email with the given Message-ID was already stored.
     *
     * @param string $messageId The RFC 2822 Message-ID to check
     *
     * @return bool True when a duplicate exists
     */
    private function isDuplicate(string $messageId): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register      = $this->settingsService->getConfigValue('register');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($messageSchema) === true) {
            return false;
        }

        $results = $objectService->findObjects(
            register: $register,
            schema: $messageSchema,
            params: ['messageId' => $messageId, '_limit' => 1],
        );

        return count($results) > 0;
    }//end isDuplicate()

    /**
     * Find an emailMessage by its RFC 2822 Message-ID.
     *
     * @param string $messageId The Message-ID to look up
     *
     * @return array<string, mixed>|null The message or null
     */
    private function findMessageByMessageId(string $messageId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register      = $this->settingsService->getConfigValue('register');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($messageSchema) === true) {
            return null;
        }

        $results = $objectService->findObjects(
            register: $register,
            schema: $messageSchema,
            params: ['messageId' => $messageId, '_limit' => 1],
        );

        if (is_array($results) === true && count($results) > 0) {
            return $results[0];
        }

        return null;
    }//end findMessageByMessageId()

    /**
     * Find a case by its human-readable identifier (e.g., ZAAK-2026-000042).
     *
     * @param string $identifier The case identifier extracted from the subject
     *
     * @return string|null The case UUID or null
     */
    private function findCaseByIdentifier(string $identifier): ?string
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        $results = $objectService->findObjects(
            register: $register,
            schema: $caseSchema,
            params: ['identifier' => $identifier, '_limit' => 1],
        );

        if (is_array($results) === true && count($results) > 0) {
            return (string) ($results[0]['id'] ?? ($results[0]['uuid'] ?? null));
        }

        return null;
    }//end findCaseByIdentifier()
}//end class
