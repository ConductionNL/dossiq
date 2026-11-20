<?php

/**
 * Unit test for BeschikkingAdapterRegistrar.
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

use OCA\Dossiq\Service\Beschikking\ArchivalAdapterInterface;
use OCA\Dossiq\Service\Beschikking\OpenRegisterArchivalAdapter;
use OCA\Dossiq\Service\Registrar\BeschikkingAdapterRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The beschikking registrar aliases archival and binds no signing adapter.
 *
 * @covers \OCA\Dossiq\Service\Registrar\BeschikkingAdapterRegistrar
 */
class BeschikkingAdapterRegistrarTest extends TestCase {

	/**
	 * Archival is aliased onto the OpenRegister archival adapter, and signing is
	 * no longer bound here: filinq signs (signing-through-filinq).
	 *
	 * @return void
	 */
	public function testOnlyArchivalIsBound(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->never())->method('registerService');
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new BeschikkingAdapterRegistrar())->register(context: $context);

		$this->assertSame([ArchivalAdapterInterface::class => OpenRegisterArchivalAdapter::class], $aliases);
	}//end testOnlyArchivalIsBound()
}//end class
