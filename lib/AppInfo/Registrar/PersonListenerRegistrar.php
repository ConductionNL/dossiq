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
use OCA\Dossiq\Listener\RoleTypeVocabularyListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\PersonLinkedEvent;
use OCA\OpenRegister\Event\PersonLinkUpdatedEvent;
use OCA\OpenRegister\Event\PersonUnlinkedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Hears OpenRegister's person links, so a person on a case becomes a role record,
 * and the role type saves, so the roles a person can hold stay current.
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
	 * The events after which a role type may have changed the link vocabulary.
	 */
	public const ROLE_TYPE_EVENTS = [
		ObjectCreatedEvent::class,
		ObjectUpdatedEvent::class,
		ObjectDeletedEvent::class,
	];

	/**
	 * Register the person-link listener for all three, and the vocabulary
	 * listener for every role type save and delete.
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

		foreach (self::ROLE_TYPE_EVENTS as $event) {
			$context->registerEventListener(event: $event, listener: RoleTypeVocabularyListener::class);
		}
	}//end register()
}//end class
