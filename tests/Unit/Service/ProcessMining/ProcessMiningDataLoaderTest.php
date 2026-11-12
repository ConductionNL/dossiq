<?php

/**
 * Loading the cases, case types, status types and status records process mining reads, and indexing them.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/process-mining-bottlenecks/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\ProcessMining\ProcessMiningDataLoader
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ProcessMiningDataLoaderTest extends TestCase {

	/**
	 * An object service recording each search and answering one row per call.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class {
			/**
			 * Searches made, as [path, args].
			 *
			 * @var array<int, array{0: string, 1: array<int, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Slug search.
			 *
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param array<string, mixed> $filters  Filters.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['slug', [$register, $schema, $filters]];
				return [['id' => $schema . '-1']];
			}

			/**
			 * Numeric search.
			 *
			 * @param array<string, mixed> $query Query.
			 *
			 * @return array<int, array<string, string>>
			 */
			public function searchObjects(array $query): array {
				$this->calls[] = ['numeric', [$query]];
				return [['id' => 'n-1']];
			}
		};
	}//end objectService()

	/**
	 * A loader over the given service and configuration.
	 *
	 * @param object|null           $service The object service.
	 * @param array<string, string> $config  The configuration.
	 *
	 * @return ProcessMiningDataLoader
	 */
	private function loader(?object $service, array $config): ProcessMiningDataLoader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new ProcessMiningDataLoader(settingsService: $settings);
	}//end loader()

	/**
	 * Every schema configured by slug.
	 *
	 * @return array<string, string>
	 */
	private static function slugs(): array {
		return [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'case_type_schema' => 'caseType',
			'status_type_schema' => 'statusType',
			'status_record_schema' => 'status',
		];
	}//end slugs()

	public function testEachLoadSearchesItsSchemaWithItsLimit(): void {
		$service = $this->objectService();
		$loader = $this->loader(service: $service, config: self::slugs());

		self::assertSame([['id' => 'case-1']], $loader->loadCases(caseTypeFilter: null));
		self::assertSame([['id' => 'caseType-1']], $loader->loadCaseTypes());
		self::assertSame([['id' => 'statusType-1']], $loader->loadStatusTypes());
		self::assertSame([['id' => 'status-1']], $loader->loadStatusRecords());

		self::assertSame(
			[
				['slug', ['dossiq', 'case', ['_limit' => ProcessMiningDataLoader::CASE_LIMIT]]],
				['slug', ['dossiq', 'caseType', ['_limit' => 500]]],
				['slug', ['dossiq', 'statusType', ['_limit' => 500]]],
				['slug', ['dossiq', 'status', ['_limit' => 10000]]],
			],
			$service->calls
		);
	}//end testEachLoadSearchesItsSchemaWithItsLimit()

	public function testACaseTypeFilterNarrowsTheCasesButAnEmptyOneDoesNot(): void {
		$service = $this->objectService();
		$loader = $this->loader(service: $service, config: self::slugs());

		$loader->loadCases(caseTypeFilter: 'ct-woo');
		$loader->loadCases(caseTypeFilter: '');

		self::assertSame(['_limit' => 2000, 'caseType' => 'ct-woo'], $service->calls[0][1][2]);
		self::assertSame(['_limit' => 2000], $service->calls[1][1][2]);
	}//end testACaseTypeFilterNarrowsTheCasesButAnEmptyOneDoesNot()

	public function testNumericIdsSearchThroughTheSelfBlock(): void {
		$service = $this->objectService();

		$this->loader(service: $service, config: ['register' => '5', 'case_schema' => '12'])->loadCases(caseTypeFilter: 'ct-1');

		self::assertSame([['numeric', [['_limit' => 2000, 'caseType' => 'ct-1', '@self' => ['register' => 5, 'schema' => 12]]]]], $service->calls);
	}//end testNumericIdsSearchThroughTheSelfBlock()

	public function testNothingIsLoadedWithoutTheServiceOrConfiguration(): void {
		$none = $this->loader(service: null, config: self::slugs());
		self::assertSame([], $none->loadCases(caseTypeFilter: null));
		self::assertSame([], $none->loadCaseTypes());
		self::assertSame([], $none->loadStatusTypes());
		self::assertSame([], $none->loadStatusRecords());

		$service = $this->objectService();
		$unconfigured = $this->loader(service: $service, config: ['register' => 'dossiq']);
		self::assertSame([], $unconfigured->loadCases(caseTypeFilter: null));
		self::assertSame([], $unconfigured->loadCaseTypes());
		self::assertSame([], $unconfigured->loadStatusTypes());
		self::assertSame([], $unconfigured->loadStatusRecords());
		self::assertSame([], $this->loader(service: $service, config: ['case_schema' => 'case'])->loadCases(caseTypeFilter: null));
		self::assertSame([], $service->calls);
	}//end testNothingIsLoadedWithoutTheServiceOrConfiguration()

	public function testIndexByIdSkipsRowsWithoutAnId(): void {
		$loader = $this->loader(service: null, config: []);

		self::assertSame(
			['a' => ['id' => 'a', 'n' => 2], '7' => ['id' => 7]],
			$loader->indexById(rows: [['id' => 'a', 'n' => 1], ['name' => 'no id'], ['id' => 7], ['id' => 'a', 'n' => 2]])
		);
	}//end testIndexByIdSkipsRowsWithoutAnId()

	public function testIndexByIdAndSlugReachesARowByEither(): void {
		$loader = $this->loader(service: null, config: []);
		$row = ['id' => 'st-1', 'slug' => 'ontvangen'];

		self::assertSame(
			['st-1' => $row, 'ontvangen' => $row, 'besluit' => ['slug' => 'besluit']],
			$loader->indexByIdAndSlug(rows: [$row, ['slug' => 'besluit'], ['title' => 'neither']])
		);
	}//end testIndexByIdAndSlugReachesARowByEither()
}//end class
