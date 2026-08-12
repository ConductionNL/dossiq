<?php

/**
 * Procest Advice Notifier.
 *
 * The whole notification fan-out for advice requests. Split out of
 * AdviceService so that service keeps only the workflow orchestration:
 * building a Nextcloud notification, choosing the recipient for each
 * status transition, and swallowing a notification failure so it can
 * never break the transaction that triggered it — one concern, one
 * collaborator.
 *
 * The acting caller's uid is passed IN rather than resolved here: this
 * class must never be the place that decides who someone is.
 *
 * @category Service
 * @package  OCA\Procest\Service\Advice
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Advice;

use DateTime;
use OCA\Procest\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches the advice-workflow notifications.
 *
 * @spec openspec/specs/advice-management/spec.md
 */
class AdviceNotifier {
	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager The notification manager.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a Nextcloud notification to a user.
	 *
	 * @param string $userId Recipient user UID.
	 * @param string $subject Notification subject key.
	 * @param string $objectId The object UUID (case or advice).
	 * @param string $message Additional message context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function sendUserNotification(
		string $userId,
		string $subject,
		string $objectId,
		string $message = '',
	): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('advies', $objectId)
				->setSubject($subject, ['object' => $objectId]);

			if ($message !== '') {
				$notification->setMessage('plain', ['message' => $message]);
			}

			$this->notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to send advice notification: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}
	}//end sendUserNotification()

	/**
	 * Fire the notification that matches a status transition.
	 *
	 * @param string $to Target status.
	 * @param array<string, mixed> $current Current advice record (pre-update).
	 * @param string $adviceId The advice UUID.
	 * @param string $callerId The acting caller's UID, or '' in a session-less context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function fireTransitionNotification(
		string $to,
		array $current,
		string $adviceId,
		string $callerId,
	): void {
		if ($to === 'aangevraagd') {
			$adviseur = (string)($current['adviseur'] ?? '');
			if ($adviseur !== '') {
				$this->sendUserNotification(
					userId: $adviseur,
					subject: 'advies_aangevraagd',
					objectId: $adviceId,
					message: (string)($current['onderwerp'] ?? '')
				);
			}

			return;
		}

		if ($to === 'ontvangen' && $callerId !== '') {
			$this->sendUserNotification(
				userId: $callerId,
				subject: 'advies_ontvangen',
				objectId: $adviceId
			);
		}
	}//end fireTransitionNotification()

	/**
	 * Notify the adviseur that an advice request was created.
	 *
	 * @param string $caseId UUID of the case.
	 * @param array<string, mixed> $payload The persisted adviceRequest payload.
	 * @param array<string, mixed> $saved The normalized saveObject() result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/advice-management/spec.md
	 */
	public function notifyAdviseur(string $caseId, array $payload, array $saved): void {
		$adviseur = $payload['adviseur'];
		if ($adviseur === '') {
			return;
		}

		$notificationObjectId = $saved['id'] ?? $caseId;

		$this->sendUserNotification(
			userId: $adviseur,
			subject: 'advice_requested',
			objectId: $notificationObjectId,
			message: 'Adviesaanvraag voor zaak ' . $caseId
		);
	}//end notifyAdviseur()
}//end class
