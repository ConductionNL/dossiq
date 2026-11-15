<?php

/**
 * Unit test for WozRegistrar.
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

use OCA\Dossiq\Service\External\Woz\WozAdapterInterface;
use OCA\Dossiq\Service\External\Woz\WozApiAdapter;
use OCA\Dossiq\Service\External\Woz\WozResponseMapper;
use OCA\Dossiq\Service\External\Woz\LogWozAdapter;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCA\Dossiq\Service\Registrar\WozRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The Woz seam binds the API adapter in test and live mode and the log adapter otherwise.
 *
 * @covers \OCA\Dossiq\Service\Registrar\WozRegistrar
 * @uses \OCA\Dossiq\Service\External\IntegrationMode
 * @uses \OCA\Dossiq\Service\External\Woz\WozApiAdapter
 * @uses \OCA\Dossiq\Service\External\Woz\LogWozAdapter
 */
class WozRegistrarTest extends TestCase {

	/**
	 * The ids the container was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * The mode key the integration mode read.
	 *
	 * @var array<int, string>
	 */
	private array $keys = [];

	/**
	 * Register the seam and hand back the one factory it bound.
	 *
	 * @return array{0: string, 1: callable} The bound name and its factory.
	 */
	private function registered(): array {
		$bound = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerService')->willReturnCallback(
			function (string $name, callable $factory) use (&$bound): void {
				$bound = [$name, $factory];
			}
		);

		(new WozRegistrar())->register(context: $context);

		return $bound;
	}//end registered()

	/**
	 * A container whose integration mode reads the given configured mode.
	 *
	 * @param string $mode The configured mode.
	 * @param object $logAdapter The log adapter the container holds.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(string $mode, object $logAdapter): ContainerInterface {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($mode): string {
				$this->keys[] = $app . ':' . $key . ':' . $default;
				return $mode;
			}
		);
		$modeService = new IntegrationMode(appConfig: $appConfig);
		$clients = $this->createMock(IClientService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$mapper = new WozResponseMapper();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($modeService, $clients, $logger, $mapper, $logAdapter): object {
				$this->asked[] = $id;
				return match ($id) {
					IntegrationMode::class => $modeService,
					'OCP\\Http\\Client\\IClientService' => $clients,
					WozResponseMapper::class => $mapper,
					'Psr\\Log\\LoggerInterface' => $logger,
					LogWozAdapter::class => $logAdapter,
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * The factory is bound to the seam's interface.
	 *
	 * @return void
	 */
	public function testTheFactoryIsBoundToTheSeamInterface(): void {
		[$name] = $this->registered();

		$this->assertSame(WozAdapterInterface::class, $name);
	}//end testTheFactoryIsBoundToTheSeamInterface()

	/**
	 * Test mode builds the API adapter and never asks for the log adapter.
	 *
	 * @return void
	 */
	public function testTestModeBuildsTheApiAdapter(): void {
		[, $factory] = $this->registered();
		$log = new LogWozAdapter(logger: $this->createMock(LoggerInterface::class));

		$adapter = $factory($this->container(mode: 'test', logAdapter: $log));

		$this->assertInstanceOf(WozApiAdapter::class, $adapter);
		$this->assertNotContains(LogWozAdapter::class, $this->asked);
		$this->assertSame(['dossiq:integration.woz.mode:log'], $this->keys);
	}//end testTestModeBuildsTheApiAdapter()

	/**
	 * Live mode builds the API adapter too.
	 *
	 * @return void
	 */
	public function testLiveModeBuildsTheApiAdapter(): void {
		[, $factory] = $this->registered();
		$log = new LogWozAdapter(logger: $this->createMock(LoggerInterface::class));

		$adapter = $factory($this->container(mode: ' LIVE ', logAdapter: $log));

		$this->assertInstanceOf(WozApiAdapter::class, $adapter);
		$this->assertContains('OCP\\Http\\Client\\IClientService', $this->asked);
	}//end testLiveModeBuildsTheApiAdapter()

	/**
	 * Log mode hands back the container's log adapter and builds no API client.
	 *
	 * @return void
	 */
	public function testLogModeReturnsTheLogAdapter(): void {
		[, $factory] = $this->registered();
		$log = new LogWozAdapter(logger: $this->createMock(LoggerInterface::class));

		$adapter = $factory($this->container(mode: 'log', logAdapter: $log));

		$this->assertSame($log, $adapter);
		$this->assertSame([IntegrationMode::class, LogWozAdapter::class], $this->asked);
	}//end testLogModeReturnsTheLogAdapter()

	/**
	 * A mode the seam does not allow falls back to the log adapter.
	 *
	 * @return void
	 */
	public function testAnUnknownModeFallsBackToTheLogAdapter(): void {
		[, $factory] = $this->registered();
		$log = new LogWozAdapter(logger: $this->createMock(LoggerInterface::class));

		$adapter = $factory($this->container(mode: 'simulator', logAdapter: $log));

		$this->assertSame($log, $adapter);
	}//end testAnUnknownModeFallsBackToTheLogAdapter()
}//end class
