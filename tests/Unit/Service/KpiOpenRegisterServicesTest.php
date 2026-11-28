<?php

/**
 * KpiOpenRegisterServices Unit Tests
 *
 * Without OpenRegister neither service is asked for; with it, the object
 * service is resolved (one that does not resolve is logged and answered as
 * none), and the language service only when it has the translation switch.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\KpiOpenRegisterServices;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for KpiOpenRegisterServices.
 *
 * @covers \OCA\Dossiq\Service\KpiOpenRegisterServices
 */
final class KpiOpenRegisterServicesTest extends TestCase {

	/**
	 * An app manager that says whether OpenRegister is installed.
	 *
	 * @param bool $installed Whether it is.
	 *
	 * @return IAppManager
	 */
	private function apps(bool $installed): IAppManager {
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->with('openregister')->willReturn($installed);
		return $apps;
	}//end apps()

	/**
	 * Without OpenRegister the container is not asked, and both answers are none.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAskedFor(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$container->expects($this->never())->method('has');

		$services = new KpiOpenRegisterServices(
			appManager: $this->apps(installed: false),
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertNull(actual: $services->objectService());
		$this->assertNull(actual: $services->languageService());
	}//end testWithoutOpenRegisterNothingIsAskedFor()

	/**
	 * The object service is resolved; a language service without the switch is passed over.
	 *
	 * @return void
	 */
	public function testTheServicesAreResolvedWhenUsable(): void {
		$objects = new \stdClass();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $objects],
				['OCA\OpenRegister\Service\LanguageService', new \stdClass()],
			]
		);

		$services = new KpiOpenRegisterServices(
			appManager: $this->apps(installed: true),
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: $objects, actual: $services->objectService());
		$this->assertNull(actual: $services->languageService());
	}//end testTheServicesAreResolvedWhenUsable()

	/**
	 * A language service with the translation switch is answered.
	 *
	 * @return void
	 */
	public function testALanguageServiceWithTheSwitchIsAnswered(): void {
		$language = new class {
			/**
			 * Whether every translation is returned.
			 *
			 * @return bool
			 */
			public function shouldReturnAllTranslations(): bool {
				return false;
			}

			/**
			 * Switch returning every translation.
			 *
			 * @param bool $all Whether to.
			 *
			 * @return void
			 */
			public function setReturnAllTranslations(bool $all): void {
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->with('OCA\OpenRegister\Service\LanguageService')->willReturn(true);
		$container->method('get')->willReturn($language);

		$services = new KpiOpenRegisterServices(
			appManager: $this->apps(installed: true),
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: $language, actual: $services->languageService());
	}//end testALanguageServiceWithTheSwitchIsAnswered()

	/**
	 * An object service that does not resolve on an installed OpenRegister is logged and answered as none.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceIsLogged(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not wired'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[KpiAggregationService] OpenRegister is installed but its ObjectService did not resolve',
			['exception' => 'not wired']
		);

		$services = new KpiOpenRegisterServices(appManager: $this->apps(installed: true), container: $container, logger: $logger);

		$this->assertNull(actual: $services->objectService());
	}//end testAnUnresolvableObjectServiceIsLogged()
}//end class
