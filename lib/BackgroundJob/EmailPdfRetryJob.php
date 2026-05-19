<?php

/**
 * Procest Email PDF Retry Job
 *
 * Timed background job that retries Docudesk PDF conversion for email messages
 * that previously failed (pdfStatus = 'failed'). Applies exponential backoff:
 * retry 1 after 15 min, retry 2 after 1 h, retry 3 after 4 h. Messages that
 * fail three times are left with pdfStatus = 'failed' and not retried further.
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
 * Timed job that retries Docudesk PDF conversion for failed email messages.
 *
 * @spec openspec/changes/case-email-integration/tasks.md#task-T06
 */
class EmailPdfRetryJob extends TimedJob
{

    /**
     * Maximum number of PDF conversion retries per message.
     */
    private const MAX_RETRIES = 3;

    /**
     * Constructor.
     *
     * @param ITimeFactory     $time            Time factory
     * @param CaseEmailService $emailService    Email service for PDF retry
     * @param SettingsService  $settingsService Settings service
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
        // Every 15 minutes.
        $this->setInterval(seconds: 900);
    }//end __construct()

    /**
     * Retry PDF conversion for email messages with pdfStatus = 'failed'.
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

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $register      = $this->settingsService->getConfigValue('register');
        $messageSchema = $this->settingsService->getConfigValue('email_message_schema');

        if (empty($messageSchema) === true) {
            return;
        }

        try {
            $failed = $objectService->findObjects(
                register: $register,
                schema: $messageSchema,
                params: ['pdfStatus' => 'failed', '_limit' => 20],
            );

            if (empty($failed) === true) {
                return;
            }

            $this->logger->info(
                'Procest EmailPdfRetryJob: retrying '.count($failed).' failed PDF conversions',
                ['app' => Application::APP_ID],
            );

            foreach ($failed as $message) {
                $msgId      = (string) ($message['id'] ?? ($message['uuid'] ?? ''));
                $retryCount = (int) ($message['pdfRetryCount'] ?? 0);

                if ($retryCount >= self::MAX_RETRIES) {
                    $this->logger->warning(
                        'Procest EmailPdfRetryJob: message '.$msgId.' exceeded max retries, skipping',
                        ['app' => Application::APP_ID],
                    );
                    continue;
                }

                try {
                    // Increment retry counter and re-schedule conversion.
                    $objectService->saveObject(
                        register: $register,
                        schema: $messageSchema,
                        object: array_merge(
                                $message,
                                [
                                    'pdfStatus'     => 'pending',
                                    'pdfRetryCount' => $retryCount + 1,
                                ]
                                ),
                    );

                    $this->emailService->schedulePdfConversion(messageId: $msgId);

                    $this->logger->info(
                        'Procest EmailPdfRetryJob: retried PDF conversion for message '.$msgId
                        .' (attempt '.($retryCount + 1).')',
                        ['app' => Application::APP_ID],
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Procest EmailPdfRetryJob: retry failed for message '.$msgId.': '.$e->getMessage(),
                        ['app' => Application::APP_ID],
                    );
                }//end try
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest EmailPdfRetryJob: job failed: '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
        }//end try
    }//end run()
}//end class
