<?php

/**
 * Unit tests for ConfiguredCaseStore: the case store is usable only with an
 * object service and a configured register and case schema, and says which
 * of the two is missing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\ConfiguredCaseStore;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ConfiguredCaseStore
 */
class ConfiguredCaseStoreTest extends TestCase {

	/**
	 * Settings answering an object service and a register/schema pair.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Config values by key.
	 */
	private function settings(?object $objectService, array $config = []): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => $config[$key] ?? '');
		return $settings;
	}//end settings()

	public function testAConfiguredStoreHasNoRefusal(): void {
		$service = new stdClass();

		$store = new ConfiguredCaseStore($this->settings($service, ['register' => '4', 'case_schema' => '12']));

		$this->assertSame($service, $store->objectService);
		$this->assertSame('4', $store->register);
		$this->assertSame('12', $store->schema);
		$this->assertNull($store->refusal());
	}//end testAConfiguredStoreHasNoRefusal()

	public function testWithoutAnObjectServiceTheConfigIsNotReadAndStorageIsUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects($this->never())->method('getConfigValue');

		$store = new ConfiguredCaseStore($settings);

		$this->assertSame('', $store->register);
		$this->assertSame('', $store->schema);
		$this->assertSame('storage_unavailable', $store->refusal());
	}//end testWithoutAnObjectServiceTheConfigIsNotReadAndStorageIsUnavailable()

	public function testAMissingRegisterOrSchemaIsNotConfigured(): void {
		$this->assertSame('case_schema_not_configured', (new ConfiguredCaseStore($this->settings(new stdClass(), ['case_schema' => '12'])))->refusal());
		$this->assertSame('case_schema_not_configured', (new ConfiguredCaseStore($this->settings(new stdClass(), ['register' => '4'])))->refusal());
	}//end testAMissingRegisterOrSchemaIsNotConfigured()
}//end class
