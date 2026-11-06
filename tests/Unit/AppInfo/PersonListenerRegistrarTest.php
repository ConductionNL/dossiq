<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\PersonListenerRegistrar;
use OCA\Dossiq\Listener\CaseSchemaVocabularyListener;
use OCA\Dossiq\Listener\PersonLinkListener;
use OCA\Dossiq\Listener\RoleTypeVocabularyListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The listener is registered for all three person-link events.
 *
 * A listener registered for two of the three looks exactly like one
 * registered for all three until the missing event fires in production.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
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
				$registered[$listener][] = $event;
			}
		);

		(new PersonListenerRegistrar())->register(context: $context);

		$this->assertSame(
			expected: PersonListenerRegistrar::PERSON_EVENTS,
			actual: ($registered[PersonLinkListener::class] ?? []),
			message: 'all three events must be registered, in the order they are declared',
		);
	}//end testEveryPersonLinkEventReachesTheListener()

	/**
	 * A role type created, changed or deleted reaches the vocabulary listener.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function testEveryRoleTypeChangeReachesTheVocabularyListener(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$listener][] = $event;
			}
		);

		(new PersonListenerRegistrar())->register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Event\ObjectCreatedEvent',
				'OCA\OpenRegister\Event\ObjectUpdatedEvent',
				'OCA\OpenRegister\Event\ObjectDeletedEvent',
			],
			actual: ($registered[RoleTypeVocabularyListener::class] ?? []),
		);
	}//end testEveryRoleTypeChangeReachesTheVocabularyListener()

	/**
	 * An update of the case schema (a register re-import) reaches the vocabulary too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema
	 */
	public function testACaseSchemaUpdateReachesTheVocabulary(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$listener][] = $event;
			}
		);

		(new PersonListenerRegistrar())->register(context: $context);

		$this->assertSame(
			expected: ['OCA\OpenRegister\Event\SchemaUpdatedEvent'],
			actual: ($registered[CaseSchemaVocabularyListener::class] ?? []),
		);
	}//end testACaseSchemaUpdateReachesTheVocabulary()

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
