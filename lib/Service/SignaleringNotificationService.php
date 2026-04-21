<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\Notification\IManager;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Service for deadline signalering notifications.
 *
 * Dispatches in-app notifications via Nextcloud INotificationManager and
 * email notifications via n8n webhook integration.
 *
 * @spec openspec/changes/signalering-widgets/tasks.md#T02
 */
class SignaleringNotificationService
{
    public function __construct(
        private IManager $notificationManager,
        private IClientService $clientService,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send in-app notification for a deadline warning.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T02
     *
     * @param string $userId User to notify
     * @param array $case Case data
     * @param string $type 'warning' or 'overdue'
     * @return void
     */
    public function notifyDeadlineWarning(string $userId, array $case, string $type = 'warning'): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $caseId = $case['id'] ?? $case['uuid'] ?? 'unknown';
            $caseTitle = $case['title'] ?? $case['onderwerp'] ?? 'Case ' . $caseId;

            $notification
                ->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('case', $caseId)
                ->setSubject('deadline_' . $type, ['caseTitle' => $caseTitle])
                ->setLink('/cases/' . $caseId);

            $this->notificationManager->notify($notification);

            $this->logger->info('Procest: Deadline notification sent', [
                'userId' => $userId,
                'caseId' => $caseId,
                'type' => $type,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Procest: Failed to send deadline notification', [
                'userId' => $userId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send email notification via n8n webhook.
     *
     * Dispatches a notification to the configured n8n endpoint for email delivery.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T02
     *
     * @param string $userEmail Email address
     * @param array $case Case data
     * @param array $deadline Deadline information from SignaleringService
     * @param string $type 'warning' or 'overdue'
     * @return bool True if webhook was called, false otherwise
     */
    public function notifyByEmail(string $userEmail, array $case, array $deadline, string $type = 'warning'): bool
    {
        // Get n8n endpoint from settings
        $n8nWebhookUrl = $this->settingsService->getConfigValue('n8n_signalering_webhook_url');
        if (empty($n8nWebhookUrl)) {
            $this->logger->debug('Procest: n8n webhook not configured, skipping email notification');
            return false;
        }

        try {
            $caseId = $case['id'] ?? $case['uuid'] ?? 'unknown';
            $caseTitle = $case['title'] ?? $case['onderwerp'] ?? 'Case ' . $caseId;

            $payload = [
                'type' => 'deadline_notification',
                'notificationType' => $type,
                'userEmail' => $userEmail,
                'case' => [
                    'id' => $caseId,
                    'title' => $caseTitle,
                    'link' => '/cases/' . $caseId,
                ],
                'deadline' => $deadline,
                'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            ];

            $client = $this->clientService->newClient();
            $response = $client->post($n8nWebhookUrl, [
                'json' => $payload,
                'timeout' => 5,
            ]);

            $this->logger->info('Procest: Email notification dispatched to n8n', [
                'userEmail' => $userEmail,
                'caseId' => $caseId,
                'statusCode' => $response->getStatusCode(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Procest: Failed to dispatch email notification to n8n', [
                'userEmail' => $userEmail,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Trigger all configured notifications for a deadline event.
     *
     * @spec openspec/changes/signalering-widgets/tasks.md#T02
     *
     * @param string $userId User to notify
     * @param string $userEmail User email for email notifications
     * @param array $case Case data
     * @param array $deadline Deadline information
     * @param array $config Signalering configuration for the zaaktype
     * @param string $type 'warning' or 'overdue'
     * @return array Results of each notification attempt
     */
    public function triggerNotifications(
        string $userId,
        string $userEmail,
        array $case,
        array $deadline,
        array $config,
        string $type = 'warning'
    ): array {
        $results = [];
        $channels = $config['notificationChannels'] ?? ['in-app'];

        if (in_array('in-app', $channels) || in_array('all', $channels)) {
            $this->notifyDeadlineWarning($userId, $case, $type);
            $results['in-app'] = true;
        }

        if ((in_array('email', $channels) || in_array('all', $channels)) && !empty($userEmail)) {
            $results['email'] = $this->notifyByEmail($userEmail, $case, $deadline, $type);
        }

        return $results;
    }
}
