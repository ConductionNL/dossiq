<?php

/**
 * IntakeValidationListenerRegistrar Unit Tests
 *
 * The BAG check and the intake requirements listen before a case is stored,
 * on create and on update; the processing log listens after it is stored.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Listener\Registrar\IntakeValidationListenerRegistrar;
use OCA\Dossiq\Listener\SpecialCategoryProcessingLogListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IntakeValidationListenerRegistrar.
 *
 * @covers \OCA\Dossiq\Listener\Registrar\IntakeValidationListenerRegistrar
 */
final class IntakeValidationListenerRegistrarTest extends TestCase {

	/**
	 * Each listener is on the events it must be on, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testEachListenerIsOnItsEvents(): void {
		$registered = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		(new IntakeValidationListenerRegistrar())->register(context: $context);

		$this->assertSame(
			expected: [
				'OCA\OpenRegister\Event\ObjectCreatingEvent' => [LocationBagValidationListener::class, IntakeRequirementsListener::class],
				'OCA\OpenRegister\Event\ObjectUpdatingEvent' => [LocationBagValidationListener::class, IntakeRequirementsListener::class],
				'OCA\OpenRegister\Event\ObjectCreatedEvent' => [SpecialCategoryProcessingLogListener::class],
				'OCA\OpenRegister\Event\ObjectUpdatedEvent' => [SpecialCategoryProcessingLogListener::class],
			],
			actual: $registered
		);
	}//end testEachListenerIsOnItsEvents()
}//end class
