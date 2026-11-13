<?php

/**
 * The register, the object service and a configured schema, as the case split and takeover stores read them.
 *
 * Pins what both stores answer and refuse before their readings are shared:
 * the object service and register pair, the schema a key names, and the
 * exception sentence for each missing piece.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseSplitStore;
use OCA\Dossiq\Service\Custody\TakeoverStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CaseMoveLedger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Both stores read the register and their schemas the same way.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitStore
 * @covers \OCA\Dossiq\Service\Custody\TakeoverStore
 *
 * @spec openspec/specs/case-management/spec.md
 */
class StoreRegisterContextTest extends TestCase {
	/**
	 * Both stores, built over the given settings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stores(): array {
		return [
			'case split store' => ['split'],
			'takeover store' => ['takeover'],
		];
	}//end stores()

	/**
	 * The object service and the configured register come back as a pair.
	 *
	 * @dataProvider stores
	 *
	 * @param string $which The store.
	 *
	 * @return void
	 */
	public function testContextIsTheObjectServiceAndTheRegister(string $which): void {
		$service = new stdClass();
		$store = $this->store(which: $which, settings: $this->settings(service: $service, config: ['register' => 'dossiq']));

		self::assertSame([$service, 'dossiq'], $store->context());
	}//end testContextIsTheObjectServiceAndTheRegister()

	/**
	 * Without OpenRegister the context is refused, before the register is read.
	 *
	 * @dataProvider stores
	 *
	 * @param string $which The store.
	 *
	 * @return void
	 */
	public function testContextRefusesWithoutOpenRegister(string $which): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');
		$store = $this->store(which: $which, settings: $settings);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');
		$store->context();
	}//end testContextRefusesWithoutOpenRegister()

	/**
	 * Without a configured register the context is refused.
	 *
	 * @dataProvider stores
	 *
	 * @param string $which The store.
	 *
	 * @return void
	 */
	public function testContextRefusesWithoutARegister(string $which): void {
		$store = $this->store(which: $which, settings: $this->settings(service: new stdClass(), config: []));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossier register not configured');
		$store->context();
	}//end testContextRefusesWithoutARegister()

	/**
	 * A configured schema is the value its key holds.
	 *
	 * @dataProvider stores
	 *
	 * @param string $which The store.
	 *
	 * @return void
	 */
	public function testSchemaIsTheConfiguredValue(string $which): void {
		$store = $this->store(which: $which, settings: $this->settings(service: null, config: ['case_schema' => 'case']));

		self::assertSame('case', $store->schema(key: 'case_schema'));
	}//end testSchemaIsTheConfiguredValue()

	/**
	 * An unset schema is refused with the key in the sentence.
	 *
	 * @dataProvider stores
	 *
	 * @param string $which The store.
	 *
	 * @return void
	 */
	public function testSchemaRefusesAnUnsetKey(string $which): void {
		$store = $this->store(which: $which, settings: $this->settings(service: null, config: []));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossiq schema case_schema not configured');
		$store->schema(key: 'case_schema');
	}//end testSchemaRefusesAnUnsetKey()

	/**
	 * Settings answering the given object service and configuration.
	 *
	 * @param object|null           $service The object service, or null.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?object $service, array $config): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return $settings;
	}//end settings()

	/**
	 * One of the two stores over the given settings.
	 *
	 * @param string          $which    'split' or 'takeover'.
	 * @param SettingsService $settings The settings.
	 *
	 * @return CaseSplitStore|TakeoverStore The store.
	 */
	private function store(string $which, SettingsService $settings): CaseSplitStore|TakeoverStore {
		if ($which === 'split') {
			return new CaseSplitStore(settingsService: $settings, ledger: $this->createMock(originalClassName: CaseMoveLedger::class));
		}

		return new TakeoverStore($settings, $this->createMock(originalClassName: LoggerInterface::class));
	}//end store()
}//end class
