<?php

/**
 * Unit tests for BeschikkingRepository: find returns null when storage,
 * configuration or the lookup is missing; requireBeschikking refuses a missing
 * beschikking and keeps its id; save refuses without storage or schema and
 * otherwise stores under the configured register and schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use JsonSerializable;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingRepository
 *
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BeschikkingRepositoryTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	/**
	 * Config values returned by the settings mock.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'beschikking_schema' => 'beschikking'];

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function repository(): BeschikkingRepository {
		return new BeschikkingRepository($this->settings, $this->logger);
	}//end repository()

	/**
	 * A fake ObjectService recording finds and saves.
	 */
	private function objectService(mixed $found = null, ?\Throwable $failure = null): object {
		return new class($found, $failure) {
			/** @var array<int, array<int, string>> */
			public array $finds = [];

			/** @var array<int, array<int, mixed>> */
			public array $saves = [];

			public function __construct(private mixed $found, private ?\Throwable $failure) {
			}

			public function find(string $id, string $register = '', string $schema = ''): mixed {
				$this->finds[] = [$id, $register, $schema];
				if ($this->failure !== null) {
					throw $this->failure;
				}
				return $this->found;
			}

			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saves[] = [$object, $register, $schema];
				return $object + ['id' => 'saved-1'];
			}
		};
	}//end objectService()

	public function testFindLooksUpUnderTheConfiguredRegisterAndSchema(): void {
		$objects = $this->objectService(['id' => 'b-1', 'status' => 'concept']);
		$this->settings->method('getObjectService')->willReturn($objects);

		$this->assertSame(['id' => 'b-1', 'status' => 'concept'], $this->repository()->find('b-1'));
		$this->assertSame([['b-1', 'dossiq', 'beschikking']], $objects->finds);
	}//end testFindLooksUpUnderTheConfiguredRegisterAndSchema()

	public function testFindSerialisesAnEntity(): void {
		$entity = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'b-2'];
			}
		};
		$this->settings->method('getObjectService')->willReturn($this->objectService($entity));

		$this->assertSame(['id' => 'b-2'], $this->repository()->find('b-2'));
	}//end testFindSerialisesAnEntity()

	public function testFindReturnsNullWithoutStorage(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->assertNull($this->repository()->find('b-3'));
	}//end testFindReturnsNullWithoutStorage()

	public function testFindReturnsNullWithoutAConfiguredSchemaAndDoesNotLookUp(): void {
		$this->config['beschikking_schema'] = '';
		$objects = $this->objectService(['id' => 'b-4']);
		$this->settings->method('getObjectService')->willReturn($objects);

		$this->assertNull($this->repository()->find('b-4'));
		$this->assertSame([], $objects->finds);
	}//end testFindReturnsNullWithoutAConfiguredSchemaAndDoesNotLookUp()

	public function testAFailingLookupIsLoggedAndReturnsNull(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectService(null, new RuntimeException('gone')));
		$this->logger->expects($this->once())->method('error')
			->with('BeschikkingService: find failed', ['exception' => 'gone', 'decisionId' => 'b-5']);

		$this->assertNull($this->repository()->find('b-5'));
	}//end testAFailingLookupIsLoggedAndReturnsNull()

	public function testANonArrayResultBecomesAnEmptyArray(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectService('scalar'));

		$this->assertSame([], $this->repository()->find('b-6'));
	}//end testANonArrayResultBecomesAnEmptyArray()

	public function testRequireBeschikkingRefusesAMissingOne(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not_found');
		$this->repository()->requireBeschikking('b-7');
	}//end testRequireBeschikkingRefusesAMissingOne()

	public function testRequireBeschikkingAddsTheIdWhenAbsentAndKeepsAnExistingOne(): void {
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls(
			$this->objectService(['status' => 'concept']),
			$this->objectService(['id' => 'uuid-8', 'status' => 'concept'])
		);

		$this->assertSame(['status' => 'concept', 'id' => 'b-8'], $this->repository()->requireBeschikking('b-8'));
		$this->assertSame(['id' => 'uuid-8', 'status' => 'concept'], $this->repository()->requireBeschikking('b-8'));
	}//end testRequireBeschikkingAddsTheIdWhenAbsentAndKeepsAnExistingOne()

	public function testSaveStoresUnderTheConfiguredRegisterAndSchema(): void {
		$objects = $this->objectService();
		$this->settings->method('getObjectService')->willReturn($objects);

		$this->assertSame(['status' => 'getekend', 'id' => 'saved-1'], $this->repository()->save(['status' => 'getekend']));
		$this->assertSame([[['status' => 'getekend'], 'dossiq', 'beschikking']], $objects->saves);
	}//end testSaveStoresUnderTheConfiguredRegisterAndSchema()

	public function testSaveRefusesWithoutStorage(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('storage_unavailable');
		$this->repository()->save(['status' => 'concept']);
	}//end testSaveRefusesWithoutStorage()

	public function testSaveRefusesWithoutAConfiguredRegister(): void {
		$this->config['register'] = '';
		$objects = $this->objectService();
		$this->settings->method('getObjectService')->willReturn($objects);

		try {
			$this->repository()->save(['status' => 'concept']);
			$this->fail('save() stored without a register');
		} catch (RuntimeException $e) {
			$this->assertSame('beschikking_schema_not_configured', $e->getMessage());
		}

		$this->assertSame([], $objects->saves);
	}//end testSaveRefusesWithoutAConfiguredRegister()
}//end class
