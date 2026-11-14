<?php

/**
 * Unit tests for TransferShareBroker.
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
use OCA\Dossiq\Service\Transfer\TransferShareBroker;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Transfer\TransferShareBroker
 * @uses \OCA\Dossiq\Service\Transfer\TransferRegisterGateway
 */
class TransferShareBrokerTest extends TestCase {
	private const TRANSFER = '6f1c2b8e-0d3a-4b7e-9c11-2a4d5e6f7a8b';

	/** @var list<array<string, string>> */
	private array $created = [];

	/**
	 * @param array<string, object> $services By container id; an absent id is not registered.
	 */
	private function broker(array $services, ?LoggerInterface $logger = null): TransferShareBroker {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($services): object {
			return $services[$id] ?? throw new \RuntimeException('unknown ' . $id);
		});
		$logger ??= $this->createMock(LoggerInterface::class);

		return new TransferShareBroker(new TransferRegisterGateway($apps, $container, $logger), $logger);
	}

	private function shareService(?\Throwable $failure = null): object {
		$test = $this;
		return new class($test, $failure) {
			public function __construct(private TransferShareBrokerTest $test, private ?\Throwable $failure) {
			}

			public function createOutgoingShare(array $params): object {
				if ($this->failure !== null) {
					throw $this->failure;
				}
				$this->test->recordShare($params);
				return (object)['token' => 'tok-1'];
			}
		};
	}

	/** @param array<string, string> $params */
	public function recordShare(array $params): void {
		$this->created[] = $params;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function mapper(array $fields, ?\Throwable $failure = null): object {
		$share = new class($fields) {
			public function __construct(private array $fields) {
			}

			public function __call(string $name, array $arguments): mixed {
				return $this->fields[lcfirst(substr($name, 3))] ?? null;
			}
		};
		return new class($share, $failure) {
			public function __construct(private object $share, private ?\Throwable $failure) {
			}

			public function findByToken(string $token): object {
				if ($this->failure !== null) {
					throw $this->failure;
				}
				return $this->share;
			}
		};
	}

	/** @return array<string, mixed> */
	private static function share(array $overrides = []): array {
		return $overrides + [
			'direction' => 'outgoing',
			'status' => 'accepted',
			'permissions' => 'read-write',
			'objectUri' => 'https://dossiq.example.nl/api/objects/dossiq/transfer/' . self::TRANSFER . '/',
			'sharedWith' => 'gemeente@cloud.example.nl',
			'organisation' => 'org-7',
		];
	}

	public function testMintingAShareAsksForAReadWriteObjectShare(): void {
		$share = $this->broker(['OCA\OpenRegister\Service\FederationShareService' => $this->shareService()])
			->mintTransferShare(self::TRANSFER, 'gemeente@cloud.example.nl', 'dossiq', 'transfer');

		$this->assertSame('tok-1', $share?->token);
		$this->assertSame([[
			'scope' => 'object',
			'register' => 'dossiq',
			'schema' => 'transfer',
			'objectUri' => self::TRANSFER,
			'sharedWith' => 'gemeente@cloud.example.nl',
			'permissions' => 'read-write',
		]], $this->created);
	}

	public function testWithoutShareSupportNothingIsMinted(): void {
		$this->assertNull($this->broker([])->mintTransferShare(self::TRANSFER, 'x@y', 'dossiq', 'transfer'));
	}

	public function testAFailedMintIsLoggedAndReadsAsNone(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'CaseTransferService: OR createOutgoingShare failed',
			['transferUuid' => self::TRANSFER, 'exception' => 'remote refused']
		);

		$this->assertNull(
			$this->broker(['OCA\OpenRegister\Service\FederationShareService' => $this->shareService(new \RuntimeException('remote refused'))], $logger)
				->mintTransferShare(self::TRANSFER, 'x@y', 'dossiq', 'transfer')
		);
	}

	public function testAValidShareResolvesToItsReceiver(): void {
		$broker = $this->broker(['OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper(self::share())]);

		$this->assertSame(['sharedWith' => 'gemeente@cloud.example.nl', 'organisation' => 'org-7'], $broker->resolveTransferShare('tok-1', self::TRANSFER));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function refusedShares(): array {
		return [
			'incoming' => [['direction' => 'incoming']],
			'revoked' => [['status' => 'revoked']],
			'declined' => [['status' => 'declined']],
			'read only' => [['permissions' => 'read']],
			'another object' => [['objectUri' => 'https://dossiq.example.nl/api/objects/dossiq/transfer/other-uuid']],
		];
	}

	/**
	 * @dataProvider refusedShares
	 * @param array<string, mixed> $overrides
	 */
	public function testAShareThatDoesNotGrantThisTransferResolvesToNothing(array $overrides): void {
		$broker = $this->broker(['OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper(self::share($overrides))]);

		$this->assertNull($broker->resolveTransferShare('tok-1', self::TRANSFER));
	}

	public function testAnUnknownTokenOrEmptyInputResolvesToNothing(): void {
		$missing = $this->broker(['OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper([], new \RuntimeException('not found'))]);
		$valid = $this->broker(['OCA\OpenRegister\Db\FederatedShareMapper' => $this->mapper(self::share())]);

		$this->assertNull($missing->resolveTransferShare('tok-1', self::TRANSFER));
		$this->assertNull($valid->resolveTransferShare('', self::TRANSFER));
		$this->assertNull($valid->resolveTransferShare('tok-1', ''));
		$this->assertNull($this->broker([])->resolveTransferShare('tok-1', self::TRANSFER));
	}
}
