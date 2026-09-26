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

use OCA\Dossiq\Listener\PersonLinkListener;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Hears OpenRegister's person links, so a person on a case becomes a role record.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
 */
class PersonListenerRegistrar {

	/**
	 * The three events a person link raises.
	 */
	public const PERSON_EVENTS = [
		PersonLinkedEvent::class,
		PersonLinkUpdatedEvent::class,
		PersonUnlinkedEvent::class,
	];

	/**
	 * Register the listener for all three.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::PERSON_EVENTS as $event) {
			$context->registerEventListener(event: $event, listener: PersonLinkListener::class);
		}
	}//end register()
}//end class
