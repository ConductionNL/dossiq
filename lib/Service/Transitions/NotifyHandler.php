<?php

/**
 * Dossiq notify action handler.
 *
 * Action config shape: `{type: 'notify', userId?: '<uid>', message?: '<text>'}`.
 * Dispatches an in-app Nextcloud notification through the notification manager,
 * the same route MentionNotificationService and AdviceNotifier already take.
 * The subject key is rendered by {@see \OCA\Dossiq\Notification\Notifier}; a
 * subject that notifier does not know is dropped before anyone sees it, so the
 * two are changed together.
 *
 * REQ-STE-5-002 says a failed side effect never rolls back the status change.
 * It does not say a failed side effect reports success. A notification that was
 * not dispatched returns `succeeded: false`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `notify` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class NotifyHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param IManager $notificationManager Nextcloud notification manager
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the notify action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		$recipient = (string)($actionConfig['userId'] ?? ($case['assignee'] ?? ''));
		if ($recipient === '') {
			// Nobody to notify is a configuration gap, not a delivery. Saying
			// so lets the transition log show which action found no recipient.
			return new ActionResult(succeeded: false, error: 'notify_missing_recipient');
		}

		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '') {
			// The notification manager rejects an empty object id, and a
			// notification that names no case cannot be opened anyway.
			return new ActionResult(succeeded: false, error: 'notify_missing_case');
		}

		$transitionLabel = (string)($transitionContext['transitionLabel'] ?? '');
		$message = (string)($actionConfig['message'] ?? '');

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($recipient)
				->setDateTime(new DateTime())
				->setObject('case', $caseId)
				->setSubject(
					Notifier::SUBJECT_CASE_STATUS_CHANGED,
					[
						'caseId' => $caseId,
						'transitionLabel' => $transitionLabel,
						'message' => $message,
					]
				);

			$this->notificationManager->notify($notification);

			return new ActionResult(succeeded: true, data: ['userId' => $recipient]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'NotifyHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);

			return new ActionResult(succeeded: false, error: 'notify_failed');
		}//end try
	}//end handle()
}//end class
