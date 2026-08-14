<?php

/**
 * Approval Step Notification Listener
 *
 * Bridges OpenRegister's approval-workflow events to procest's parafering
 * notifications. Subscribes to OpenRegister's ApprovalStepApprovedEvent and
 * ApprovalStepRejectedEvent (dispatched by OpenRegister's ApprovalService after
 * each step state change) and emits the corresponding Nextcloud notification:
 *
 *   - on approval that advances a next step -> notify the next parafeerder
 *     (members of the next step's role group);
 *   - on rejection (terugsturen) -> notify the voorstel's steller.
 *
 * This replaces the imperative notifyStepActivated() call path with an
 * event-driven one (ADR-022 / migrate-parafering-to-or-approval-workflow): the
 * routing services no longer push notifications directly off the in-array
 * advance; OpenRegister's approval events are the single source of truth.
 *
 * @category Listener
 * @package  OCA\Procest\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/parafering-via-or-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Listener;

use OCA\Procest\Service\ParaferingNotificationService;
use OCA\Procest\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listener that translates OpenRegister approval-step events into procest
 * parafering notifications.
 *
 * The OpenRegister event classes are referenced by fully-qualified name and
 * resolved via duck-typing on the dispatched event so this app does not carry
 * a hard compile-time dependency on the optional OpenRegister app.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/parafering-via-or-approval/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalStepNotificationListener implements IEventListener {
	/**
	 * OpenRegister approved-event class name.
	 */
	private const EVENT_APPROVED = 'OCA\OpenRegister\Event\ApprovalStepApprovedEvent';

	/**
	 * OpenRegister rejected-event class name.
	 */
	private const EVENT_REJECTED = 'OCA\OpenRegister\Event\ApprovalStepRejectedEvent';

	/**
	 * Constructor.
	 *
	 * @param ParaferingNotificationService $notificationService The procest notification service.
	 * @param SettingsService $settingsService Procest settings bridge (voorstel lookup).
	 * @param IGroupManager $groupManager Group manager (resolve role members).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ParaferingNotificationService $notificationService,
		private readonly SettingsService $settingsService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister approval-step event.
	 *
	 * @param Event $event The dispatched OpenRegister approval-step event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parafering-via-or-approval/spec.md
	 */
	public function handle(Event $event): void {
		try {
			$class = get_class($event);
			if ($class === self::EVENT_APPROVED) {
				$this->handleApproved(event: $event);
				return;
			}

			if ($class === self::EVENT_REJECTED) {
				$this->handleRejected(event: $event);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: approval-step notification listener failed',
				['event' => get_class($event), 'exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Notify the next parafeerder after a step approval advances the chain.
	 *
	 * @param Event $event The ApprovalStepApprovedEvent (duck-typed).
	 *
	 * @return void
	 */
	private function handleApproved(Event $event): void {
		if (method_exists($event, 'getNextStep') === false || method_exists($event, 'getObjectUuid') === false) {
			return;
		}

		$nextStep = $event->getNextStep();
		if ($nextStep === null) {
			// Final step approved — chain complete; the steller is notified by
			// the accordering path in ParafeerActieService.
			return;
		}

		$objectUuid = (string)$event->getObjectUuid();
		$proposal = $this->loadProposal(objectUuid: $objectUuid);
		$onderwerp = (string)($proposal['onderwerp'] ?? '');

		$role = '';
		if (is_callable([$nextStep, 'getRole']) === true) {
			$role = (string)($nextStep->getRole() ?? '');
		}

		foreach ($this->resolveGroupMembers(role: $role) as $userId) {
			$this->notificationService->notifyStepActivated(
				$userId,
				$onderwerp,
				$objectUuid,
				$role
			);
		}
	}//end handleApproved()

	/**
	 * Notify the steller when a step is rejected (terugsturen).
	 *
	 * @param Event $event The ApprovalStepRejectedEvent (duck-typed).
	 *
	 * @return void
	 */
	private function handleRejected(Event $event): void {
		if (method_exists($event, 'getObjectUuid') === false) {
			return;
		}

		$objectUuid = (string)$event->getObjectUuid();
		$proposal = $this->loadProposal(objectUuid: $objectUuid);
		$steller = (string)($proposal['steller'] ?? '');
		if ($steller === '') {
			return;
		}

		$rejectedBy = '';
		if (method_exists($event, 'getUserId') === true) {
			$rejectedBy = (string)$event->getUserId();
		}

		$comment = '';
		if (method_exists($event, 'getStep') === true) {
			$step = $event->getStep();
			if (is_object($step) === true && is_callable([$step, 'getComment']) === true) {
				$comment = $this->extractCommentText(comment: (string)($step->getComment() ?? ''));
			}
		}

		$this->notificationService->notifyVoorstelReturned(
			$steller,
			(string)($proposal['onderwerp'] ?? ''),
			$objectUuid,
			$rejectedBy,
			$comment
		);
	}//end handleRejected()

	/**
	 * Load a voorstel by UUID via OpenRegister's ObjectService (best-effort).
	 *
	 * @param string $objectUuid The voorstel UUID.
	 *
	 * @return array<string, mixed> The voorstel array, or an empty array.
	 */
	private function loadProposal(string $objectUuid): array {
		if ($objectUuid === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('voorstel_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$proposal = $objectService->find($objectUuid, register: $register, schema: $schema);
			return $this->normalizeToArray(value: $proposal);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: could not load voorstel for approval notification',
				['voorstel' => $objectUuid, 'exception' => $e->getMessage()]
			);
		}

		return [];
	}//end loadVoorstel()

	/**
	 * Normalize an OpenRegister return value (array or jsonSerializable) to an array.
	 *
	 * @param mixed $value The ObjectService return value.
	 *
	 * @return array<string, mixed> The normalized array, or an empty array.
	 */
	private function normalizeToArray($value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end normalizeToArray()

	/**
	 * Resolve the Nextcloud user IDs that are members of a role group.
	 *
	 * @param string $role The Nextcloud group ID.
	 *
	 * @return array<int, string> The member user IDs (empty when the group is unknown).
	 */
	private function resolveGroupMembers(string $role): array {
		if ($role === '') {
			return [];
		}

		$group = $this->groupManager->get($role);
		if ($group === null) {
			// Not a group: treat the role token as a direct user UID.
			return [$role];
		}

		$userIds = [];
		foreach ($group->getUsers() as $user) {
			$userIds[] = $user->getUID();
		}

		return $userIds;
	}//end resolveGroupMembers()

	/**
	 * Extract the human-readable text from an OpenRegister comment field.
	 *
	 * The comment may be a plain string or the JSON metadata-in-comment shape
	 * `{"text": "...", "_meta": {...}}` written by ParaferingApprovalBridge.
	 *
	 * @param string $comment The raw comment.
	 *
	 * @return string The human-readable text.
	 */
	private function extractCommentText(string $comment): string {
		if ($comment === '' || str_starts_with($comment, '{') === false) {
			return $comment;
		}

		$decoded = json_decode($comment, true);
		if (is_array($decoded) === true && isset($decoded['text']) === true) {
			return (string)$decoded['text'];
		}

		return $comment;
	}//end extractCommentText()
}//end class
