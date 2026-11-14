<?php

/**
 * Unit tests for MandaatRepository::save(): a configured store persists the
 * object through the configured schema, a save that throws is logged and the
 * payload is returned, and an unconfigured store returns the payload untouched.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mandaat;

use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredSchemaWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * MandaatRepository::save() over the configured schema writer.
 */
#[CoversClass(MandaatRepository::class)]
#[UsesClass(ConfiguredSchemaWriter::class)]
class MandaatRepositorySaveTest extends TestCase {

	private SettingsService&MockObject $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
	}//end setUp()

	/**
	 * A store that records each save, and throws for a schema named in $failing.
	 *
	 * @param array<int, string> $failing Schemas whose save throws.
	 */
	private function store(array $failing = []): object {
		return new class($failing) {
			/** @var list<array{string, array<string, mixed>, ?string}> */
			public array $saved = [];

			/** @param array<int, string> $failing */
			public function __construct(private array $failing) {
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if (in_array($schema, $this->failing, true) === true) {
					throw new RuntimeException('store down');
				}

				$this->saved[] = [$schema, $object, $uuid];
				return $object + ['id' => 'saved-1'];
			}
		};
	}//end store()

	/**
	 * Wire the settings to a store and a config map.
	 *
	 * @param object|null          $store  The object service, or null for none.
	 * @param array<string, string> $config The config values.
	 */
	private function wire(?object $store, array $config): void {
		$this->settings->method('getObjectService')->willReturn($store);
		$this->settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
	}//end wire()

	private function repository(): MandaatRepository {
		return new MandaatRepository($this->settings, $this->createMock(LoggerInterface::class));
	}//end repository()

	public function testAConfiguredStoreSavesThroughItsSchema(): void {
		$store = $this->store();
		$this->wire(store: $store, config: ['register' => 'dossiq', 'mandaat_schema' => 'mandate']);

		$saved = $this->repository()->save(schemaConfigKey: 'mandaat_schema', object: ['role' => 'r-1']);

		self::assertSame(['role' => 'r-1', 'id' => 'saved-1'], $saved);
		self::assertSame([['mandate', ['role' => 'r-1'], null]], $store->saved);
	}//end testAConfiguredStoreSavesThroughItsSchema()

	public function testASaveThatThrowsIsLoggedAndReturnsThePayload(): void {
		$this->wire(store: $this->store(failing: ['mandate']), config: ['register' => 'dossiq', 'mandaat_schema' => 'mandate']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Mandaat import persist failed',
			self::callback(static fn (array $c): bool => $c['key'] === 'mandaat_schema' && $c['error'] === 'store down')
		);

		$payload = ['role' => 'r-9'];
		self::assertSame($payload, (new MandaatRepository($this->settings, $logger))->save(schemaConfigKey: 'mandaat_schema', object: $payload));
	}//end testASaveThatThrowsIsLoggedAndReturnsThePayload()

	public function testAnUnconfiguredStoreReturnsThePayloadUntouched(): void {
		$payload = ['role' => 'r-2'];

		$this->wire(store: null, config: ['register' => 'dossiq', 'mandaat_schema' => 'mandate']);
		self::assertSame($payload, $this->repository()->save(schemaConfigKey: 'mandaat_schema', object: $payload));

		$this->setUp();
		$this->wire(store: $this->store(), config: ['register' => '', 'mandaat_schema' => 'mandate']);
		self::assertSame($payload, $this->repository()->save(schemaConfigKey: 'mandaat_schema', object: $payload));

		$this->setUp();
		$this->wire(store: $this->store(), config: ['register' => 'dossiq', 'mandaat_schema' => '']);
		self::assertSame($payload, $this->repository()->save(schemaConfigKey: 'mandaat_schema', object: $payload));
	}//end testAnUnconfiguredStoreReturnsThePayloadUntouched()
}//end class
