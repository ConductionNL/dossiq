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

use OCA\Dossiq\Listener\Registrar\ImmutabilityListenerRegistrar;
use OCA\Dossiq\Listener\WooDeliveredSetGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\Registrar\ImmutabilityListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseAccessListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseActivityListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseLinkListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\CaseRequesterListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ContactListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\DocumentListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ForfeitInvoiceListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\IntakeFormListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\IntakeListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ObjectListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\OnboardingListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\PersonListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar
 * @uses \OCA\Dossiq\Listener\Registrar\WooListenerRegistrar
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
		(new \OCA\Dossiq\Listener\Registrar\ObjectListenerRegistrar())->register(context: $context);
		(new \OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar())->register(context: $context);

		$deleting = array_unique($registered['OCA\OpenRegister\Event\ObjectDeletingEvent'] ?? []);
		$this->assertNotSame([], $deleting);
		$this->assertSame([], array_values(array_diff($deleting, ($registered['OCA\OpenRegister\Event\ObjectLeavingEvent'] ?? []))));
	}//end testEveryDeleteGuardAlsoHearsAMove()
}//end class
