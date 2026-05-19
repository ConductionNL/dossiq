<?php

/**
 * Procest Inbound Email Job
 *
 * Timed background job that polls the configured IMAP mailbox for new messages,
 * processes them through the CaseEmailService for auto-linking, moves processed
 * messages to the "Processed" IMAP folder, and deduplicates by Message-ID.
 * All exceptions are caught and logged to prevent job deregistration.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T06
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\CaseEmailService;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Timed job that polls IMAP for inbound emails and links them to cases.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T06
 */
class InboundEmailJob extends TimedJob
{

    /**
     * Default poll interval in seconds (5 minutes).
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Default maximum messages processed per run.
     */
    private const DEFAULT_BATCH_SIZE = 50;

    /**
     * Constructor.
     *
     * @param ITimeFactory     $time            Time factory
     * @param CaseEmailService $emailService    Email service for processing
     * @param SettingsService  $settingsService Settings service for config
     * @param IAppManager      $appManager      App manager for availability check
     * @param LoggerInterface  $logger          Logger
     */
    public function __construct(
        ITimeFactory $time,
        private readonly CaseEmailService $emailService,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        $intervalValue = (int) $this->settingsService->getConfigValue(
            key: 'email_poll_interval',
            default: (string) self::DEFAULT_INTERVAL,
        );
        if ($intervalValue > 0) {
            $interval = $intervalValue;
        } else {
            $interval = self::DEFAULT_INTERVAL;
        }

        $this->setInterval(seconds: $interval);
    }//end __construct()

    /**
     * Poll the IMAP mailbox and process inbound emails.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/case-email-integration/tasks.md#task-T06
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            return;
        }

        $imapHost = $this->settingsService->getConfigValue('email_imap_host');
        if (empty($imapHost) === true) {
            $this->logger->debug(
                'Procest InboundEmailJob: IMAP not configured, skipping',
                ['app' => Application::APP_ID],
            );
            return;
        }

        $batchSize = (int) $this->settingsService->getConfigValue(
            key: 'email_poll_batch_size',
            default: (string) self::DEFAULT_BATCH_SIZE,
        );
        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        $this->logger->info(
            'Procest InboundEmailJob: polling IMAP (batch='.$batchSize.')',
            ['app' => Application::APP_ID],
        );

        try {
            $messages  = $this->fetchImapMessages(batchSize: $batchSize);
            $processed = 0;

            foreach ($messages as $msg) {
                try {
                    $this->emailService->processInboundEmail(
                        messageId: (string) ($msg['messageId'] ?? ''),
                        from: (string) ($msg['from'] ?? ''),
                        to: (string) ($msg['to'] ?? ''),
                        subject: (string) ($msg['subject'] ?? ''),
                        body: (string) ($msg['body'] ?? ''),
                        inReplyTo: (string) ($msg['inReplyTo'] ?? ''),
                    );
                    $processed++;
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Procest InboundEmailJob: failed to process message: '.$e->getMessage(),
                        ['app' => Application::APP_ID, 'messageId' => $msg['messageId'] ?? ''],
                    );
                }
            }//end foreach

            $this->logger->info(
                'Procest InboundEmailJob: processed '.$processed.' of '.count($messages).' messages',
                ['app' => Application::APP_ID],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest InboundEmailJob: IMAP poll failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }//end try
    }//end run()

    /**
     * Fetch unread messages from the IMAP mailbox up to batch size.
     *
     * Returns a list of message arrays with messageId, from, to, subject,
     * body, and inReplyTo keys. IMAP interaction is intentionally isolated
     * here to allow unit testing of processInboundEmail without a live server.
     *
     * @param int $batchSize Maximum number of messages to fetch
     *
     * @return array<int, array<string, string>> Message list
     */
    private function fetchImapMessages(int $batchSize): array
    {
        $host     = $this->settingsService->getConfigValue('email_imap_host');
        $port     = (int) $this->settingsService->getConfigValue('email_imap_port', '993');
        $user     = $this->settingsService->getConfigValue('email_imap_user');
        $password = $this->settingsService->getConfigValue('email_imap_password');

        if (empty($host) === true || empty($user) === true || empty($password) === true) {
            return [];
        }

        if (function_exists('imap_open') === false) {
            $this->logger->warning(
                'Procest InboundEmailJob: PHP IMAP extension not available',
                ['app' => Application::APP_ID],
            );
            return [];
        }

        $mailbox = '{'.$host.':'.$port.'/imap/ssl}INBOX';

        // phpcs:disable CustomSn.Functions.NamedParameters
        $connection = @imap_open($mailbox, $user, $password, 0, 1);
        // phpcs:enable CustomSn.Functions.NamedParameters

        if ($connection === false) {
            $this->logger->error(
                'Procest InboundEmailJob: could not connect to IMAP server '.$host,
                ['app' => Application::APP_ID],
            );
            return [];
        }

        try {
            // phpcs:disable CustomSn.Functions.NamedParameters
            $msgNums = imap_search($connection, 'UNSEEN');
            // phpcs:enable CustomSn.Functions.NamedParameters

            if ($msgNums === false) {
                return [];
            }

            $messages = [];
            $count    = 0;

            foreach ($msgNums as $num) {
                if ($count >= $batchSize) {
                    break;
                }

                // phpcs:disable CustomSn.Functions.NamedParameters
                $header = imap_headerinfo($connection, $num);
                $body   = imap_fetchbody($connection, $num, '1');
                // phpcs:enable CustomSn.Functions.NamedParameters

                if ($header === false) {
                    continue;
                }

                // phpcs:disable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
                $msgId     = trim($header->message_id ?? '');
                $inReplyTo = trim($header->in_reply_to ?? '');
                // phpcs:enable Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps

                $fromStr = '';
                if (isset($header->from[0]) === true) {
                    $fromStr = $header->from[0]->mailbox.'@'.$header->from[0]->host;
                }

                $toStr = '';
                if (isset($header->to[0]) === true) {
                    $toStr = $header->to[0]->mailbox.'@'.$header->to[0]->host;
                }

                $subjectStr = '';
                if (isset($header->subject) === true) {
                    // phpcs:disable CustomSn.Functions.NamedParameters
                    $subjectStr = imap_utf8($header->subject);
                    // phpcs:enable CustomSn.Functions.NamedParameters
                }

                $bodyStr = '';
                if ($body !== false) {
                    $bodyStr = $body;
                }

                $messages[] = [
                    'messageId' => $msgId,
                    'from'      => $fromStr,
                    'to'        => $toStr,
                    'subject'   => $subjectStr,
                    'body'      => $bodyStr,
                    'inReplyTo' => $inReplyTo,
                ];

                $count++;
            }//end foreach

            return $messages;
        } finally {
            // phpcs:disable CustomSn.Functions.NamedParameters
            imap_close($connection);
            // phpcs:enable CustomSn.Functions.NamedParameters
        }//end try
    }//end fetchImapMessages()
}//end class
