<?php

/**
 * Unit tests for ConfiguredSchemaStore.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredSchemaStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reads answer nothing without a store; writes refuse; a create drops identity keys.
 *
 * @covers \OCA\Dossiq\Service\Support\ConfiguredSchemaStore
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\ObjectSearch
 * @uses   \OCA\Dossiq\Service\Support\CallerIdentity
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ConfiguredSchemaStoreTest extends TestCase {

	/**
	 * A store over a settings double answering the given object service and config.
	 *
	 * @param object|null $objectService The object service, or null for none.
	 * @param string $register The configured register.
	 * @param string $schema The configured schema under `thing_schema`.
	 *
	 * @return ConfiguredSchemaStore
	 */
	private function store(?object $objectService, string $register = 'dossiq', string $schema = 'thing'): ConfiguredSchemaStore {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', $register],
			['thing_schema', '', $schema],
		]);

		return new ConfiguredSchemaStore(settingsService: $settings, schemaKey: 'thing_schema', notConfigured: 'Thing schema not configured');
	}//end store()

	/**
	 * An object service that records its saves and answers fixed reads.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class {
			/** @var list<array{object: array<string, mixed>, options: ObjectSaveOptions}> */
			public array $saves = [];

			/** @var list<array<string, mixed>> */
			public array $searches = [];

			public function find(int|string $id, mixed ...$args): array {
				return ['id' => (string)$id, 'title' => 'Found'];
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->searches[] = ['register' => $register, 'schema' => $schema, 'filters' => $filters];
				return [['id' => 'a'], ['id' => 'b']];
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				$this->saves[] = ['object' => $object, 'options' => $options];
				return $object + ['saved' => true];
			}
		};
	}//end objectService()

	/**
	 * Reads go to the configured register and schema.
	 *
	 * @return void
	 */
	public function testReadsUseTheConfiguredSchema(): void {
		$service = $this->objectService();
		$store   = $this->store(objectService: $service);

		self::assertSame(['id' => 'x-1', 'title' => 'Found'], $store->find(id: 'x-1'));
		self::assertSame([['id' => 'a'], ['id' => 'b']], $store->search(filters: ['_limit' => 5]));
		self::assertSame('dossiq', $service->searches[0]['register']);
		self::assertSame('thing', $service->searches[0]['schema']);
		self::assertSame(['_limit' => 5], $service->searches[0]['filters']);
	}//end testReadsUseTheConfiguredSchema()

	/**
	 * Without OpenRegister or a schema a read answers nothing.
	 *
	 * @return void
	 */
	public function testAReadWithoutAStoreAnswersNothing(): void {
		self::assertNull($this->store(objectService: null)->find(id: 'x'));
		self::assertSame([], $this->store(objectService: null)->search(filters: []));
		self::assertNull($this->store(objectService: $this->objectService(), schema: '')->find(id: 'x'));
		self::assertSame([], $this->store(objectService: $this->objectService(), register: '')->search(filters: []));
	}//end testAReadWithoutAStoreAnswersNothing()

	/**
	 * A create refuses without OpenRegister, then without a schema, before preparing anything.
	 *
	 * @return void
	 */
	public function testACreateRefusesBeforeItPrepares(): void {
		$prepared = false;
		$prepare  = function (array $object) use (&$prepared): array {
			$prepared = true;
			return $object;
		};

		try {
			$this->store(objectService: null)->create(object: [], prepare: $prepare);
			self::fail('no refusal without OpenRegister');
		} catch (RuntimeException $e) {
			self::assertSame('OpenRegister is not available', $e->getMessage());
		}

		try {
			$this->store(objectService: $this->objectService(), schema: '')->create(object: [], prepare: $prepare);
			self::fail('no refusal without a schema');
		} catch (RuntimeException $e) {
			self::assertSame('Thing schema not configured', $e->getMessage());
		}

		self::assertFalse($prepared);
	}//end testACreateRefusesBeforeItPrepares()

	/**
	 * A create drops the identity keys, prepares the record and saves it as new.
	 *
	 * @return void
	 */
	public function testACreateDropsIdentityKeysAndSavesThePreparedRecord(): void {
		$service = $this->objectService();
		$answer  = $this->store(objectService: $service)->create(
			object: ['id' => 'forged', 'uuid' => 'forged', '@self' => ['id' => 'forged'], 'title' => 'T'],
			prepare: fn (array $object): array => $object + ['status' => 'received']
		);

		self::assertSame(['title' => 'T', 'status' => 'received', 'saved' => true], $answer);
		self::assertSame(['title' => 'T', 'status' => 'received'], $service->saves[0]['object']);
		self::assertNull($service->saves[0]['options']->uuid);
		self::assertSame('dossiq', $service->saves[0]['options']->target->register);
		self::assertSame('thing', $service->saves[0]['options']->target->schema);
	}//end testACreateDropsIdentityKeysAndSavesThePreparedRecord()

	/**
	 * An update writes onto the uuid and refuses only without OpenRegister.
	 *
	 * @return void
	 */
	public function testAnUpdateWritesOntoTheUuid(): void {
		$service = $this->objectService();
		$this->store(objectService: $service)->update(id: 'u-1', object: ['status' => 'handled']);

		self::assertSame(['status' => 'handled'], $service->saves[0]['object']);
		self::assertSame('u-1', $service->saves[0]['options']->uuid);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$this->store(objectService: null)->update(id: 'u-1', object: []);
	}//end testAnUpdateWritesOntoTheUuid()
}//end class
