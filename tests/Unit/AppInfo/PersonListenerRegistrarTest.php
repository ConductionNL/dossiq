<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\PersonListenerRegistrar;
use OCA\Dossiq\Listener\PersonLinkListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The listener is registered for all three person-link events.
 *
 * A listener registered for two of the three looks exactly like one
 * registered for all three until the missing event fires in production.
 *
 * @spec openspec/changes/people-on-the-case/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
 */
class PersonListenerRegistrarTest extends TestCase {

	/**
	 * Every person-link event gets the projection listener.
	 *
	 * @return void
	 */
	public function testEveryPersonLinkEventReachesTheListener(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event] = $listener;
			}
		);

		(new PersonListenerRegistrar())->register(context: $context);

		$this->assertSame(
			expected: PersonListenerRegistrar::PERSON_EVENTS,
			actual: array_keys($registered),
			message: 'all three events must be registered, in the order they are declared',
		);
		foreach ($registered as $listener) {
			$this->assertSame(expected: PersonLinkListener::class, actual: $listener);
		}
	}//end testEveryPersonLinkEventReachesTheListener()

	/**
	 * The three events are the ones OpenRegister raises for a person link.
	 *
	 * @return void
	 */
	public function testTheEventsAreOpenRegistersPersonLinkEvents(): void {
		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Event\PersonLinkedEvent',
				'OCA\OpenRegister\Event\PersonLinkUpdatedEvent',
				'OCA\OpenRegister\Event\PersonUnlinkedEvent',
			],
			actual: PersonListenerRegistrar::PERSON_EVENTS,
		);
	}//end testTheEventsAreOpenRegistersPersonLinkEvents()
}//end class
