<?php

/**
 * Dossiq bezwaar / parafering listener registrar.
 *
 * The bezwaar and parafering listeners that are deliberately NOT narrowed to a
 * register/schema interest. The narrowed ones are subscribed from boot() by
 * {@see BezwaarSubscriptionRegistrar} — see that class for why the split exists.
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Listener\ApprovalStepNotificationListener;
use OCA\Dossiq\Listener\BezwaarAdviceRequestedListener;
use OCA\Dossiq\Listener\BezwaarDecisionListener;
use OCA\Dossiq\Listener\BezwaarHearingScheduledListener;
use OCA\Dossiq\Listener\ParaferingAuditListener;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the unnarrowed bezwaar and parafering-audit event listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class BezwaarListenerRegistrar {
	/**
	 * Register the parafering and bezwaar listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerParaferingListeners(context: $context);
		$this->registerObjectionStatusListeners(context: $context);
	}//end register()

	/**
	 * Register the parafering audit trail and approval-step notification listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	private function registerParaferingListeners(IRegistrationContext $context): void {
		// Parafering audit trail: one listener emits an OR audit-trail entry
		// (hash-chained, natively immutable) for every parafeerroute transition.
		// Per ADR-022 + consume-or-audit-trail-fleet-wide (migrate-parafering-to-or-audit),
		// there is no parallel paraferingAuditEntry write path and no in-app
		// append-only validator — OR's audit trail rejects PUT/DELETE natively.
		$context->registerEventListener(
			event: ParafeerTransitionEvent::class,
			listener: ParaferingAuditListener::class
		);

		// Parafering notifications now observe OpenRegister's approval-workflow
		// step events (ADR-022 / migrate-parafering-to-or-approval-workflow):
		// when a step is approved the next parafeerder is notified; when a step
		// is rejected (terugsturen) the steller is notified. The OpenRegister
		// event classes are registered by FQN string so dossiq carries no
		// hard compile-time dependency on the optional OpenRegister app.
		$context->registerEventListener(
			event: 'OCA\OpenRegister\Event\ApprovalStepApprovedEvent',
			listener: ApprovalStepNotificationListener::class
		);
		$context->registerEventListener(
			event: 'OCA\OpenRegister\Event\ApprovalStepRejectedEvent',
			listener: ApprovalStepNotificationListener::class
		);
	}//end registerParaferingListeners()

	/**
	 * Register the bezwaar status-driven listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	private function registerObjectionStatusListeners(IRegistrationContext $context): void {
		// Bezwaar-advisory-committee auto-assignment when a bezwaar enters
		// status "Hearing planned" — listener defers to
		// AdvisoryCommitteeService::autoAssignDefaultCommittee.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: BezwaarAdviceRequestedListener::class
		);

		// Bezwaar-hearing default-session seeding when a bezwaar enters
		// status "Hearing planned" — listener defers to
		// HearingService::seedDefaultHearing.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: BezwaarHearingScheduledListener::class
		);

		// Bezwaar-decision guard: a bezwaar may only enter status
		// "Decision on objection" when a published bezwaarDecision
		// exists for it. The listener reverts illegal transitions
		// without bypassing the status-transition-engine.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: BezwaarDecisionListener::class
		);
	}//end registerBezwaarStatusListeners()
}//end class
