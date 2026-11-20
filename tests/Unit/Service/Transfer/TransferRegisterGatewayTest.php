<?php

/**
 * Where a case transfer reads its register and reaches OpenRegister's services.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
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

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * The transfer scope, and each OpenRegister service: absent app, missing
 * method, failing container, and the service itself.
 *
 * @covers \OCA\Dossiq\Service\Transfer\TransferRegisterGateway
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\Settings\OpenRegisterBridge
 * @uses \OCA\Dossiq\Service\Settings\ConfigurationImport
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class TransferRegisterGatewayTest extends TestCase {

	private ContainerInterface $container;

	private LoggerInterface $logger;

	private function gateway(bool $installed = true, array $config = []): TransferRegisterGateway {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed === true ? ['dossiq', 'openregister'] : ['dossiq']);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$appConfig->method('hasKey')->willReturn(true);

		$this->container ??= $this->createMock(ContainerInterface::class);
		$this->logger    ??= $this->createMock(LoggerInterface::class);

		$settings = new SettingsService($appConfig, $apps, $this->container, $this->logger);

		return new TransferRegisterGateway($apps, $this->container, $this->logger, $settings);
	}

	public function testTheScopeIsTheConfiguredRegisterAndTransferSchema(): void {
		$this->assertSame([4, 17], $this->gateway(config: ['register' => '4', 'case_transfer_schema' => '17'])->transferScope());
		$this->assertSame([0, 0], $this->gateway()->transferScope());
	}

	public function testWithoutOpenRegisterNoServiceIsAsked(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->expects($this->never())->method('get');

		$gateway = $this->gateway(installed: false);

		$this->assertNull($gateway->objectService());
		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
	}

	public function testEachServiceIsTakenFromTheContainer(): void {
		$objects = new stdClass();
		$shares  = new class {
			public function createOutgoingShare(array $params): object {
				return (object)$params;
			}
		};
		$mapper  = new class {
			public function findByToken(string $token): object {
				return new stdClass();
			}
		};

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Service\ObjectService', $objects],
				['OCA\OpenRegister\Service\FederationShareService', $shares],
				['OCA\OpenRegister\Db\FederatedShareMapper', $mapper],
			]
		);

		$gateway = $this->gateway();

		$this->assertSame($objects, $gateway->objectService());
		$this->assertSame($shares, $gateway->federationShareService());
		$this->assertSame($mapper, $gateway->federatedShareMapper());
	}

	public function testAnOlderOpenRegisterWithoutTheMethodsGivesNoFederation(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn(new stdClass());

		$gateway = $this->gateway();

		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
	}

	public function testAFailingContainerIsLoggedAndGivesNull(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new RuntimeException('boom'));
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->exactly(3))->method('error')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertStringStartsWith('Dossiq: Could not get ', $message);
				$this->assertSame(['exception' => 'boom'], $context);
			}
		);

		$gateway = $this->gateway();

		$this->assertNull($gateway->objectService());
		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
	}
}
