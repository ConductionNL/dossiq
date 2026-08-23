<?php

/**
 * Dossiq Parafering Notification Service
 *
 * Handles Nextcloud notifications for the B&W parafering workflow.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/parafering-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Nextcloud notifications for parafering workflow events.
 */
class ParaferingNotificationService {
	/**
	 * Constructor.
	 *
	 * @param IManager $notificationManager The Nextcloud notification manager
	 * @param LoggerInterface $logger The logger
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
	 * @param string $subject The voorstel subject
	 * @param string $proposalId The voorstel UUID
	 * @param string $stepLabel The step label (e.g. 'Afdelingshoofd')
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function notifyStepActivated(
		string $actorUserId,
		string $subject,
		string $proposalId,
		string $stepLabel,
	): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($actorUserId)
				->setDateTime(new DateTime())
				->setObject('proposal', $proposalId)
				->setSubject(
					'parafering_step_activated',
					[
						'subject' => $subject,
						'stepLabel' => $stepLabel,
					]
				);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to send parafering step notification',
				[
					'actor' => $actorUserId,
					'proposal' => $proposalId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end notifyStepActivated()

	/**
	 * Send notification when a voorstel is returned to the steller.
	 *
	 * @param string $stellerUserId The steller user who should receive the notification
	 * @param string $subject The voorstel subject
	 * @param string $proposalId The voorstel UUID
	 * @param string $returnedBy The actor who returned it
	 * @param string $comment The return comment
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function notifyVoorstelReturned(
		string $stellerUserId,
		string $subject,
		string $proposalId,
		string $returnedBy,
		string $comment,
	): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($stellerUserId)
				->setDateTime(new DateTime())
				->setObject('proposal', $proposalId)
				->setSubject(
					'voorstel_returned',
					[
						'subject' => $subject,
						'returnedBy' => $returnedBy,
						'comment' => $comment,
					]
				);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Failed to send voorstel return notification',
				[
					'author' => $stellerUserId,
					'proposal' => $proposalId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end notifyVoorstelReturned()

	/*
	 * NO notifyParaferingReminder() HERE.
	 *
	 * It emitted a `parafering_reminder` Nextcloud notification for an overdue
	 * step. Nothing called it: there is no scheduled job or listener in this
	 * app that detects an overdue parafering step, so the reminder had no
	 * trigger. The live notifications on this service
	 * (`notifyVoorstelReturned` and its siblings) are driven by
	 * `ApprovalStepNotificationListener` and `ParafeerActieService`; the
	 * reminder is the one with no producer. Adding a job to fire it is a
	 * feature, not dead-code removal.
	 */
}//end class
