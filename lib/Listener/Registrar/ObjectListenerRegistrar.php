<?php

/**
 * Dossiq object-lifecycle listener registrar.
 *
 * The notifier plus the OpenRegister object-lifecycle listeners that are not
 * scoped to a single subsystem: KPI cache invalidation and role-routing
 * cache invalidation. Subsystem-scoped listeners live in their own
 * registrars ({@see IntakeListenerRegistrar}, {@see TermijnTimerRegistrar}).
 *
 * @category AppInfo
 * @package  OCA\Dossiq\Listener\Registrar
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Registrar;

use OCA\Dossiq\Listener\CaseDeleteGuardListener;
use OCA\Dossiq\Listener\CaseTypeDeleteGuard;
use OCA\Dossiq\Listener\EmailTemplateVersionListener;
use OCA\Dossiq\Listener\WooRefusalGroundDeleteGuard;
use OCA\Dossiq\Listener\DependentTermListener;
use OCA\Dossiq\Listener\KpiCacheInvalidationListener;
use OCA\Dossiq\Listener\RoleMutationListener;
use OCA\Dossiq\Notification\Notifier;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the notifier and the cross-subsystem object-lifecycle listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Fourteen, two over the
 * threshold, and five of the fourteen are the per-domain registrars this
 * class delegates to rather than dependencies it entangles itself with. The
 * one that crossed the line is `ContactListenerRegistrar`, and it was made a
 * registrar of its own precisely so the binding it carries would not be a
 * fifth listener import here. Splitting further would mean a registrar
 * holding one registrar holding one listener, which trades a number for a
 * layer and makes the list of what dossiq binds harder to read. The reason a
 * ceiling exists is entanglement; a list of registrars is not that.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class ObjectListenerRegistrar {
	/**
	 * Register the notifier and the cross-subsystem object listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Note @mention notifications (nc-vue #207, ncvue-w2-leaves-adoption):
		// MentionNotificationService raises `note_mention` notifications;
		// this Notifier renders them for the bell menu.
		$context->registerNotifierService(Notifier::class);

		$this->registerCacheInvalidationListeners(context: $context);
		$this->registerCaseDeleteGuard(context: $context);
		$this->registerWooRefusalGroundDeleteGuard(context: $context);
		$this->registerCaseTypeDeleteGuard(context: $context);
		$this->registerEmailTemplateVersionListener(context: $context);
		$this->registerSubsystemRegistrars(context: $context);
	}//end register()

	/**
	 * Register the Woo refusal-ground delete guard.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerWooRefusalGroundDeleteGuard(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectDeletingEvent::class,
			listener: WooRefusalGroundDeleteGuard::class
		);
		$context->registerEventListener(
			event: ObjectLeavingEvent::class,
			listener: WooRefusalGroundDeleteGuard::class
		);
	}//end registerWooRefusalGroundDeleteGuard()

	/**
	 * Register the case type delete guard.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerCaseTypeDeleteGuard(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectDeletingEvent::class,
			listener: CaseTypeDeleteGuard::class
		);
		$context->registerEventListener(
			event: ObjectLeavingEvent::class,
			listener: CaseTypeDeleteGuard::class
		);
	}//end registerCaseTypeDeleteGuard()

	/**
	 * Register the email template version listener on create and update.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerEmailTemplateVersionListener(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $templateEvent) {
			$context->registerEventListener(
				event: $templateEvent,
				listener: EmailTemplateVersionListener::class
			);
		}
	}//end registerEmailTemplateVersionListener()

	/**
	 * Hand the context to each subsystem's own registrar.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerSubsystemRegistrars(IRegistrationContext $context): void {
		(new IntakeListenerRegistrar())->register(context: $context);
		(new ContactListenerRegistrar())->register(context: $context);
		(new DocumentListenerRegistrar())->register(context: $context);
		(new PersonListenerRegistrar())->register(context: $context);
		(new CaseRequesterListenerRegistrar())->register(context: $context);
		(new CaseAccessListenerRegistrar())->register(context: $context);
		(new WooListenerRegistrar())->register(context: $context);
		(new CaseLinkListenerRegistrar())->register(context: $context);
		(new ForfeitInvoiceListenerRegistrar())->register(context: $context);
		(new OnboardingListenerRegistrar())->register(context: $context);
		(new CaseActivityListenerRegistrar())->register(context: $context);
	}//end registerSubsystemRegistrars()

	/**
	 * Register the case delete guard (REQ-CM-35).
	 *
	 * The binding is by schema: `CaseDeleteGuardListener` guards the schema
	 * named by {@see CaseDeleteGuardListener::GUARDED_SCHEMA_CONFIG_KEY} and
	 * returns for every other schema on the instance. Nextcloud's
	 * registration API takes an event class and a listener class and has no
	 * place for a schema, so the binding is named here and honoured there.
	 *
	 * The PRE-persist event is the only one that can refuse: `ObjectDeletedEvent`
	 * fires after the row is gone (ADR-078).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	private function registerCaseDeleteGuard(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectDeletingEvent::class,
			listener: CaseDeleteGuardListener::class
		);
		$context->registerEventListener(
			event: ObjectLeavingEvent::class,
			listener: CaseDeleteGuardListener::class
		);
	}//end registerCaseDeleteGuard()

	/**
	 * Register the KPI and role-routing cache-invalidation listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerCacheInvalidationListeners(IRegistrationContext $context): void {
		$this->registerKpiCacheInvalidation(context: $context);

		// A term event that moves a date offers the same move to the cases
		// waiting on that case. Registered on the create only: the event row
		// is written once per move, and the instance it names is rewritten
		// several times for the same one.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DependentTermListener::class
		);

		$this->registerRoleMutationListeners(context: $context);
	}//end registerCacheInvalidationListeners()

	/**
	 * Register the KPI cache invalidation on create, update and delete.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerKpiCacheInvalidation(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: KpiCacheInvalidationListener::class
		);

		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: KpiCacheInvalidationListener::class
		);

		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: KpiCacheInvalidationListener::class
		);
	}//end registerKpiCacheInvalidation()

	/**
	 * Register the role-routing cache invalidation on role mutations.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerRoleMutationListeners(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: RoleMutationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: RoleMutationListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: RoleMutationListener::class
		);
	}//end registerRoleMutationListeners()
}//end class
