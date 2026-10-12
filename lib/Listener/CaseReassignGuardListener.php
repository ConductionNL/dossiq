<?php

/**
 * Dossiq case reassign guard.
 *
 * A case type can keep records private to the people on a case
 * (`caseType.reviewerAccess`, case-reviewer-access). The assignee reads every
 * one of those records, so handing the case to someone else hands the records
 * on. This guard refuses that change before it is saved unless a supervisor
 * or an administrator makes it, and after an accepted change it reconciles
 * the case so the former assignee's read grants go and the new one's arrive.
 * It is generic: any case type that declares reviewer access is guarded, and
 * a case type that does not is left alone.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuse a change of assignee on a case with private records unless a supervisor makes it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
 */
class CaseReassignGuardListener implements IEventListener {

	use SavedObjectPayload;

	/**
	 * The field that names who handles the case.
	 */
	private const ASSIGNEE = 'assignee';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The configured case schema.
	 * @param CaseReviewerAccess $access          Who supervises, and the reconciler.
	 * @param IUserSession       $userSession     The caller.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseReviewerAccess $access,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse before the save, reconcile after it.
	 *
	 * A write without a signed-in user (a background job, an import, a flow)
	 * is not a handler handing a case on, and is let through; the reconcile
	 * after the save still moves the grants.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent === false && $event instanceof ObjectUpdatedEvent === false)) {
			return;
		}

		$before = $this->previousObject(event: $event);
		$after = $this->savedObject(event: $event);
		if ($before === null || $after === null || $this->isCase(object: $before) === false
			|| $this->unchanged(before: $before, after: $after, fields: [self::ASSIGNEE]) === true
		) {
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->access->reconcile(caseId: (string)($after['id'] ?? ''));
			return;
		}

		$this->refuseUnlessSupervised(event: $event, before: $before);
	}//end handle()

	/**
	 * Stop the update when the caller may not hand this case on.
	 *
	 * @param ObjectUpdatingEvent  $event  The pre-persist event.
	 * @param array<string, mixed> $before The case before the change.
	 *
	 * @return void
	 */
	private function refuseUnlessSupervised(ObjectUpdatingEvent $event, array $before): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$refusal = $this->access->reassignRefusal(case: $before, userId: $user->getUID());
		if ($refusal === null) {
			return;
		}

		$event->setErrors($refusal);
		$event->stopPropagation();

		$this->logger->info(
			'Dossiq: refused handing on a case with private records without a supervisor (REQ-CRA-005)',
			['case' => (string)($before['id'] ?? ''), 'user' => $user->getUID()]
		);
	}//end refuseUnlessSupervised()

	/**
	 * Whether the object is a case.
	 *
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return bool
	 */
	private function isCase(array $object): bool {
		return $this->inSchema(object: $object, configured: $this->settingsService->getConfigValue('case_schema'));
	}//end isCase()
}//end class
