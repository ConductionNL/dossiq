<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\ContactMomentTimelineListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Puts a logged contact on the case timeline, whichever surface logged it.
 *
 * A REGISTRAR OF ITS OWN, NOT A LINE IN `ObjectListenerRegistrar`. That class
 * sat one dependency under the coupling ceiling and this listener was the one
 * that crossed it. It already delegates the intake, document and person
 * bindings to a registrar each for the same reason, so this follows the shape
 * rather than suppressing the warning.
 *
 * WHY THE BINDING IS ON THE EVENT. A contact logged from the KCC werkplek goes
 * through `ContactMomentController` and therefore through
 * `ContactMomentService`. A contact logged from the Communication tab on the
 * case page goes through the manifest's `log-contact` open-form action, which
 * saves straight to OpenRegister and runs no dossiq service at all. Both cross
 * the create event exactly once, so the event is the one place the entry can be
 * written from without being written twice.
 *
 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
 */
class ContactListenerRegistrar {

	/**
	 * Register the writer that puts a logged contact on the case timeline.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: ContactMomentTimelineListener::class
		);
	}//end register()
}//end class
