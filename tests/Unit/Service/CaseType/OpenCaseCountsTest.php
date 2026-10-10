<?php

/**
 * OpenCaseCounts: open cases per current case type, from one terms facet.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\OpenCaseCounts;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\CaseType\OpenCaseCounts
 */
class OpenCaseCountsTest extends TestCase {

	/**
	 * The counter over a store that answers $answer, or throws when it is null.
	 *
	 * @param array<string, mixed>|null $answer   The facet answer.
	 * @param array<string, string>     $config   The app config.
	 * @param bool                      $hasStore Whether OpenRegister is there.
	 * @param array<int, array>         $queries  Receives every query asked.
	 *
	 * @return OpenCaseCounts The counter.
	 */
	private function counter(?array $answer, array $config, bool $hasStore = true, array &$queries = []): OpenCaseCounts {
		$store = new class($answer, $queries) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $answer  The answer.
			 * @param array<int, array>         $queries The queries asked.
			 */
			public function __construct(private ?array $answer, private array &$queries) {
			}

			/**
			 * Mimic ObjectService::getFacetsForObjects().
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<string, mixed> The answer.
			 *
			 * @throws RuntimeException When the answer is null.
			 */
			public function getFacetsForObjects(array $query = []): array {
				$this->queries[] = $query;
				if ($this->answer === null) {
					throw new RuntimeException('facet store down');
				}

				return $this->answer;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($hasStore ? $store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new OpenCaseCounts(settingsService: $settings);
	}//end counter()

	/**
	 * Buckets are counted, keyed by the version in use, over open cases only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testCountsFoldIntoTheVersionInUse(): void {
		$queries = [];
		$counter = $this->counter(
			answer: [
				'facets' => [
					'caseType' => [
						'buckets' => [
							['key' => 'v1', 'results' => 3],
							['value' => 'v2', 'count' => 2],
							['key' => '', 'results' => 9],
							['results' => 4],
							['key' => 'other', 'results' => 1],
						],
					],
				],
			],
			config: ['register' => '12', 'case_schema' => '34'],
			queries: $queries
		);

		self::assertSame(['v2' => 5, 'other' => 1], $counter->byCaseType(supersededBy: ['v1' => 'v2']));
		self::assertCount(1, $queries);
		self::assertSame(['register' => 12, 'schema' => 34], $queries[0]['@self'], 'Numeric ids are passed as integers.');
		self::assertSame(['caseType' => ['type' => 'terms']], $queries[0]['_facets']);
		self::assertSame(0, $queries[0]['isFinalStatus']);
		self::assertSame(0, $queries[0]['statusHiddenInLists']);
		self::assertSame(0, $queries[0]['isDraft']);
	}//end testCountsFoldIntoTheVersionInUse()

	/**
	 * Slugs are passed as they are, and the nested data.buckets shape is read.
	 *
	 * @return void
	 */
	public function testSlugsAndNestedBuckets(): void {
		$queries = [];
		$counter = $this->counter(
			answer: ['facets' => ['caseType' => ['data' => ['buckets' => [['key' => 'a', 'results' => 2]]]]]],
			config: ['register' => 'dossiq', 'case_schema' => 'case'],
			queries: $queries
		);

		self::assertSame(['a' => 2], $counter->byCaseType(supersededBy: []));
		self::assertSame(['register' => 'dossiq', 'schema' => 'case'], $queries[0]['@self']);
	}//end testSlugsAndNestedBuckets()

	/**
	 * A cycle in supersededBy ends.
	 *
	 * @return void
	 */
	public function testACycleEnds(): void {
		$counter = $this->counter(
			answer: ['facets' => ['caseType' => ['buckets' => [['key' => 'x', 'results' => 1]]]]],
			config: ['register' => 'dossiq', 'case_schema' => 'case']
		);

		self::assertSame(['x' => 1], $counter->byCaseType(supersededBy: ['x' => 'y', 'y' => 'x']));
	}//end testACycleEnds()

	/**
	 * Unknown is null: no OpenRegister, no case schema, no caseType facet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function testUnknownIsNull(): void {
		$config = ['register' => 'dossiq', 'case_schema' => 'case'];

		self::assertNull($this->counter(answer: ['facets' => []], config: $config, hasStore: false)->byCaseType(supersededBy: []));
		self::assertNull($this->counter(answer: ['facets' => []], config: ['register' => 'dossiq'])->byCaseType(supersededBy: []));
		self::assertNull($this->counter(answer: ['facets' => []], config: $config)->byCaseType(supersededBy: []));
		self::assertNull($this->counter(answer: ['facets' => ['caseType' => 'x']], config: $config)->byCaseType(supersededBy: []));
	}//end testUnknownIsNull()

	/**
	 * A failing facet is passed on, for the controller to translate.
	 *
	 * @return void
	 */
	public function testAFailingFacetIsPassedOn(): void {
		$this->expectException(RuntimeException::class);
		$this->counter(answer: null, config: ['register' => 'dossiq', 'case_schema' => 'case'])->byCaseType(supersededBy: []);
	}//end testAFailingFacetIsPassedOn()
}//end class
