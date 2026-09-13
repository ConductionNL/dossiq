<?php

/**
 * The case delete guard is registered, on the event that can still refuse.
 *
 * A guard that is defined but never registered looks exactly like a guard that
 * passes: no listener runs, no error is raised, and every delete succeeds.
 * `ChecklistRunImmutabilityListener` sat unreferenced by any registrar for
 * months for precisely that reason, so the registration is pinned here rather
 * than assumed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Dossiq\Listener\CaseDeleteGuardListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar
 */
class ObjectListenerRegistrarTest extends TestCase {

	/**
	 * The delete guard is registered on OpenRegister's pre-persist delete event.
	 *
	 * @return void
	 */
	public function testTheCaseDeleteGuardIsRegistered(): void {
		$registered = $this->registrations();

		$this->assertContains(
			needle: CaseDeleteGuardListener::class,
			haystack: ($registered['OCA\OpenRegister\Event\ObjectDeletingEvent'] ?? []),
			message: 'the guard must be registered, or every delete succeeds and nothing says why',
		);
	}//end testTheCaseDeleteGuardIsRegistered()

	/**
	 * It is NOT registered on the post-persist event, which cannot refuse.
	 *
	 * `ObjectDeletedEvent` fires after the row is gone, so a guard there stops
	 * nothing (ADR-078). Registering on both would look twice as safe and be
	 * exactly as safe.
	 *
	 * @return void
	 */
	public function testTheGuardIsNotOnThePostPersistEvent(): void {
		$registered = $this->registrations();

		$this->assertNotContains(
			needle: CaseDeleteGuardListener::class,
			haystack: ($registered['OCA\OpenRegister\Event\ObjectDeletedEvent'] ?? []),
		);
	}//end testTheGuardIsNotOnThePostPersistEvent()

	/**
	 * The guard is bound to the case schema and to nothing else.
	 *
	 * @return void
	 */
	public function testTheGuardIsBoundToTheCaseSchema(): void {
		$this->assertSame(
			expected: 'case_schema',
			actual: CaseDeleteGuardListener::GUARDED_SCHEMA_CONFIG_KEY,
		);
	}//end testTheGuardIsBoundToTheCaseSchema()

	/**
	 * Run the registrar over a recording context.
	 *
	 * @return array<string, array<int, string>> Listener classes per event class.
	 */
	private function registrations(): array {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		(new ObjectListenerRegistrar())->register(context: $context);

		return $registered;
	}//end registrations()
}//end class
