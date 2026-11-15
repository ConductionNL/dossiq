<?php

/**
 * Dossiq Advice Notifier.
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
 * @package  OCA\Dossiq\Service\Advice
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Advice;

use DateTime;
use OCA\Dossiq\Kernel\AppIdentity;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
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
			$this->notificationManager->notify(
				$this->notificationFor(userId: $userId, about: ['subject' => $subject, 'objectId' => $objectId, 'message' => $message])
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to send advice notification: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
		}
	}//end sendUserNotification()

	/**
	 * The advice notification for a user, with its message when there is one.
	 *
	 * @param string                                                  $userId Recipient user UID.
	 * @param array{subject: string, objectId: string, message: string} $about  The subject key, the object UUID and the message.
	 *
	 * @return INotification The notification, not yet sent.
	 */
	private function notificationFor(string $userId, array $about): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(AppIdentity::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('advice', $about['objectId'])
			->setSubject($about['subject'], ['object' => $about['objectId']]);

		if ($about['message'] !== '') {
			$notification->setMessage('plain', ['message' => $about['message']]);
		}

		return $notification;
	}//end notificationFor()

	/**
	 * Fire the notification that matches a status transition.
	 *
	 * @param string $to Target status.
	 * @param array<string, mixed> $current Current advice record (pre-update).
	 * @param string $adviceId The advice UUID.
	 * @param string $callerId The acting caller's UID, or '' in a session-less context.
	 * @param string $summary  The received advice's conclusion and text, for the notification.
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
		string $summary='',
	): void {
		if ($to === 'requested') {
			$this->notifyRequested(current: $current, adviceId: $adviceId);

			return;
		}

		if ($to === 'received' && $callerId !== '') {
			$this->sendUserNotification(
				userId: $callerId,
				subject: 'advies_ontvangen',
				objectId: $adviceId,
				message: $summary
			);
		}
	}//end fireTransitionNotification()

	/**
	 * Tell the advisor an advice was requested of them, when the advice names one.
	 *
	 * @param array<string, mixed> $current  The advice as it stands.
	 * @param string               $adviceId The advice id.
	 *
	 * @return void
	 */
	private function notifyRequested(array $current, string $adviceId): void {
		$advisor = (string)($current['advisor'] ?? '');
		if ($advisor !== '') {
			$this->sendUserNotification(
				userId: $advisor,
				subject: 'advies_aangevraagd',
				objectId: $adviceId,
				message: (string)($current['subject'] ?? '')
			);
		}
	}//end notifyRequested()

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
		$advisor = $payload['advisor'];
		if ($advisor === '') {
			return;
		}

		$notificationObjectId = $saved['id'] ?? $caseId;

		$this->sendUserNotification(
			userId: $advisor,
			subject: 'advice_requested',
			objectId: $notificationObjectId,
			message: 'Adviesaanvraag voor zaak ' . $caseId
		);
	}//end notifyAdviseur()
}//end class
