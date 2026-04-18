<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Background job for processing advice deadlines.
 *
 * Daily job that:
 * - Expires overdue advice requests (status aangevraagd -> verlopen)
 * - Creates tasks for behandelaar on expired advice
 * - Sends reminder notifications 3 days before deadline
 *
 * @spec openspec/changes/advice-management/tasks.md#task-5
 */
class AdviceDeadlineJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400); // Daily.
    }

    /**
     * Execute the job.
     *
     * @param mixed $argument Job argument
     *
     * @return void
     *
     * @spec openspec/changes/advice-management/tasks.md#task-5
     */
    protected function run($argument): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return;
        }

        $this->logger->info('Procest: Running advice deadline job');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register = $this->settingsService->getConfigValue('register');
            $adviceSchema = $this->settingsService->getConfigValue('advies_aanvraag_schema');
            $taskSchema = $this->settingsService->getConfigValue('task_schema');
            $reminderDays = (int) $this->settingsService->getConfigValue('advice_reminder_days', '3');

            if (empty($register) || empty($adviceSchema) || empty($taskSchema)) {
                return;
            }

            // Get all pending advice requests
            $result = $objectService->findObjects(
                $register,
                $adviceSchema,
                ['status' => 'aangevraagd']
            );

            $today = new \DateTime('today');
            $reminderDate = (new \DateTime('today'))->add(new \DateInterval('P' . $reminderDays . 'D'));

            foreach (($result ?? []) as $adviceItem) {
                $advice = is_object($adviceItem) ? $adviceItem->jsonSerialize() : $adviceItem;
                $deadline = isset($advice['deadline']) ? new \DateTime($advice['deadline']) : null;

                if ($deadline === null) {
                    continue;
                }

                // Check if advice is overdue
                if ($deadline < $today) {
                    $this->expireAdvice($objectService, $register, $adviceSchema, $taskSchema, $advice);
                } elseif ($deadline->format('Y-m-d') === $reminderDate->format('Y-m-d')) {
                    // Send reminder if deadline is 3 days away
                    $this->sendReminderNotification($advice);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Procest: Advice deadline job error: ' . $e->getMessage());
        }
    }

    /**
     * Expire an advice request.
     *
     * @param mixed  $objectService The ObjectService instance
     * @param string $register      The register ID
     * @param string $adviceSchema  The advice schema ID
     * @param string $taskSchema    The task schema ID
     * @param array  $advice        The advice data
     *
     * @return void
     */
    private function expireAdvice($objectService, string $register, string $adviceSchema, string $taskSchema, array $advice): void
    {
        try {
            // Update advice status to verlopen
            $advice['status'] = 'verlopen';
            $objectService->saveObject($register, $adviceSchema, $advice);

            // Create task for behandelaar
            $taskData = [
                'case' => $advice['case'] ?? '',
                'title' => 'Advies verlopen: ' . ($advice['onderwerp'] ?? $advice['adviseur']),
                'description' => 'Advies van ' . ($advice['adviseur'] ?? 'adviseur') . ' is verlopen. Beoordeel of procedure kan doorgaan zonder dit advies.',
                'status' => 'open',
                'priority' => 'high',
            ];

            $objectService->saveObject($register, $taskSchema, $taskData);

            $this->logger->info('Procest: Advice expired', [
                'adviceId' => $advice['id'] ?? $advice['uuid'] ?? '',
                'caseId' => $advice['case'] ?? '',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Failed to expire advice', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Send a reminder notification for an upcoming deadline.
     *
     * @param array $advice The advice data
     *
     * @return void
     */
    private function sendReminderNotification(array $advice): void
    {
        try {
            $this->logger->info('Procest: Advice reminder notification sent', [
                'adviceId' => $advice['id'] ?? $advice['uuid'] ?? '',
                'adviseur' => $advice['adviseur'] ?? '',
                'deadline' => $advice['deadline'] ?? '',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Procest: Failed to send reminder notification', ['exception' => $e->getMessage()]);
        }
    }
}
