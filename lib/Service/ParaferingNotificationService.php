<?php

/**
 * Procest Parafering Notification Service
 *
 * Handles Nextcloud notifications for the B&W parafering workflow.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-parafering-actions-impl/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Nextcloud notifications for parafering workflow events.
 */
class ParaferingNotificationService
{
    /**
     * Constructor.
     *
     * @param IManager        $notificationManager The Nextcloud notification manager
     * @param LoggerInterface $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private readonly IManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send notification when a new parafering step is activated.
     *
     * @param string $actorUserId The user who should act on the step
     * @param string $onderwerp   The voorstel subject
     * @param string $voorstelId  The voorstel UUID
     * @param string $stepLabel   The step label (e.g. 'Afdelingshoofd')
     *
     * @return void
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function notifyStepActivated(
        string $actorUserId,
        string $onderwerp,
        string $voorstelId,
        string $stepLabel
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($actorUserId)
                ->setDateTime(new \DateTime())
                ->setObject('voorstel', $voorstelId)
                ->setSubject(
                        'parafering_step_activated',
                        [
                            'onderwerp' => $onderwerp,
                            'stepLabel' => $stepLabel,
                        ]
                        );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to send parafering step notification',
                [
                    'actor'     => $actorUserId,
                    'voorstel'  => $voorstelId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end notifyStepActivated()

    /**
     * Send notification when a voorstel is returned to the steller.
     *
     * @param string $stellerUserId The steller user who should receive the notification
     * @param string $onderwerp     The voorstel subject
     * @param string $voorstelId    The voorstel UUID
     * @param string $returnedBy    The actor who returned it
     * @param string $comment       The return comment
     *
     * @return void
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function notifyVoorstelReturned(
        string $stellerUserId,
        string $onderwerp,
        string $voorstelId,
        string $returnedBy,
        string $comment
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($stellerUserId)
                ->setDateTime(new \DateTime())
                ->setObject('voorstel', $voorstelId)
                ->setSubject(
                        'voorstel_returned',
                        [
                            'onderwerp'  => $onderwerp,
                            'returnedBy' => $returnedBy,
                            'comment'    => $comment,
                        ]
                        );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to send voorstel return notification',
                [
                    'steller'   => $stellerUserId,
                    'voorstel'  => $voorstelId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end notifyVoorstelReturned()

    /**
     * Send a reminder notification for an overdue parafering step.
     *
     * @param string $actorUserId The user who should act
     * @param string $onderwerp   The voorstel subject
     * @param string $voorstelId  The voorstel UUID
     * @param int    $daysWaiting Number of days the step has been waiting
     *
     * @return void
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function notifyParaferingReminder(
        string $actorUserId,
        string $onderwerp,
        string $voorstelId,
        int $daysWaiting
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($actorUserId)
                ->setDateTime(new \DateTime())
                ->setObject('voorstel', $voorstelId)
                ->setSubject(
                        'parafering_reminder',
                        [
                            'onderwerp'   => $onderwerp,
                            'daysWaiting' => $daysWaiting,
                        ]
                        );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Failed to send parafering reminder notification',
                [
                    'actor'     => $actorUserId,
                    'voorstel'  => $voorstelId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end notifyParaferingReminder()
}//end class
