<?php

/**
 * RepairHelperFactory unit tests
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\LoadDefaultZgwMappingsInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwMappingServiceInterface;
use OCA\Dossiq\Repair\LoadDefaultZgwMappings;
use OCA\Dossiq\Repair\RepairHelperFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The factory hands out the default ZGW mapping loader under its contract,
 * wired to the collaborators it was given.
 *
 * @covers \OCA\Dossiq\Repair\RepairHelperFactory
 * @uses   \OCA\Dossiq\Repair\LoadDefaultZgwMappings
 */
final class RepairHelperFactoryTest extends TestCase {

	/**
	 * The loader is the real repair step and reads the settings service it was given.
	 *
	 * @return void
	 */
	public function testTheDefaultZgwMappingsLoaderUsesTheGivenSettings(): void {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->expects($this->once())->method('getSettings')->willReturn([]);

		$loader = (new RepairHelperFactory())->defaultZgwMappings(
			zgwMappingService: $this->createMock(ZgwMappingServiceInterface::class),
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertInstanceOf(LoadDefaultZgwMappingsInterface::class, $loader);
		self::assertInstanceOf(LoadDefaultZgwMappings::class, $loader);
		$mappings = $loader->getDefaultMappings(registerId: 'reg-7');
		self::assertNotSame([], $mappings);
	}//end testTheDefaultZgwMappingsLoaderUsesTheGivenSettings()

	/**
	 * Every call builds a fresh loader.
	 *
	 * @return void
	 */
	public function testEachCallBuildsItsOwnLoader(): void {
		$factory = new RepairHelperFactory();
		$build = fn (): LoadDefaultZgwMappingsInterface => $factory->defaultZgwMappings(
			zgwMappingService: $this->createMock(ZgwMappingServiceInterface::class),
			settingsService: $this->createMock(SettingsServiceInterface::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertNotSame($build(), $build());
	}//end testEachCallBuildsItsOwnLoader()
}//end class
