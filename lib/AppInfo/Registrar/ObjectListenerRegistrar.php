<?php

/**
 * Dossiq object-lifecycle listener registrar.
 *
 * The notifier plus the OpenRegister object-lifecycle listeners that are not
 * scoped to a single subsystem: KPI cache invalidation, role-routing cache
 * invalidation, DSO vergunningaanvraag intake and BAG location validation.
 * Subsystem-scoped listeners live in their own registrars.
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\KpiCacheInvalidationListener;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Listener\RoleMutationListener;
use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Listener\VergunningaanvraagCreatedListener;
use OCA\Dossiq\Notification\Notifier;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the notifier and the cross-subsystem object-lifecycle listeners.
 *
 * @psalm-suppress UnusedClass
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
		$this->registerIntakeListeners(context: $context);

		// One clock (termijnbewaking-op-engine-timers): the engine sweep
		// fires the armed termijn timers; this listener does the domain
		// side (threshold bookkeeping, breach flip, dwangsom accrual sync,
		// pause expiry). The ::class reference does not autoload, so
		// registration is safe when OpenRegister is absent.
		$context->registerEventListener(
			event: FlowTimerFiredEvent::class,
			listener: TermijnTimerFiredListener::class
		);
	}//end register()

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

		// Role-routing cache invalidation on role mutations.
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
	}//end registerCacheInvalidationListeners()

	/**
	 * Register the DSO intake and BAG location-validation listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	private function registerIntakeListeners(IRegistrationContext $context): void {
		// DSO Omgevingsloket: create a Dossiq zaak when a vergunningaanvraag is
		// written by OpenRegister.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: VergunningaanvraagCreatedListener::class
		);

		// Bag-location-save-validation: pre-persist location source=bag
		// enforcement (closes bag-register-adapter tasks.md item 4.1).
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: LocationBagValidationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: LocationBagValidationListener::class
		);
	}//end registerIntakeListeners()
}//end class
