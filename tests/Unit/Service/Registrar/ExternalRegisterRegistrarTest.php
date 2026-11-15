<?php

/**
 * Unit test for ExternalRegisterRegistrar.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Registrar;

use OCA\Dossiq\Service\External\Brk\BrkAdapterInterface;
use OCA\Dossiq\Service\External\Woz\WozAdapterInterface;
use OCA\Dossiq\Service\Registrar\ExternalRegisterRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The external-register registrar binds the BRK and the WOZ seams, in that order.
 *
 * @covers \OCA\Dossiq\Service\Registrar\ExternalRegisterRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\BrkRegistrar
 * @uses \OCA\Dossiq\Service\Registrar\WozRegistrar
 */
class ExternalRegisterRegistrarTest extends TestCase {

	/**
	 * Both seams get a factory, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testBothRegisterSeamsAreBound(): void {
		$names = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(2))->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$names): void {
				$names[] = $name;
			}
		);
		$context->expects($this->never())->method('registerServiceAlias');
		$context->expects($this->never())->method('registerEventListener');

		(new ExternalRegisterRegistrar())->register(context: $context);

		$this->assertSame([BrkAdapterInterface::class, WozAdapterInterface::class], $names);
	}//end testBothRegisterSeamsAreBound()
}//end class
