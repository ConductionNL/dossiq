<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SignaleringNotificationService;
use OCA\Procest\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SignaleringNotificationService class.
 *
 * @covers \OCA\Procest\Service\SignaleringNotificationService
 */
class SignaleringNotificationServiceTest extends TestCase
{
    private SignaleringNotificationService $service;
    private IManager $notificationManager;
    private IClientService $clientService;
    private SettingsService $settingsService;
    private LoggerInterface $logger;
    private INotification $notification;

    protected function setUp(): void
    {
        $this->notificationManager = $this->createMock(IManager::class);
        $this->clientService = $this->createMock(IClientService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->notification = $this->createMock(INotification::class);

        // Setup notification fluent interface
        $this->notification->method('setApp')->willReturn($this->notification);
        $this->notification->method('setUser')->willReturn($this->notification);
        $this->notification->method('setDateTime')->willReturn($this->notification);
        $this->notification->method('setObject')->willReturn($this->notification);
        $this->notification->method('setSubject')->willReturn($this->notification);
        $this->notification->method('setLink')->willReturn($this->notification);

        $this->notificationManager->method('createNotification')->willReturn($this->notification);

        $this->service = new SignaleringNotificationService(
            $this->notificationManager,
            $this->clientService,
            $this->settingsService,
            $this->logger,
        );
    }

    /**
     * Test in-app notification is sent to correct user.
     */
    public function testNotifyDeadlineWarningSendsToCorrectUser(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setUser')
            ->with('user-123')
            ->willReturn($this->notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($this->notification);

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $this->service->notifyDeadlineWarning('user-123', $case, 'warning');
    }

    /**
     * Test in-app notification includes correct subject type.
     */
    public function testNotifyDeadlineWarningUsesCorrectSubject(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setSubject')
            ->with(
                'deadline_warning',
                $this->callback(function (array $params): bool {
                    return isset($params['caseTitle']) && $params['caseTitle'] === 'Test Case';
                })
            )
            ->willReturn($this->notification);

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $this->service->notifyDeadlineWarning('user-123', $case, 'warning');
    }

    /**
     * Test overdue notification uses correct subject type.
     */
    public function testNotifyDeadlineOverdueUsesCorrectSubject(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setSubject')
            ->with(
                'deadline_overdue',
                $this->anything()
            )
            ->willReturn($this->notification);

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $this->service->notifyDeadlineWarning('user-123', $case, 'overdue');
    }

    /**
     * Test email notification is dispatched to n8n webhook.
     */
    public function testNotifyByEmailDispatchesToWebhook(): void
    {
        $this->settingsService
            ->method('getConfigValue')
            ->with('n8n_signalering_webhook_url')
            ->willReturn('https://n8n.example.com/webhook/signalering');

        $mockClient = $this->createMock(IClient::class);
        $this->clientService
            ->expects($this->once())
            ->method('newClient')
            ->willReturn($mockClient);

        $mockResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                'https://n8n.example.com/webhook/signalering',
                $this->callback(function (array $options): bool {
                    return isset($options['json']['type']) && $options['json']['type'] === 'deadline_notification';
                })
            )
            ->willReturn($mockResponse);

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $deadline = [
            'date' => '2026-05-01T00:00:00Z',
            'daysRemaining' => 7,
            'status' => 'warning',
        ];

        $result = $this->service->notifyByEmail('user@example.com', $case, $deadline, 'warning');

        $this->assertTrue($result);
    }

    /**
     * Test email notification returns false when webhook not configured.
     */
    public function testNotifyByEmailReturnsFalseWhenWebhookNotConfigured(): void
    {
        $this->settingsService
            ->method('getConfigValue')
            ->with('n8n_signalering_webhook_url')
            ->willReturn('');

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $deadline = [
            'date' => '2026-05-01T00:00:00Z',
            'daysRemaining' => 7,
            'status' => 'warning',
        ];

        $result = $this->service->notifyByEmail('user@example.com', $case, $deadline, 'warning');

        $this->assertFalse($result);
    }

    /**
     * Test email notification handles exception gracefully.
     */
    public function testNotifyByEmailHandlesExceptionGracefully(): void
    {
        $this->settingsService
            ->method('getConfigValue')
            ->with('n8n_signalering_webhook_url')
            ->willReturn('https://n8n.example.com/webhook/signalering');

        $mockClient = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($mockClient);

        $mockClient
            ->method('post')
            ->willThrowException(new \Exception('Network error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Procest: Failed to dispatch email notification to n8n',
                $this->anything()
            );

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $deadline = [
            'date' => '2026-05-01T00:00:00Z',
            'daysRemaining' => 7,
            'status' => 'warning',
        ];

        $result = $this->service->notifyByEmail('user@example.com', $case, $deadline, 'warning');

        $this->assertFalse($result);
    }

    /**
     * Test trigger notifications dispatches both in-app and email.
     */
    public function testTriggerNotificationsDispatchesBothChannels(): void
    {
        $this->notificationManager
            ->expects($this->once())
            ->method('notify');

        $this->settingsService
            ->method('getConfigValue')
            ->with('n8n_signalering_webhook_url')
            ->willReturn('https://n8n.example.com/webhook/signalering');

        $mockClient = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($mockClient);

        $mockResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
        $mockResponse->method('getStatusCode')->willReturn(200);

        $mockClient->method('post')->willReturn($mockResponse);

        $case = [
            'id' => 'case-456',
            'title' => 'Test Case',
        ];

        $deadline = [
            'date' => '2026-05-01T00:00:00Z',
            'daysRemaining' => 7,
            'status' => 'warning',
        ];

        $config = [
            'notificationChannels' => ['in-app', 'email'],
        ];

        $results = $this->service->triggerNotifications(
            'user-123',
            'user@example.com',
            $case,
            $deadline,
            $config,
            'warning'
        );

        $this->assertTrue($results['in-app']);
        $this->assertTrue($results['email']);
    }
}
