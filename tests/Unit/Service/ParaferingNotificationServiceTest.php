<?php

/**
 * ParaferingNotificationService Unit Tests
 *
 * Tests for the Procest ParaferingNotificationService that sends Nextcloud
 * notifications for the B&W parafering workflow.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ParaferingNotificationService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ParaferingNotificationService class.
 *
 * @covers \OCA\Procest\Service\ParaferingNotificationService
 */
class ParaferingNotificationServiceTest extends TestCase
{

    /**
     * The mocked Nextcloud notification manager.
     *
     * @var IManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private IManager $notificationManager;

    /**
     * The mocked logger interface.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var ParaferingNotificationService
     */
    private ParaferingNotificationService $service;

    /**
     * The mocked notification instance.
     *
     * @var INotification|\PHPUnit\Framework\MockObject\MockObject
     */
    private INotification $notification;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->notificationManager = $this->createMock(IManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->notification        = $this->createMock(INotification::class);

        // The notification fluent builder chain.
        $this->notification->method('setApp')->willReturn($this->notification);
        $this->notification->method('setUser')->willReturn($this->notification);
        $this->notification->method('setDateTime')->willReturn($this->notification);
        $this->notification->method('setObject')->willReturn($this->notification);
        $this->notification->method('setSubject')->willReturn($this->notification);

        $this->notificationManager
            ->method('createNotification')
            ->willReturn($this->notification);

        $this->service = new ParaferingNotificationService(
            $this->notificationManager,
            $this->logger,
        );

    }//end setUp()


    /**
     * Test that notifyStepActivated sends a notification to the correct user.
     *
     * @return void
     */
    public function testNotifyStepActivatedSendsNotificationToActor(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setUser')
            ->with('actor-user-1')
            ->willReturn($this->notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($this->notification);

        $this->service->notifyStepActivated(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            stepLabel: 'Afdelingshoofd',
        );

    }//end testNotifyStepActivatedSendsNotificationToActor()


    /**
     * Test that notifyStepActivated sets the correct notification subject.
     *
     * @return void
     */
    public function testNotifyStepActivatedSetsCorrectSubject(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setSubject')
            ->with(
                'parafering_step_activated',
                $this->callback(
                    function (array $params): bool {
                        return isset($params['onderwerp']) === true
                            && isset($params['stepLabel']) === true
                            && $params['onderwerp'] === 'Testvoorstel'
                            && $params['stepLabel'] === 'Afdelingshoofd';
                    }
                )
            )
            ->willReturn($this->notification);

        $this->service->notifyStepActivated(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            stepLabel: 'Afdelingshoofd',
        );

    }//end testNotifyStepActivatedSetsCorrectSubject()


    /**
     * Test that notifyStepActivated sets the app to Application::APP_ID.
     *
     * @return void
     */
    public function testNotifyStepActivatedSetsAppId(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setApp')
            ->with(Application::APP_ID)
            ->willReturn($this->notification);

        $this->service->notifyStepActivated(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            stepLabel: 'Afdelingshoofd',
        );

    }//end testNotifyStepActivatedSetsAppId()


    /**
     * Test that notifyVoorstelReturned sends a notification to the steller.
     *
     * @return void
     */
    public function testNotifyVoorstelReturnedSendsNotificationToSteller(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setUser')
            ->with('steller-user-1')
            ->willReturn($this->notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($this->notification);

        $this->service->notifyVoorstelReturned(
            stellerUserId: 'steller-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            returnedBy: 'manager-user',
            comment: 'Aanpassing nodig',
        );

    }//end testNotifyVoorstelReturnedSendsNotificationToSteller()


    /**
     * Test that notifyVoorstelReturned includes the return comment in subject params.
     *
     * @return void
     */
    public function testNotifyVoorstelReturnedIncludesComment(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setSubject')
            ->with(
                'voorstel_returned',
                $this->callback(
                    function (array $params): bool {
                        return isset($params['comment']) === true
                            && $params['comment'] === 'Aanpassing nodig';
                    }
                )
            )
            ->willReturn($this->notification);

        $this->service->notifyVoorstelReturned(
            stellerUserId: 'steller-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            returnedBy: 'manager-user',
            comment: 'Aanpassing nodig',
        );

    }//end testNotifyVoorstelReturnedIncludesComment()


    /**
     * Test that notifyParaferingReminder sends a reminder to the actor.
     *
     * @return void
     */
    public function testNotifyParaferingReminderSendsToActor(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setUser')
            ->with('actor-user-1')
            ->willReturn($this->notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify');

        $this->service->notifyParaferingReminder(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            daysWaiting: 3,
        );

    }//end testNotifyParaferingReminderSendsToActor()


    /**
     * Test that notifyParaferingReminder includes daysWaiting in subject params.
     *
     * @return void
     */
    public function testNotifyParaferingReminderIncludesDaysWaiting(): void
    {
        $this->notification
            ->expects($this->once())
            ->method('setSubject')
            ->with(
                'parafering_reminder',
                $this->callback(
                    function (array $params): bool {
                        return isset($params['daysWaiting']) === true
                            && $params['daysWaiting'] === 5;
                    }
                )
            )
            ->willReturn($this->notification);

        $this->service->notifyParaferingReminder(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            daysWaiting: 5,
        );

    }//end testNotifyParaferingReminderIncludesDaysWaiting()


    /**
     * Test that notification exceptions are caught and logged as warnings.
     *
     * @return void
     */
    public function testNotificationExceptionIsCaughtAndLogged(): void
    {
        $this->notificationManager
            ->method('notify')
            ->willThrowException(new \RuntimeException('Notification service unavailable'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to send parafering step notification'),
                $this->anything()
            );

        // Should not throw — exception must be swallowed.
        $this->service->notifyStepActivated(
            actorUserId: 'actor-user-1',
            onderwerp: 'Testvoorstel',
            voorstelId: 'voorstel-uuid-123',
            stepLabel: 'Afdelingshoofd',
        );

    }//end testNotificationExceptionIsCaughtAndLogged()


}//end class
