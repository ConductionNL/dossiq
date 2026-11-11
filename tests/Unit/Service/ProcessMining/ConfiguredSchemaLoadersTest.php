<?php

/**
 * How the turnaround and process-mining reports read a configured schema.
 *
 * Pins every loader of DoorlooptijdService and ProcessMiningDataLoader before
 * their shared reading moves to one helper: an empty list without
 * OpenRegister, without a register or without the schema, and otherwise the
 * rows of the configured schema under the loader's own filters.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Each loader reads its own schema, or answers an empty list.
 *
 * @covers \OCA\Dossiq\Service\DoorlooptijdService
 * @covers \OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class ConfiguredSchemaLoadersTest extends TestCase {
	/**
	 * Each loader, the schema key it reads and the filters it sends.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function loaders(): array {
		return [
			'turnaround cases' => ['doorlooptijd.loadCases', 'case_schema', ['_limit' => 1000, 'caseType' => 'permit']],
			'turnaround case types' => ['doorlooptijd.loadCaseTypes', 'case_type_schema', ['_limit' => 500]],
			'mining cases' => ['mining.loadCases', 'case_schema', ['_limit' => 2000, 'caseType' => 'permit']],
			'mining case types' => ['mining.loadCaseTypes', 'case_type_schema', ['_limit' => 500]],
			'mining status types' => ['mining.loadStatusTypes', 'status_type_schema', ['_limit' => 500]],
			'mining status records' => ['mining.loadStatusRecords', 'status_record_schema', ['_limit' => 10000]],
		];
	}//end loaders()

	/**
	 * A configured schema is searched under the loader's filters.
	 *
	 * @param string               $loader  The loader.
	 * @param string               $key     The schema key it reads.
	 * @param array<string, mixed> $filters The filters it sends.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testAConfiguredSchemaIsSearched(string $loader, string $key, array $filters): void {
		$register = $this->register();
		$rows = $this->load(loader: $loader, settings: $this->settings(service: $register, config: ['register' => 'dossiq', $key => 'things']));

		self::assertSame(expected: [['id' => 'a', 'title' => 'A']], actual: $rows);
		self::assertSame(expected: [['dossiq', 'things', $filters]], actual: $register->calls);
	}//end testAConfiguredSchemaIsSearched()

	/**
	 * Without OpenRegister the list is empty and no configuration is read.
	 *
	 * @param string $loader The loader.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheListIsEmpty(string $loader): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');

		self::assertSame(expected: [], actual: $this->load(loader: $loader, settings: $settings));
	}//end testWithoutOpenRegisterTheListIsEmpty()

	/**
	 * Without a register, or without the schema, the list is empty and nothing is searched.
	 *
	 * @param string $loader The loader.
	 * @param string $key    The schema key it reads.
	 *
	 * @dataProvider loaders
	 *
	 * @return void
	 */
	public function testAMissingRegisterOrSchemaGivesAnEmptyList(string $loader, string $key): void {
		$register = $this->register();
		$noRegister = $this->load(loader: $loader, settings: $this->settings(service: $register, config: [$key => 'things']));
		$noSchema = $this->load(loader: $loader, settings: $this->settings(service: $register, config: ['register' => 'dossiq']));

		self::assertSame(expected: [[], []], actual: [$noRegister, $noSchema]);
		self::assertSame(expected: [], actual: $register->calls);
	}//end testAMissingRegisterOrSchemaGivesAnEmptyList()

	/**
	 * An object service that records each slug search and answers one row.
	 *
	 * @return object The register double.
	 */
	private function register(): object {
		return new class {
			/**
			 * The searches, as register, schema and filters.
			 *
			 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Record a slug search.
			 *
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> One row.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = [$register, $schema, $filters];

				return [['id' => 'a', 'title' => 'A']];
			}//end searchObjectsBySlug()
		};
	}//end register()

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
	 * Run one loader over the given settings; the turnaround loaders are private.
	 *
	 * @param string          $loader   'doorlooptijd.<method>' or 'mining.<method>'.
	 * @param SettingsService $settings The settings.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function load(string $loader, SettingsService $settings): array {
		[$owner, $method] = explode(separator: '.', string: $loader);
		$subject = new ProcessMiningDataLoader(settingsService: $settings);
		if ($owner === 'doorlooptijd') {
			$subject = $this->doorlooptijd(settings: $settings);
		}

		$arguments = [];
		if ($method === 'loadCases') {
			$arguments = ['permit'];
		}

		return (new ReflectionMethod($subject, $method))->invoke($subject, ...$arguments);
	}//end load()

	/**
	 * The turnaround service over the given settings, its calculators doubles.
	 *
	 * @param SettingsService $settings The settings.
	 *
	 * @return DoorlooptijdService The service.
	 */
	private function doorlooptijd(SettingsService $settings): DoorlooptijdService {
		$arguments = [];
		foreach ((new ReflectionClass(DoorlooptijdService::class))->getConstructor()->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$arguments[] = $this->argument(type: $type->getName(), settings: $settings);
		}

		return new DoorlooptijdService(...$arguments);
	}//end doorlooptijd()

	/**
	 * The settings for a SettingsService parameter, a double for any other.
	 *
	 * @param class-string    $type     The parameter's class.
	 * @param SettingsService $settings The settings.
	 *
	 * @return object The argument.
	 */
	private function argument(string $type, SettingsService $settings): object {
		if ($type === SettingsService::class) {
			return $settings;
		}

		return $this->createMock(originalClassName: $type);
	}//end argument()
}//end class
