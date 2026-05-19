<?php

/**
 * Procest Consultation Notification Service
 *
 * Emits Nextcloud notifications for consultation lifecycle events.
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
 * @spec openspec/changes/consultation-management/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for emitting Nextcloud notifications on consultation lifecycle events.
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-3
 */
class ConsultationNotificationService
{
    /**
     * Constructor.
     *
     * @param INotificationManager $notificationManager The Nextcloud notification manager
     * @param LoggerInterface      $logger              The logger
     */
    public function __construct(
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify a user that a new consultation has been created.
     *
     * @param string $consultationId     The consultation UUID
     * @param string $recipientUserId    The Nextcloud UID of the recipient
     * @param string $consultationNumber The human-readable consultation number
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function notifyConsultationCreated(
        string $consultationId,
        string $recipientUserId,
        string $consultationNumber,
    ): void {
        $this->sendNotification(
            consultationId: $consultationId,
            recipientUserId: $recipientUserId,
            subject: 'consultation_created',
            params: ['consultationNumber' => $consultationNumber],
        );
    }//end notifyConsultationCreated()

    /**
     * Notify a user that a consultation status has changed.
     *
     * @param string $consultationId     The consultation UUID
     * @param string $recipientUserId    The Nextcloud UID of the recipient
     * @param string $newStatus          The new status value
     * @param string $consultationNumber The human-readable consultation number
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function notifyStatusChanged(
        string $consultationId,
        string $recipientUserId,
        string $newStatus,
        string $consultationNumber,
    ): void {
        $this->sendNotification(
            consultationId: $consultationId,
            recipientUserId: $recipientUserId,
            subject: 'consultation_status_changed',
            params: [
                'consultationNumber' => $consultationNumber,
                'newStatus'          => $newStatus,
            ],
        );
    }//end notifyStatusChanged()

    /**
     * Notify a user that advice has been submitted on a consultation.
     *
     * @param string $consultationId     The consultation UUID
     * @param string $recipientUserId    The Nextcloud UID of the recipient
     * @param string $adviesType         The advice type (e.g. 'positief', 'negatief')
     * @param string $consultationNumber The human-readable consultation number
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function notifyAdviceSubmitted(
        string $consultationId,
        string $recipientUserId,
        string $adviesType,
        string $consultationNumber,
    ): void {
        $this->sendNotification(
            consultationId: $consultationId,
            recipientUserId: $recipientUserId,
            subject: 'consultation_advice_submitted',
            params: [
                'consultationNumber' => $consultationNumber,
                'adviesType'         => $adviesType,
            ],
        );
    }//end notifyAdviceSubmitted()

    /**
     * Notify a user that a consultation deadline is approaching.
     *
     * @param string $consultationId     The consultation UUID
     * @param string $recipientUserId    The Nextcloud UID of the recipient
     * @param string $consultationNumber The human-readable consultation number
     * @param int    $daysLeft           Number of days until the deadline
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function notifyDeadlineWarning(
        string $consultationId,
        string $recipientUserId,
        string $consultationNumber,
        int $daysLeft,
    ): void {
        $this->sendNotification(
            consultationId: $consultationId,
            recipientUserId: $recipientUserId,
            subject: 'consultation_deadline_warning',
            params: [
                'consultationNumber' => $consultationNumber,
                'daysLeft'           => (string) $daysLeft,
            ],
        );
    }//end notifyDeadlineWarning()

    /**
     * Notify a user that a consultation is overdue.
     *
     * @param string $consultationId     The consultation UUID
     * @param string $recipientUserId    The Nextcloud UID of the recipient
     * @param string $consultationNumber The human-readable consultation number
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function notifyOverdue(
        string $consultationId,
        string $recipientUserId,
        string $consultationNumber,
    ): void {
        $this->sendNotification(
            consultationId: $consultationId,
            recipientUserId: $recipientUserId,
            subject: 'consultation_overdue',
            params: ['consultationNumber' => $consultationNumber],
        );
    }//end notifyOverdue()

    /**
     * Build and dispatch a single Nextcloud notification.
     *
     * Errors are caught and logged so that a notification failure does not
     * interrupt the calling workflow.
     *
     * @param string               $consultationId  The consultation UUID
     * @param string               $recipientUserId The Nextcloud UID of the recipient
     * @param string               $subject         The notification subject key
     * @param array<string, mixed> $params          Additional subject parameters
     *
     * @return void
     */
    private function sendNotification(
        string $consultationId,
        string $recipientUserId,
        string $subject,
        array $params=[],
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($recipientUserId)
                ->setDateTime(new \DateTime())
                ->setObject('consultation', $consultationId)
                ->setSubject($subject, $params);

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to send consultation notification: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }
    }//end sendNotification()
}//end class
