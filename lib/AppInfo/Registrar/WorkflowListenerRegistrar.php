<?php

/**
 * Dossiq workflow listener registrar.
 *
 * The two delegation-boundary listeners: the AWB termijn binding that runs on
 * case creation, and the decidesk decision-outcome listener that materialises a
 * ZGW Besluit from a concluded decision. Both are pure observers (ADR-022).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Listener\DecisionConcludedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the termijnbewaking and decision-outcome listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */
class WorkflowListenerRegistrar {
	/**
	 * Register the termijn and decision listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerTermListeners(context: $context);
		$this->registerDecisionListeners(context: $context);
	}//end register()

	/**
	 * Register termijnbewaking (AWB deadline engine) listeners.
	 *
	 * On case creation, an AWB TermijnInstance is automatically bound to
	 * the case using the active TermijnDefinitie for the zaaktype. The
	 * listener is a pure observer (ADR-022); all logic lives in
	 * {@see \OCA\Dossiq\Service\TermijnService}.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	private function registerTermListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DeadlineCaseCreatedListener::class
		);
	}//end registerTermijnListeners()

	/**
	 * Register the decidesk decision-outcome listener.
	 *
	 * Dossiq delegates contract / besluit / bezwaar / advice DECISIONS to
	 * decidesk by dispatching `DecisionRequestedEvent`; the terminal outcome
	 * arrives back as decidesk's `DecisionConcludedEvent`. This listener
	 * materialises the ZGW `Besluit` from that outcome (filtered to this app via
	 * `getSourceApp()`). The event class is registered by FQN string and only
	 * when decidesk is installed, so dossiq carries no hard compile-time
	 * dependency on the optional decidesk app.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossiq-delegation-via-events/specs/contract-decision-delegation/spec.md#requirement-req-pdcd-003-the-zgw-besluit-is-materialised-from-the-decisionconcludedevent
	 */
	private function registerDecisionListeners(IRegistrationContext $context): void {
		if (class_exists('\\OCA\\Decidesk\\Event\\DecisionConcludedEvent') === false) {
			return;
		}

		// FQN string (not ::class) so there is no hard compile-time dependency
		// on the optional decidesk app — mirrors the OpenRegister approval-event
		// registration in BezwaarListenerRegistrar.
		$context->registerEventListener(
			event: 'OCA\Decidesk\Event\DecisionConcludedEvent',
			listener: DecisionConcludedListener::class
		);
	}//end registerDecisionListeners()
}//end class
