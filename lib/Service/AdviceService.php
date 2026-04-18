<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing advice requests (adviesAanvraag) lifecycle.
 *
 * Handles creation, status transitions, notification dispatch, and timeline recording.
 *
 * @spec openspec/changes/advice-management/tasks.md#task-3
 */
class AdviceService
{
    public function __construct(
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Create an advice request linked to a case.
     *
     * @param string $caseId The case UUID
     * @param array  $data   The advice request data (adviseur, type, onderwerp, deadline, questions)
     *
     * @return array The created advice request
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function createAdvice(string $caseId, array $data): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return ['error' => 'OpenRegister is not available'];
            }

            $register = (string) $this->settingsService->getConfigValue('register');
            $schema = (string) $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return ['error' => 'Advice schema not configured'];
            }

            // Ensure required fields
            if (empty($data['adviseur'])) {
                return ['error' => 'Adviseur is required'];
            }
            if (empty($data['type']) || !in_array($data['type'], ['intern', 'extern'], true)) {
                return ['error' => 'Type must be "intern" or "extern"'];
            }

            $adviceData = array_merge($data, [
                'case' => $caseId,
                'status' => 'aangevraagd',
                'requestedAt' => date('Y-m-d\TH:i:sP'),
            ]);

            $result = $objectService->saveObject($register, $schema, $adviceData);

            // Create task for adviseur
            $this->createAdviseurTask($caseId, $data, $result);

            // Record activity on case
            $this->recordCaseActivity($caseId, 'Advies aangevraagd van ' . $data['adviseur']);

            $this->logger->info('Procest: Advice created', [
                'adviceId' => $result->getUuid() ?? $result['id'] ?? 'unknown',
                'caseId' => $caseId,
            ]);

            return is_object($result) ? $result->jsonSerialize() : $result;
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to create advice', ['exception' => $e->getMessage()]);
            return ['error' => 'Failed to create advice request'];
        }
    }

    /**
     * Mark advice as received and store the document.
     *
     * @param string $adviceId The advice request UUID
     * @param string $fileId   The Nextcloud file ID of the advice document
     *
     * @return array The updated advice request
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function receiveAdvice(string $adviceId, string $fileId): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return ['error' => 'OpenRegister is not available'];
            }

            $register = (string) $this->settingsService->getConfigValue('register');
            $schema = (string) $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return ['error' => 'Advice schema not configured'];
            }

            // Get existing advice
            $advice = $objectService->findObject($register, $schema, $adviceId);
            if ($advice === null) {
                return ['error' => 'Advice request not found'];
            }

            $adviceArray = is_object($advice) ? $advice->jsonSerialize() : $advice;

            $updateData = array_merge($adviceArray, [
                'status' => 'ontvangen',
                'receivedAt' => date('Y-m-d\TH:i:sP'),
                'adviesDocument' => $fileId,
            ]);

            $result = $objectService->saveObject($register, $schema, $updateData);

            // Send notification to behandelaar
            $this->sendNotification(
                $adviceArray['case'] ?? '',
                'Advies ontvangen van ' . ($adviceArray['adviseur'] ?? 'adviseur'),
                'advice_received'
            );

            // Record activity
            $this->recordCaseActivity(
                $adviceArray['case'] ?? '',
                'Advies ontvangen van ' . ($adviceArray['adviseur'] ?? 'adviseur')
            );

            $this->logger->info('Procest: Advice received', ['adviceId' => $adviceId]);

            return is_object($result) ? $result->jsonSerialize() : $result;
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to receive advice', ['exception' => $e->getMessage()]);
            return ['error' => 'Failed to receive advice'];
        }
    }

    /**
     * Send a reminder notification to the adviseur.
     *
     * @param string $adviceId The advice request UUID
     *
     * @return void
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function sendReminder(string $adviceId): void
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return;
            }

            $register = (string) $this->settingsService->getConfigValue('register');
            $schema = (string) $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return;
            }

            $advice = $objectService->findObject($register, $schema, $adviceId);
            if ($advice === null) {
                return;
            }

            $adviceArray = is_object($advice) ? $advice->jsonSerialize() : $advice;

            $this->sendNotification(
                '',
                'Herinnering: advies verwacht voor ' . ($adviceArray['deadline'] ?? 'deadline'),
                'advice_reminder',
                $adviceArray['adviseur'] ?? ''
            );

            $this->logger->info('Procest: Reminder sent', ['adviceId' => $adviceId]);
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to send reminder', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Get all advice requests for a case.
     *
     * @param string $caseId The case UUID
     *
     * @return array List of advice requests
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function getAdviceForCase(string $caseId): array
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return [];
            }

            $register = (string) $this->settingsService->getConfigValue('register');
            $schema = (string) $this->settingsService->getConfigValue('advies_aanvraag_schema');

            if (empty($register) || empty($schema)) {
                return [];
            }

            $results = $objectService->findObjects(
                $register,
                $schema,
                ['case' => $caseId]
            );

            if (is_array($results)) {
                return array_map(
                    fn($item) => is_object($item) ? $item->jsonSerialize() : $item,
                    $results
                );
            }

            return [];
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to get advice for case', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Check if there are pending advice requests blocking workflow transition.
     *
     * @param string $caseId The case UUID
     *
     * @return array List of pending advice requests
     *
     * @spec openspec/changes/advice-management/tasks.md#task-3
     */
    public function checkGuard(string $caseId): array
    {
        try {
            $adviceList = $this->getAdviceForCase($caseId);

            return array_filter(
                $adviceList,
                fn($advice) => ($advice['status'] ?? '') === 'aangevraagd'
            );
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to check guard', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create a task for the adviseur.
     *
     * @param string $caseId The case UUID
     * @param array  $data   The advice request data
     * @param mixed  $result The created advice object
     *
     * @return void
     */
    private function createAdviseurTask(string $caseId, array $data, $result): void
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return;
            }

            $taskRegister = (string) $this->settingsService->getConfigValue('register');
            $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');

            if (empty($taskRegister) || empty($taskSchema)) {
                return;
            }

            $taskData = [
                'case' => $caseId,
                'title' => 'Advies uitbrengen voor ' . ($data['onderwerp'] ?? 'adviesaanvraag'),
                'description' => $data['questions'] ?? '',
                'assignee' => $data['adviseur'],
                'status' => 'open',
                'dueDate' => $data['deadline'] ?? date('Y-m-d', strtotime('+14 days')),
            ];

            $objectService->saveObject($taskRegister, $taskSchema, $taskData);
        } catch (Throwable $e) {
            $this->logger->error('Procest: Failed to create adviseur task', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Send a notification via NotificatieService if available.
     *
     * @param string $caseId   The case UUID (optional)
     * @param string $message  The notification message
     * @param string $type     The notification type
     * @param string $recipient The target user UID (optional)
     *
     * @return void
     */
    private function sendNotification(string $caseId, string $message, string $type, string $recipient = ''): void
    {
        try {
            $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
            if (!$appManager->isInstalled('notifications')) {
                return;
            }

            // Log the notification for now; actual implementation depends on NotificatieService
            $this->logger->info('Procest: Notification queued', [
                'case' => $caseId,
                'message' => $message,
                'type' => $type,
                'recipient' => $recipient,
            ]);
        } catch (Throwable $e) {
            $this->logger->debug('Procest: Could not send notification', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Record an activity entry on the case.
     *
     * @param string $caseId    The case UUID
     * @param string $activity  The activity description
     *
     * @return void
     */
    private function recordCaseActivity(string $caseId, string $activity): void
    {
        try {
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return;
            }

            $register = (string) $this->settingsService->getConfigValue('register');
            $caseSchema = (string) $this->settingsService->getConfigValue('case_schema');

            if (empty($register) || empty($caseSchema)) {
                return;
            }

            $case = $objectService->findObject($register, $caseSchema, $caseId);
            if ($case === null) {
                return;
            }

            $caseArray = is_object($case) ? $case->jsonSerialize() : $case;
            $activityLog = json_decode($caseArray['activity'] ?? '[]', true) ?? [];

            $activityLog[] = [
                'timestamp' => date('Y-m-d\TH:i:sP'),
                'actor' => $this->userSession->getUser()?->getUID() ?? 'system',
                'description' => $activity,
            ];

            $caseArray['activity'] = json_encode($activityLog);
            $objectService->saveObject($register, $caseSchema, $caseArray);
        } catch (Throwable $e) {
            $this->logger->debug('Procest: Could not record case activity', ['exception' => $e->getMessage()]);
        }
    }
}
