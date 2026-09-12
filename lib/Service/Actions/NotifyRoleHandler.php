<?php

/**
 * Dossiq NotifyRoleHandler
 *
 * Resolves a role slug to its members and emits an in-app Nextcloud
 * notification to each. In dry-run mode it returns the resolved recipient
 * list and rendered message without queuing any notifications.
 *
 * Notifications go to Nextcloud's notification manager, the route
 * MentionNotificationService, AdviceNotifier and the transition `notify`
 * action already take. The subject key is rendered by
 * {@see \OCA\Dossiq\Notification\Notifier}; a subject that notifier does not
 * know is refused in `prepare()` and dropped before anyone sees it, so the
 * sender and the renderer change together.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use DateTime;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Handler for `notifyRole` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class NotifyRoleHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for NotifyRoleHandler.
	 *
	 * @param IManager $notificationManager Nextcloud notification manager.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The action type slug handled by this handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function type(): string {
		return 'notifyRole';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the role notification.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$roleSlug = (string)($actionConfig['roleSlug'] ?? '');
			$message = $this->renderTemplate(
				template: (string)($actionConfig['messageTemplate'] ?? ''),
				case: $case
			);

			$recipients = $this->resolveRoleMembers(roleSlug: $roleSlug, case: $case);
			$preview = [
				'roleSlug' => $roleSlug,
				'recipients' => $recipients,
				'message' => $message,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($roleSlug === '' || $recipients === []) {
				return new ActionResult(succeeded: false, error: 'no_recipients', data: $preview);
			}

			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			if ($caseId === '') {
				// The notification manager rejects an empty object id, and a
				// notification that names no case cannot be opened anyway.
				return new ActionResult(succeeded: false, error: 'missing_case_id', data: $preview);
			}

			foreach ($recipients as $userId) {
				$this->notifyOne(userId: $userId, caseId: $caseId, roleSlug: $roleSlug, message: $message);
			}

			$preview['notified'] = count($recipients);

			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'NotifyRoleHandler: failed to dispatch notification',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'notify_role_failed');
		}//end try
	}//end handle()

	/**
	 * Dispatch one notification.
	 *
	 * The role slug travels as a subject parameter rather than into the
	 * wording: it is workflow-configuration vocabulary, and a recipient who
	 * never opened the workflow editor has no way to read it.
	 *
	 * @param string $userId The recipient's user identifier.
	 * @param string $caseId The case the notification points at.
	 * @param string $roleSlug The role the recipient holds on the case.
	 * @param string $message The rendered message, possibly empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	private function notifyOne(string $userId, string $caseId, string $roleSlug, string $message): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('case', $caseId)
			->setSubject(
				Notifier::SUBJECT_CASE_ROLE_NOTIFIED,
				[
					'caseId' => $caseId,
					'roleSlug' => $roleSlug,
					'message' => $message,
				]
			);

		$this->notificationManager->notify($notification);
	}//end notifyOne()

	/**
	 * Resolve a role slug to a list of user identifiers on the case.
	 *
	 * V1 strategy: look up `case.<roleSlug>` for a single user, or
	 * `case.<roleSlug>Members[]` for a collection. RoleResolverService will
	 * supersede this lookup once role-based-step-routing lands.
	 *
	 * @param string $roleSlug Role slug.
	 * @param array $case Case object.
	 *
	 * @return array<int, string>
	 */
	private function resolveRoleMembers(string $roleSlug, array $case): array {
		if ($roleSlug === '') {
			return [];
		}

		$singleId = $this->memberId(member: ($case[$roleSlug] ?? null));
		if ($singleId !== '') {
			return [$singleId];
		}

		$multi = ($case[$roleSlug . 'Members'] ?? null);
		if (is_array($multi) === false) {
			return [];
		}

		$out = [];
		foreach ($multi as $member) {
			$memberId = $this->memberId(member: $member);
			if ($memberId !== '') {
				$out[] = $memberId;
			}
		}

		return $out;
	}//end resolveRoleMembers()

	/**
	 * Read a user identifier off a role member, which may be a bare uid
	 * string or an object with an `id` / `userId` key.
	 *
	 * @param mixed $member A single role member entry.
	 *
	 * @return string The user identifier, or empty string when unreadable.
	 */
	private function memberId(mixed $member): string {
		if (is_string($member) === true) {
			return $member;
		}

		if (is_array($member) === false) {
			return '';
		}

		return (string)($member['id'] ?? ($member['userId'] ?? ''));
	}//end memberId()
}//end class
