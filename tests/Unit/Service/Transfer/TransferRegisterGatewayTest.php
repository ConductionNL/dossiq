<?php

/**
 * Unit tests for TransferRegisterGateway.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transfer
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transfer;

use OCA\Dossiq\Service\Transfer\TransferRegisterGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Transfer\TransferRegisterGateway
 */
class TransferRegisterGatewayTest extends TestCase {
	/**
	 * @param array<string, object|\Throwable> $services By container id.
	 */
	private function gateway(array $services, bool $installed = true, ?LoggerInterface $logger = null): TransferRegisterGateway {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn($installed ? ['dossiq', 'openregister'] : ['dossiq']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($services): object {
			$service = $services[$id] ?? new \RuntimeException('unknown ' . $id);
			if ($service instanceof \Throwable) {
				throw $service;
			}
			return $service;
		});

		return new TransferRegisterGateway($apps, $container, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testWithoutOpenRegisterThereIsNoService(): void {
		$gateway = $this->gateway(['OCA\OpenRegister\Service\ObjectService' => new \stdClass()], false);

		$this->assertNull($gateway->objectService());
		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
	}

	public function testTheServicesComeFromTheContainer(): void {
		$objects = new \stdClass();
		$shares = new class {
			public function createOutgoingShare(array $params): object {
				return (object)$params;
			}
		};
		$mapper = new class {
			public function findByToken(string $token): object {
				return new \stdClass();
			}
		};
		$gateway = $this->gateway([
			'OCA\OpenRegister\Service\ObjectService' => $objects,
			'OCA\OpenRegister\Service\FederationShareService' => $shares,
			'OCA\OpenRegister\Db\FederatedShareMapper' => $mapper,
		]);

		$this->assertSame($objects, $gateway->objectService());
		$this->assertSame($shares, $gateway->federationShareService());
		$this->assertSame($mapper, $gateway->federatedShareMapper());
	}

	public function testAnOpenRegisterWithoutTheFederationMethodsHasNoShareSupport(): void {
		$gateway = $this->gateway([
			'OCA\OpenRegister\Service\FederationShareService' => new \stdClass(),
			'OCA\OpenRegister\Db\FederatedShareMapper' => new \stdClass(),
		]);

		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
	}

	public function testAContainerFailureIsLoggedAndReadsAsNoService(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logged = [];
		$logger->method('error')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context['exception']];
		});
		$gateway = $this->gateway([
			'OCA\OpenRegister\Service\ObjectService' => new \RuntimeException('not registered'),
			'OCA\OpenRegister\Service\FederationShareService' => new \Error('class missing'),
			'OCA\OpenRegister\Db\FederatedShareMapper' => new \Error('table missing'),
		], true, $logger);

		$this->assertNull($gateway->objectService());
		$this->assertNull($gateway->federationShareService());
		$this->assertNull($gateway->federatedShareMapper());
		$this->assertSame([
			['Dossiq: Could not get ObjectService', 'not registered'],
			['Dossiq: Could not get OR FederationShareService', 'class missing'],
			['Dossiq: Could not get OR FederatedShareMapper', 'table missing'],
		], $logged);
	}
}
