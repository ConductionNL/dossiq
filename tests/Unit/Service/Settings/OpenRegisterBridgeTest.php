<?php

/**
 * Dossiq OpenRegister Bridge Test
 *
 * Pins when OpenRegister counts as available, which service each accessor
 * resolves, and that an unavailable app or a failing resolution answers null
 * and is logged rather than thrown.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Settings;

use OCA\Dossiq\Service\Settings\OpenRegisterBridge;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests for OpenRegisterBridge.
 */
#[CoversClass(OpenRegisterBridge::class)]
final class OpenRegisterBridgeTest extends TestCase {
	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Bridge under test.
	 *
	 * @var OpenRegisterBridge
	 */
	private OpenRegisterBridge $bridge;

	/**
	 * Set up mocks and the bridge.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->container  = $this->createMock(ContainerInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);

		$this->bridge = new OpenRegisterBridge(appManager: $this->appManager, container: $this->container, logger: $this->logger);
	}//end setUp()

	/**
	 * Make OpenRegister enabled or not, and installed or not.
	 *
	 * @param bool $enabled   Enabled for the user.
	 * @param bool $installed Installed.
	 *
	 * @return void
	 */
	private function openRegister(bool $enabled, bool $installed): void {
		$this->appManager->method('isEnabledForUser')->with('openregister')->willReturn($enabled);
		$this->appManager->method('isInstalled')->with('openregister')->willReturn($installed);
	}//end openRegister()

	/**
	 * Each accessor and the container id it resolves, with the log message of a failure.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function accessors(): array {
		return [
			'object service'       => ['objectService', 'OCA\OpenRegister\Service\ObjectService', 'Dossiq: Could not access OpenRegister ObjectService'],
			'object grant resolver' => ['objectGrantResolver', 'OCA\OpenRegister\Service\Rbac\ObjectGrantResolver', 'Dossiq: Could not access OpenRegister ObjectGrantResolver'],
			'file service'         => ['fileService', 'OCA\OpenRegister\Service\FileService', 'Dossiq: Could not access OpenRegister FileService'],
		];
	}//end accessors()

	/**
	 * OpenRegister is available when it is enabled for the user or installed.
	 *
	 * @return void
	 */
	public function testAvailableWhenEnabledOrInstalled(): void {
		$cases = [[true, false, true], [false, true, true], [true, true, true], [false, false, false]];
		foreach ($cases as [$enabled, $installed, $expected]) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForUser')->willReturn($enabled);
			$appManager->method('isInstalled')->willReturn($installed);
			$bridge = new OpenRegisterBridge(appManager: $appManager, container: $this->container, logger: $this->logger);

			self::assertSame(expected: $expected, actual: $bridge->isAvailable(), message: json_encode([$enabled, $installed]));
		}
	}//end testAvailableWhenEnabledOrInstalled()

	/**
	 * With OpenRegister available, each accessor answers the service the container resolves for its id.
	 *
	 * @param string $method The accessor.
	 * @param string $id     The container id.
	 *
	 * @return void
	 */
	#[DataProvider('accessors')]
	public function testAccessorResolvesItsService(string $method, string $id): void {
		$service = new stdClass();
		$this->openRegister(enabled: true, installed: true);
		$this->container->expects(self::once())->method('get')->with($id)->willReturn($service);

		self::assertSame(expected: $service, actual: $this->bridge->$method());
	}//end testAccessorResolvesItsService()

	/**
	 * Without OpenRegister, each accessor answers null without touching the container.
	 *
	 * @param string $method The accessor.
	 *
	 * @return void
	 */
	#[DataProvider('accessors')]
	public function testAccessorAnswersNullWithoutOpenRegister(string $method): void {
		$this->openRegister(enabled: false, installed: false);
		$this->container->expects(self::never())->method('get');

		self::assertNull(actual: $this->bridge->$method());
	}//end testAccessorAnswersNullWithoutOpenRegister()

	/**
	 * A failing resolution answers null and logs an error naming the service.
	 *
	 * @param string $method  The accessor.
	 * @param string $id      The container id.
	 * @param string $message The logged message.
	 *
	 * @return void
	 */
	#[DataProvider('accessors')]
	public function testFailingResolutionIsLoggedAndAnswersNull(string $method, string $id, string $message): void {
		$this->openRegister(enabled: true, installed: true);
		$this->container->method('get')->willThrowException(new RuntimeException(message: 'not registered'));
		$this->logger->expects(self::once())->method('error')->with($message, ['exception' => 'not registered']);

		self::assertNull(actual: $this->bridge->$method());
	}//end testFailingResolutionIsLoggedAndAnswersNull()

	/**
	 * classNamed() resolves any class by name, and logs the class it could not resolve.
	 *
	 * @return void
	 */
	public function testClassNamedResolvesAndLogsByClass(): void {
		$service = new stdClass();
		$this->openRegister(enabled: true, installed: true);
		$this->container->method('get')->willReturnCallback(
			static function (string $class) use ($service): object {
				if ($class === 'OCA\OpenRegister\Service\Found') {
					return $service;
				}

				throw new RuntimeException(message: 'unknown ' . $class);
			}
		);
		$this->logger->expects(self::once())
			->method('error')
			->with('Dossiq: Could not access OpenRegister class', ['class' => 'OCA\OpenRegister\Service\Missing', 'exception' => 'unknown OCA\OpenRegister\Service\Missing']);

		self::assertSame(expected: $service, actual: $this->bridge->classNamed(class: 'OCA\OpenRegister\Service\Found'));
		self::assertNull(actual: $this->bridge->classNamed(class: 'OCA\OpenRegister\Service\Missing'));
	}//end testClassNamedResolvesAndLogsByClass()

	/**
	 * Without OpenRegister, classNamed() answers null without touching the container.
	 *
	 * @return void
	 */
	public function testClassNamedAnswersNullWithoutOpenRegister(): void {
		$this->openRegister(enabled: false, installed: false);
		$this->container->expects(self::never())->method('get');

		self::assertNull(actual: $this->bridge->classNamed(class: 'OCA\OpenRegister\Service\Found'));
	}//end testClassNamedAnswersNullWithoutOpenRegister()
}//end class
