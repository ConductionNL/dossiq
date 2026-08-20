<?php

/**
 * Procest object-lifecycle listener registrar.
 *
 * The notifier plus the OpenRegister object-lifecycle listeners that are not
 * scoped to a single subsystem: KPI cache invalidation, role-routing cache
 * invalidation, DSO vergunningaanvraag intake and BAG location validation.
 * Subsystem-scoped listeners live in their own registrars.
 *
 * @category AppInfo
 * @package  OCA\Procest\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Procest\Listener\KpiCacheInvalidationListener;
use OCA\Procest\Listener\LocationBagValidationListener;
use OCA\Procest\Listener\RoleMutationListener;
use OCA\Procest\Listener\VergunningaanvraagCreatedListener;
use OCA\Procest\Notification\Notifier;
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
		// DSO Omgevingsloket: create a Procest zaak when a vergunningaanvraag is
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
