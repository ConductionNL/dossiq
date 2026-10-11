<?php

/**
 * Dossiq case access listener registrar.
 *
 * Binds the guard that keeps a case with private records from changing hands
 * without a supervisor (REQ-CRA-005): before the save to refuse, after it to
 * reconcile the read grants.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
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

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseDecisionAccessListener;
use OCA\Dossiq\Listener\CaseReassignGuardListener;
use OCA\OpenRegister\Event\DecisionChangeRequestedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case reassign guard on the update before and after the save,
 * and the case's answer to OpenRegister's decision change question.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
 */
class CaseAccessListenerRegistrar {
	/**
	 * The events the guard listens to: the pre-persist one refuses, the post-persist one reconciles.
	 *
	 * @var list<class-string>
	 */
	public const EVENTS = [ObjectUpdatingEvent::class, ObjectUpdatedEvent::class];

	/**
	 * Register the guard.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-with-private-records-changes-hands-only-through-a-supervisor-req-cra-005
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::EVENTS as $event) {
			$context->registerEventListener(event: $event, listener: CaseReassignGuardListener::class);
		}

		// REQ-CRA-006: OpenRegister asks who may change another reviewer's
		// decision. Registered by name; without OpenRegister nothing dispatches it.
		$context->registerEventListener(event: DecisionChangeRequestedEvent::class, listener: CaseDecisionAccessListener::class);
	}//end register()
}//end class
