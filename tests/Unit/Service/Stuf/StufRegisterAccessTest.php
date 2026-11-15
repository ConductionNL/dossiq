<?php

/**
 * Unit tests for StufRegisterAccess.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
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

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCP\App\IAppManager;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Stuf\StufRegisterAccess
 */
class StufRegisterAccessTest extends TestCase {
	/** @var list<array<int|string, mixed>> */
	public array $calls = [];

	private function access(?object $service, bool $installed = true, ?LoggerInterface $logger = null): StufRegisterAccess {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($service): object {
			if ($service === null) {
				throw new \RuntimeException('no ObjectService');
			}
			return $service;
		});
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $app === 'dossiq' && $key === 'register' ? 'reg-7' : $default);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('openregister')->willReturn($installed);

		return new StufRegisterAccess($container, $config, $logger ?? $this->createMock(LoggerInterface::class), $apps);
	}

	/**
	 * A stand-in ObjectService recording its calls.
	 *
	 * @param mixed $answer What find/findAll/saveObject return, or a Throwable to throw.
	 */
	private function objectService(mixed $answer): object {
		return new class($this, $answer) {
			public function __construct(private StufRegisterAccessTest $test, private mixed $answer) {
			}

			private function answer(string $method, array $arguments): mixed {
				$this->test->calls[] = [$method, $arguments];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}
				return $this->answer;
			}

			public function saveObject(array $data, array $extend, string $register, string $schema, ?string $uuid): mixed {
				return $this->answer('saveObject', [$data, $extend, $register, $schema, $uuid]);
			}

			public function find(string $id, string $register, string $schema): mixed {
				return $this->answer('find', [$id, $register, $schema]);
			}

			public function findAll(array $config): mixed {
				return $this->answer('findAll', [$config]);
			}
		};
	}

	private static function entity(array $data): object {
		return new class($data) implements \JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}

	public function testSavingWritesIntoTheConfiguredRegister(): void {
		$access = $this->access($this->objectService(self::entity(['id' => 'm-1', 'status' => 'queued'])));

		$this->assertSame(['id' => 'm-1', 'status' => 'queued'], $access->saveObject(StufRegisterAccess::SCHEMA_MESSAGE, ['status' => 'queued']));
		$this->assertSame([['saveObject', [['status' => 'queued'], [], 'reg-7', 'stufMessage', null]]], $this->calls);
	}

	public function testSavingWithoutOpenRegisterThrows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Dossiq StUF: OpenRegister is not installed; the StUF register is unavailable.');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cannot save StUF object: OpenRegister is unavailable.');

		$this->access($this->objectService([]), false, $logger)->saveObject('stufMessage', []);
	}

	public function testFindingByIdReturnsTheSerialisedObject(): void {
		$access = $this->access($this->objectService(self::entity(['id' => 'e-1', 'endpointUrl' => 'https://zs'])));

		$this->assertSame(['id' => 'e-1', 'endpointUrl' => 'https://zs'], $access->findById(StufRegisterAccess::SCHEMA_ENDPOINT, 'e-1'));
		$this->assertSame([['find', ['e-1', 'reg-7', 'stufEndpoint']]], $this->calls);
	}

	/** @return array<string, array{mixed}> */
	public static function nothingFound(): array {
		return [
			'null' => [null],
			'empty array' => [[]],
			'not serialisable' => [new \stdClass()],
		];
	}

	/** @dataProvider nothingFound */
	public function testFindingByIdReadsAnEmptyAnswerAsNotFound(mixed $answer): void {
		$this->assertNull($this->access($this->objectService($answer))->findById('stufEndpoint', 'e-1'));
	}

	public function testAnEmptyIdIsNotLookedUp(): void {
		$access = $this->access($this->objectService(['id' => 'x']));

		$this->assertNull($access->findById('stufEndpoint', ''));
		$this->assertSame([], $this->calls);
	}

	public function testAFailingLookupIsLoggedAndReadsAsNotFound(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with(
			$this->logicalOr('StUF register findById failed: {err}', 'StUF register findAll failed: {err}'),
			['err' => 'db gone', 'schema' => 'stufEndpoint']
		);
		$access = $this->access($this->objectService(new \RuntimeException('db gone')), true, $logger);

		$this->assertNull($access->findById('stufEndpoint', 'e-1'));
		$this->assertSame([], $access->findAll('stufEndpoint'));
	}

	public function testFindAllFiltersOnRegisterAndSchema(): void {
		$access = $this->access($this->objectService([self::entity(['id' => 'a']), ['id' => 'b']]));

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $access->findAll('zaaksysteemMapping', ['zaaktype' => 'zt-1'], 20));
		$this->assertSame([['findAll', [['filters' => ['register' => 'reg-7', 'schema' => 'zaaksysteemMapping', 'zaaktype' => 'zt-1'], 'limit' => 20]]]], $this->calls);
	}

	public function testFindOneAsksForOneAndReturnsTheFirst(): void {
		$access = $this->access($this->objectService([['id' => 'a']]));

		$this->assertSame(['id' => 'a'], $access->findOne('stufEndpoint', ['name' => 'x']));
		$this->assertSame(1, $this->calls[0][1][0]['limit']);
		$this->assertNull($this->access($this->objectService([]))->findOne('stufEndpoint', []));
	}

	public function testANonListAnswerReadsAsEmpty(): void {
		$this->assertSame([], $this->access($this->objectService('rows'))->findAll('stufEndpoint'));
	}

	public function testAnUnresolvableObjectServiceReadsAsEmpty(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Dossiq StUF: could not resolve OpenRegister ObjectService: no ObjectService');

		$this->assertSame([], $this->access(null, true, $logger)->findAll('stufEndpoint'));
	}
}
