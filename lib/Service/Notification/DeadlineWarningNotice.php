<?php

/**
 * Dossiq deadline warning notice.
 *
 * The Nextcloud notification a handler gets when a statutory deadline is
 * seven days away or has passed: about the case, with the days remaining
 * (negative once overdue) and a warning or overdue subject. A notice that
 * cannot be sent is logged and does not undo the warning. Split out of
 * {@see \OCA\Dossiq\Service\WOODeadlineService}, which keeps reading the
 * case, counting the days and deciding when to warn.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-4
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use DateTime;
use OCA\Dossiq\Kernel\AppIdentity;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Notifies the handler that a deadline is near or has passed.
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-4
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DeadlineWarningNotice {

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager Creates and sends the notification.
	 * @param LoggerInterface      $logger              Logs a notice that could not be sent.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a deadline notification to the behandelaar.
	 *
	 * @param string                                                     $userId  The user to notify
	 * @param array{caseId: string, daysRemaining: int, isOverdue: bool} $warning The case UUID, the days remaining
	 *                                                                            (negative if overdue) and whether
	 *                                                                            the deadline has passed
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-4
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function send(string $userId, array $warning): void {
		try {
			$notification = $this->deadlineNotification(userId: $userId, warning: $warning);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to send WOO deadline notification: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID, 'caseId' => $warning['caseId']],
			);
		}//end try
	}//end send()

	/**
	 * The notification subject: overdue, or a warning.
	 *
	 * @param bool $isOverdue Whether the deadline has passed
	 *
	 * @return string The subject.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-4
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function deadlineSubject(bool $isOverdue): string {
		$subject = 'woo_deadline_warning';
		if ($isOverdue === true) {
			$subject = 'woo_deadline_overdue';
		}

		return $subject;
	}//end deadlineSubject()

	/**
	 * The deadline notification for the behandelaar.
	 *
	 * @param string                                                     $userId  The user to notify
	 * @param array{caseId: string, daysRemaining: int, isOverdue: bool} $warning The case UUID, the days remaining
	 *                                                                            (negative if overdue) and whether
	 *                                                                            the deadline has passed
	 *
	 * @return INotification The notification.
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-4
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function deadlineNotification(string $userId, array $warning): INotification {
		$subject = $this->deadlineSubject(isOverdue: $warning['isOverdue']);
		$caseId = $warning['caseId'];

		$notification = $this->notificationManager->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('woo_deadline', $caseId)
			->setSubject(
				$subject,
				['caseId' => $caseId, 'daysRemaining' => $warning['daysRemaining']]
			);

		return $notification;
	}//end deadlineNotification()
}//end class
