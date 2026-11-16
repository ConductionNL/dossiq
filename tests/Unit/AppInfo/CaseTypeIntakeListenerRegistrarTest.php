<?php

/**
 * The case-type intake listener is attached to exactly its four events.
 *
 * The listener stamps the case type's intake data onto a case while it is
 * being saved, on the create that follows, and when a draft is sent. A
 * missing registration leaves its own tests green while intake stops; an
 * extra one runs it twice. So the registrar's exact output is asserted.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseTypeIntakeListener;
use OCA\Dossiq\Listener\Registrar\CaseTypeIntakeListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\IntakeListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * What the case-type intake registrar attaches.
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseTypeIntakeListenerRegistrar
 */
class CaseTypeIntakeListenerRegistrarTest extends TestCase {

	/**
	 * Run the registrar and return the [event, listener] pairs in call order.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function registrations(): array {
		$registered = [];
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: 4))->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new CaseTypeIntakeListenerRegistrar())->register(context: $context);

		return $registered;
	}//end registrations()

	/**
	 * Creating, updating, created and draft-sent reach the listener, in that order, once each.
	 *
	 * @return void
	 */
	public function testTheFourEventsReachTheListenerInOrder(): void {
		$this->assertSame(
			expected: [
				['OCA\OpenRegister\Event\ObjectCreatingEvent', CaseTypeIntakeListener::class],
				['OCA\OpenRegister\Event\ObjectUpdatingEvent', CaseTypeIntakeListener::class],
				['OCA\OpenRegister\Event\ObjectCreatedEvent', CaseTypeIntakeListener::class],
				['OCA\OpenRegister\Event\ObjectActivatedEvent', CaseTypeIntakeListener::class],
			],
			actual: $this->registrations(),
		);
	}//end testTheFourEventsReachTheListenerInOrder()

	/**
	 * The draft-sent event is the one the intake registrar names, not a copy of it.
	 *
	 * @return void
	 */
	public function testTheDraftSentEventIsTheIntakeRegistrarsConstant(): void {
		$events = array_column($this->registrations(), 0);

		$this->assertContains(needle: IntakeListenerRegistrar::DRAFT_SENT_EVENT, haystack: $events);
		// A saved case is not re-stamped after the fact: no post-update hook.
		$this->assertNotContains(needle: 'OCA\OpenRegister\Event\ObjectUpdatedEvent', haystack: $events);
	}//end testTheDraftSentEventIsTheIntakeRegistrarsConstant()
}//end class
