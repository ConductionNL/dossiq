<?php

/**
 * The pre-persist immutability guards are registered, the delivered Woo set's among them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-delivered-set/spec.md#requirement-a-frozen-set-and-its-assessments-refuse-change-req-wds-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\AppInfo\Registrar\ImmutabilityListenerRegistrar;
use OCA\Dossiq\Listener\WooDeliveredSetGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\AppInfo\Registrar\ImmutabilityListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\CaseAccessListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\CaseActivityListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\CaseLinkListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\CaseRequesterListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\ContactListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\DocumentListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\ForfeitInvoiceListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\IntakeFormListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\IntakeListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\OnboardingListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\PersonListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\ReviewListenerRegistrar
 * @uses \OCA\Dossiq\AppInfo\Registrar\WooListenerRegistrar
 */
class ImmutabilityListenerRegistrarTest extends TestCase {

	/**
	 * The delivered set guard listens to both pre-persist events, the only ones that can refuse.
	 *
	 * @return void
	 */
	public function testTheDeliveredSetGuardIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		(new ImmutabilityListenerRegistrar())->register(context: $context);

		$this->assertContains(WooDeliveredSetGuard::class, ($registered['OCA\OpenRegister\Event\ObjectUpdatingEvent'] ?? []));
		$this->assertContains(WooDeliveredSetGuard::class, ($registered['OCA\OpenRegister\Event\ObjectDeletingEvent'] ?? []));
		$this->assertNotContains(WooDeliveredSetGuard::class, ($registered['OCA\OpenRegister\Event\ObjectUpdatedEvent'] ?? []));
	}//end testTheDeliveredSetGuardIsRegistered()

	/**
	 * Every guard that hears a delete also hears a move out of the register, in all three registrars.
	 *
	 * @return void
	 */
	public function testEveryDeleteGuardAlsoHearsAMove(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		(new ImmutabilityListenerRegistrar())->register(context: $context);
		(new \OCA\Dossiq\AppInfo\Registrar\ObjectListenerRegistrar())->register(context: $context);
		(new \OCA\Dossiq\AppInfo\Registrar\ReviewListenerRegistrar())->register(context: $context);

		$deleting = array_unique($registered['OCA\OpenRegister\Event\ObjectDeletingEvent'] ?? []);
		$this->assertNotSame([], $deleting);
		$this->assertSame([], array_values(array_diff($deleting, ($registered['OCA\OpenRegister\Event\ObjectLeavingEvent'] ?? []))));
	}//end testEveryDeleteGuardAlsoHearsAMove()
}//end class
