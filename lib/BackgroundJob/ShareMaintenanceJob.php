<?php

/**
 * Procest Share Maintenance Background Job
 *
 * Daily job for share expiration reminders and cleanup.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use DateTime;
use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily background job that manages share lifecycle.
 *
 * Checks for shares expiring within 3 days and sends reminder
 * notifications to case workers.
 */
class ShareMaintenanceJob extends TimedJob
{
    /**
     * Days before expiration to send a reminder.
     */
    private const REMINDER_DAYS = 3;

    /**
     * Constructor for the ShareMaintenanceJob.
     *
     * @param ITimeFactory       $time            The time factory
     * @param SettingsService    $settingsService The settings service
     * @param IAppManager        $appManager      The app manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        // Run once per day (86400 seconds).
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Execute the share maintenance job.
     *
     * @param mixed $argument Job argument (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) — required by parent

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: ShareMaintenanceJob could not get ObjectService',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_share_schema');

        if (empty($register) === true || empty($schema) === true) {
            return;
        }

        try {
            $shares = $objectService->findAll(
                ['filters' => ['register' => (int) $register, 'schema' => (int) $schema]],
            );

            $reminderDate = new DateTime('+'.self::REMINDER_DAYS.' days');

            foreach ($shares as $share) {
                $shareData = $share;
                if (is_object($share) === true) {
                    $shareData = $share->jsonSerialize();
                }

                // Skip revoked shares.
                if (empty($shareData['revokedAt']) === false) {
                    continue;
                }

                // Check if share expires within reminder window.
                if (empty($shareData['expiresAt']) === false) {
                    $expiresAt = new DateTime($shareData['expiresAt']);
                    if ($expiresAt <= $reminderDate && $expiresAt > new DateTime()) {
                        $this->logger->info(
                            'Procest: Share expiring soon',
                            [
                                'shareId'   => ($shareData['id'] ?? 'unknown'),
                                'expiresAt' => $shareData['expiresAt'],
                                'createdBy' => ($shareData['createdBy'] ?? 'unknown'),
                            ]
                        );
                    }
                }
            }//end foreach
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: ShareMaintenanceJob failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
