<?php

/**
 * StarterStore Unit Tests.
 *
 * Pins when the store has nothing to work on (no object service, register or
 * schema), the system path that skips RBAC on read and write, the delete call,
 * and that every storage failure is logged and answered with null or false.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Starter
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

namespace OCA\Dossiq\Tests\Unit\Service\Starter;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for StarterStore.
 *
 * @covers \OCA\Dossiq\Service\Starter\StarterStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class StarterStoreTest extends TestCase {

	/**
	 * Calls the object service fake received, as [method, named args].
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	public array $calls = [];

	/**
	 * What the fake throws on every call, or null.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $failure = null;

	/**
	 * Messages the store logged, as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array}>
	 */
	private array $logged = [];

	/**
	 * A recording object service fake.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;
		return new class ($test) {
			public function __construct(private StarterStoreTest $test) {
			}

			private function record(string $method, array $args): void {
				$this->test->calls[] = [$method, $args];
				if ($this->test->failure !== null) {
					throw $this->test->failure;
				}
			}

			public function find(string $id, $register = null, $schema = null, bool $_rbac = true): array {
				$this->record('find', ['id' => $id, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac]);
				return ['id' => $id];
			}

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): array {
				$this->record('saveObject', ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac]);
				return $object + ['id' => ($uuid ?? 'new')];
			}

			public function deleteObject(string $uuid, $register = null, $schema = null): bool {
				$this->record('deleteObject', ['uuid' => $uuid, 'register' => $register, 'schema' => $schema]);
				return true;
			}

			public function searchObjects(array $query): array {
				$this->record('searchObjects', ['query' => $query]);
				return [];
			}
		};
	}//end objectService()

	/**
	 * A store over the given configuration.
	 *
	 * @param bool                 $withService Whether OpenRegister is available.
	 * @param array<string,string> $config      The config values.
	 *
	 * @return StarterStore
	 */
	private function store(bool $withService = true, array $config = ['register' => '4', 'case_type_schema' => '165']): StarterStore {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($withService === true ? $this->objectService() : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? '')
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new StarterStore(settings: $settings, logger: $logger);
	}//end store()

	/**
	 * Without an object service, register or schema nothing is touched.
	 *
	 * @return void
	 */
	public function testWithoutAContextNothingIsTouched(): void {
		$stores = [
			$this->store(withService: false),
			$this->store(config: ['case_type_schema' => '165']),
			$this->store(config: ['register' => '4']),
		];

		foreach ($stores as $store) {
			$this->assertFalse($store->available('case_type_schema'));
			$this->assertNull($store->rows('case_type_schema'));
			$this->assertNull($store->row('case_type_schema', 'ct-1'));
			$this->assertNull($store->save('case_type_schema', ['name' => 'x']));
			$this->assertFalse($store->delete('case_type_schema', 'ct-1'));
		}

		$this->assertSame([], $this->calls);
		$this->assertTrue($this->store()->available('case_type_schema'));
	}//end testWithoutAContextNothingIsTouched()

	/**
	 * Listing defaults the limit to 500 and keeps a caller's own limit.
	 *
	 * @return void
	 */
	public function testRowsDefaultTheLimit(): void {
		$store = $this->store();

		$this->assertSame([], $store->rows('case_type_schema', ['status' => 'active']));
		$this->assertSame([], $store->rows('case_type_schema', ['_limit' => 20]));

		$this->assertSame(500, $this->calls[0][1]['query']['_limit']);
		$this->assertSame('active', $this->calls[0][1]['query']['status']);
		$this->assertSame(['register' => 4, 'schema' => 165], $this->calls[0][1]['query']['@self']);
		$this->assertSame(20, $this->calls[1][1]['query']['_limit']);
	}//end testRowsDefaultTheLimit()

	/**
	 * The system store reads without RBAC, and a missing row is null without a log.
	 *
	 * @return void
	 */
	public function testTheSystemStoreReadsWithoutRbac(): void {
		$system = $this->store()->asSystem();

		$this->assertSame(['id' => 'ct-1'], $system->row('case_type_schema', 'ct-1'));
		$this->assertSame(['find', ['id' => 'ct-1', 'register' => '4', 'schema' => '165', '_rbac' => false]], $this->calls[0]);
		$this->assertNull($system->row('case_type_schema', ''));
		$this->assertCount(1, $this->calls);

		$this->failure = new DoesNotExistException('gone');
		$this->assertNull($system->row('case_type_schema', 'ct-2'));
		$this->assertSame([], $this->logged);
	}//end testTheSystemStoreReadsWithoutRbac()

	/**
	 * The system store writes without RBAC under the given uuid; the plain
	 * store does not switch RBAC off.
	 *
	 * @return void
	 */
	public function testTheSystemStoreWritesWithoutRbac(): void {
		$saved = $this->store()->asSystem()->save('case_type_schema', ['name' => 'Vergunning'], 'ct-1');

		$this->assertSame(['name' => 'Vergunning', 'id' => 'ct-1'], $saved);
		$this->assertSame(
			['saveObject', ['object' => ['name' => 'Vergunning'], 'register' => '4', 'schema' => '165', 'uuid' => 'ct-1', '_rbac' => false]],
			$this->calls[0]
		);

		$this->store()->save('case_type_schema', ['name' => 'Melding']);
		$this->assertTrue($this->calls[1][1]['_rbac']);
		$this->assertNull($this->calls[1][1]['uuid']);
	}//end testTheSystemStoreWritesWithoutRbac()

	/**
	 * Delete removes the row by uuid within the register and schema; a blank id
	 * deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteRemovesTheRow(): void {
		$store = $this->store();

		$this->assertTrue($store->delete('case_type_schema', 'ct-1'));
		$this->assertFalse($store->delete('case_type_schema', ''));
		$this->assertSame([['deleteObject', ['uuid' => 'ct-1', 'register' => '4', 'schema' => '165']]], $this->calls);
	}//end testDeleteRemovesTheRow()

	/**
	 * Every storage failure is logged with the schema and answered null/false.
	 *
	 * @return void
	 */
	public function testStorageFailuresAreLoggedAndSoft(): void {
		$this->failure = new RuntimeException('db down');
		$store = $this->store();

		$this->assertNull($store->rows('case_type_schema'));
		$this->assertNull($store->row('case_type_schema', 'ct-1'));
		$this->assertNull($store->asSystem()->save('case_type_schema', [], 'ct-1'));
		$this->assertFalse($store->delete('case_type_schema', 'ct-1'));

		$this->assertSame(
			[
				['warning', 'Dossiq starter: could not list rows', ['schema' => 'case_type_schema', 'exception' => 'db down']],
				['warning', 'Dossiq starter: could not read a row', ['schema' => 'case_type_schema', 'id' => 'ct-1', 'exception' => 'db down']],
				['error', 'Dossiq starter: could not write a row', ['schema' => 'case_type_schema', 'id' => 'ct-1', 'exception' => 'db down']],
				['error', 'Dossiq starter: could not delete a row', ['schema' => 'case_type_schema', 'id' => 'ct-1', 'exception' => 'db down']],
			],
			$this->logged
		);
	}//end testStorageFailuresAreLoggedAndSoft()

	/**
	 * idOf reads id, then uuid, then the same under @self.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$store = $this->store();

		$this->assertSame('a', $store->idOf(['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $store->idOf(['uuid' => 'b']));
		$this->assertSame('c', $store->idOf(['@self' => ['id' => 'c']]));
		$this->assertSame('d', $store->idOf(['@self' => ['uuid' => 'd']]));
		$this->assertSame('', $store->idOf(['@self' => 'x']));
		$this->assertSame('', $store->idOf(null));
		$this->assertSame('165', $store->schemaIdOf('case_type_schema'));
	}//end testIdOf()
}//end class
