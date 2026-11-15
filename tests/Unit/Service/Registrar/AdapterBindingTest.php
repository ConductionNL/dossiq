<?php

/**
 * Unit test for AdapterBinding.
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

use OCA\Dossiq\Service\Registrar\AdapterBinding;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Registrar\AdapterBinding
 */
class AdapterBindingTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$container = $this->createMock(originalClassName: \Psr\Container\ContainerInterface::class);
		$logger = $this->createMock(originalClassName: \Psr\Log\LoggerInterface::class);
		$configKey = 'configKey-value';
		$named = 'named-value';

		$value = new AdapterBinding(
			container: $container,
			logger: $logger,
			configKey: $configKey,
			named: $named,
		);

		$this->assertSame(expected: $container, actual: $value->container);
		$this->assertSame(expected: $logger, actual: $value->logger);
		$this->assertSame(expected: $configKey, actual: $value->configKey);
		$this->assertSame(expected: $named, actual: $value->named);
	}//end testEveryPropertyReadsBack()
}//end class
