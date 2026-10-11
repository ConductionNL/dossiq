<?php

/**
 * Dossiq case decision access listener
 *
 * Answers OpenRegister's vetoable DecisionChangeRequestedEvent for objects
 * that belong to a case whose case type keeps records private.
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Access\CaseDecisionAccess;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Allow or refuse a change to another reviewer's decision on a case's object.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
 */
class CaseDecisionAccessListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CaseDecisionAccess $access The case-side answer.
	 * @param LoggerInterface    $logger Logger.
	 */
	public function __construct(
		private readonly CaseDecisionAccess $access,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the question for an object on a guarded case; leave every other answer as it was.
	 *
	 * A read that throws refuses: the question fails closed, as OpenRegister's own does.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-decision-on-a-cases-file-is-changed-by-its-decider-or-a-supervisor-of-that-case-req-cra-006
	 */
	public function handle(Event $event): void {
		if ($event instanceof DecisionChangeRequestedEvent === false) {
			return;
		}

		$objectUuid = trim((string)($event->getSubject()['objectUuid'] ?? ''));
		if ($objectUuid === '') {
			return;
		}

		try {
			$verdict = $this->access->mayChange(objectUuid: $objectUuid, userId: $event->getActorId());
		} catch (Throwable $error) {
			$this->logger->warning(
				'Dossiq: the case of a decision could not be read; the change is refused (REQ-CRA-006)',
				['object' => $objectUuid, 'error' => $error->getMessage()]
			);
			$event->refuse(CaseDecisionAccess::REFUSAL);
			return;
		}

		if ($verdict === true) {
			$event->allow();
		}

		if ($verdict === false) {
			$event->refuse(CaseDecisionAccess::REFUSAL);
		}
	}//end handle()
}//end class
