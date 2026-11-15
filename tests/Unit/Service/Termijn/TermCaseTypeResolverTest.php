<?php

/**
 * Which case type a term instance is reported under.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Service\Termijn\TermLookups
 * @uses \OCA\Dossiq\Service\Support\ReferenceId
 */
class TermCaseTypeResolverTest extends TestCase {

	/**
	 * The store, counting the searches the resolver makes.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * Seed one Woo case type, one case of it and a definition naming it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class extends FakeTermijnStore {
			/**
			 * Searches made.
			 *
			 * @var int
			 */
			public int $searches = 0;

			/**
			 * Count, then search.
			 *
			 * @param string               $registerSlug Register slug.
			 * @param string               $schemaSlug   Schema slug.
			 * @param array<string, mixed> $filters      Filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				$this->searches++;
				return parent::searchObjectsBySlug(registerSlug: $registerSlug, schemaSlug: $schemaSlug, filters: $filters);
			}
		};

		$this->objects->seed('caseType', ['id' => 'ct-woo', 'identifier' => 'woo-verzoek', 'title' => 'Woo-verzoek']);
		$this->objects->seed('caseType', ['id' => 'ct-slug', '@self' => ['slug' => 'melding'], 'title' => 'Melding']);
		$this->objects->seed('case', ['id' => 'case-1', 'caseType' => 'ct-woo']);
		$this->objects->seed('case', ['id' => 'case-2', 'caseType' => ['id' => 'ct-woo']]);
		$this->objects->seed('case', ['id' => 'case-3', 'caseType' => 'ct-slug']);
		$this->objects->seed('deadlineDefinition', ['id' => 'td-woo', 'caseType' => 'woo-verzoek']);
	}//end setUp()

	/**
	 * Seed case types that no case in the report reaches, and definitions naming them.
	 *
	 * One is named by its identifier, one by its metadata slug only, and one
	 * definition names a case type the register does not hold.
	 *
	 * @return void
	 */
	private function seedTypesReachedOnlyByDefinition(): void {
		$this->objects->seed('caseType', ['id' => 'ct-sloop', 'identifier' => 'sloopmelding', 'title' => 'Sloopmelding']);
		$this->objects->seed('caseType', [
			'id' => 'ct-klein',
			'@self' => ['slug' => 'omgevingsvergunning-kleinbouw'],
			'title' => ['nl' => 'Omgevingsvergunning kleine bouwactiviteit'],
		]);
		$this->objects->seed('deadlineDefinition', ['id' => 'td-sloop', 'caseType' => 'sloopmelding']);
		$this->objects->seed('deadlineDefinition', ['id' => 'td-klein', 'caseType' => 'omgevingsvergunning-kleinbouw']);
		$this->objects->seed('deadlineDefinition', ['id' => 'td-gone', 'caseType' => 'nergens']);
	}//end seedTypesReachedOnlyByDefinition()

	/**
	 * A resolver over the store, with every schema configured unless named.
	 *
	 * @param array<int, string> $unconfigured Config keys that answer empty.
	 * @param object|null        $store        The object service, the seeded store when null.
	 *
	 * @return TermCaseTypeResolver
	 */
	private function resolver(array $unconfigured = [], ?object $store = null): TermCaseTypeResolver {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($store ?? $this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($unconfigured): string {
				if (in_array($key, $unconfigured, true) === true) {
					return '';
				}

				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					'termijn_definitie_schema' => 'deadlineDefinition',
					default => '',
				};
			},
		);

		return new TermCaseTypeResolver(settingsService: $settings);
	}//end resolver()

	/**
	 * Through the case, through the definition, and otherwise unresolved and named.
	 *
	 * @return void
	 */
	public function testEachTermResolvesThroughItsCaseThenItsDefinition(): void {
		$result = $this->resolver()->resolve(rows: [
			['id' => 'ti-1', 'case' => 'case-1'],
			['id' => 'ti-2', 'case' => ['id' => 'case-2']],
			['id' => 'ti-3', 'case' => 'case-gone', 'deadlineDefinition' => 'td-woo'],
			['id' => 'ti-4', 'case' => 'case-3'],
			['id' => 'ti-5', 'case' => 'case-gone'],
		]);

		self::assertSame(expected: ['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], actual: $result['byRow'][0]);
		self::assertSame(expected: ['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], actual: $result['byRow'][1]);
		self::assertSame(expected: 'woo-verzoek', actual: $result['byRow'][2]['key']);
		self::assertSame(expected: 'melding', actual: $result['byRow'][3]['key']);
		self::assertSame(expected: TermCaseTypeResolver::UNRESOLVED, actual: $result['byRow'][4]['key']);
		self::assertSame(expected: ['ti-5'], actual: $result['unresolved']);
	}//end testEachTermResolvesThroughItsCaseThenItsDefinition()

	/**
	 * The whole answer, keyed and ordered as the rows were, for every path.
	 *
	 * @return void
	 */
	public function testTheWholeAnswerForEveryPath(): void {
		$this->seedTypesReachedOnlyByDefinition();
		$this->objects->seed('caseType', ['id' => 'ct-untitled', 'identifier' => 'naamloos']);
		$this->objects->seed('case', ['id' => 'case-9', 'caseType' => 'ct-untitled']);

		$result = $this->resolver()->resolve(rows: [
			7 => ['id' => 'ti-7', 'case' => 'case-9', 'deadlineDefinition' => 'td-sloop'],
			2 => ['id' => 'ti-2', 'case' => 'case-gone', 'deadlineDefinition' => 'td-klein'],
			4 => ['id' => 'ti-4', 'deadlineDefinition' => 'td-gone'],
			0 => ['case' => 'case-gone'],
			3 => ['id' => 'ti-3', 'case' => 'case-3', 'deadlineDefinition' => 'td-sloop'],
			5 => ['id' => 'ti-5', 'deadlineDefinition' => 'td-woo'],
		]);

		self::assertSame(
			expected: [
				'byRow' => [
					7 => ['key' => 'naamloos', 'title' => ''],
					2 => ['key' => 'omgevingsvergunning-kleinbouw', 'title' => 'Omgevingsvergunning kleine bouwactiviteit'],
					4 => ['key' => 'nergens', 'title' => ''],
					0 => ['key' => TermCaseTypeResolver::UNRESOLVED, 'title' => ''],
					3 => ['key' => 'melding', 'title' => 'Melding'],
					5 => ['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'],
				],
				'unresolved' => [''],
			],
			actual: $result
		);
	}//end testTheWholeAnswerForEveryPath()

	/**
	 * Three batched searches for any number of rows, never one per row.
	 *
	 * @return void
	 */
	public function testTheLookupIsBatched(): void {
		$rows = [];
		for ($i = 0; $i < 20; $i++) {
			$rows[] = ['id' => 'ti-' . $i, 'case' => 'case-1', 'deadlineDefinition' => 'td-woo'];
		}

		$this->resolver()->resolve(rows: $rows);

		self::assertSame(expected: 3, actual: $this->objects->searches);
	}//end testTheLookupIsBatched()

	/**
	 * A term resolved through its definition carries the title of the case type it names, by identifier or by slug,
	 * also when no case in the report reaches that case type.
	 *
	 * @return void
	 */
	public function testATermResolvedThroughItsDefinitionCarriesItsCaseTypesTitle(): void {
		$this->seedTypesReachedOnlyByDefinition();

		$result = $this->resolver()->resolve(rows: [
			['id' => 'ti-1', 'case' => 'case-1'],
			['id' => 'ti-2', 'deadlineDefinition' => 'td-sloop'],
			['id' => 'ti-3', 'deadlineDefinition' => 'td-klein'],
			['id' => 'ti-4', 'deadlineDefinition' => 'td-gone'],
			['id' => 'ti-5', 'deadlineDefinition' => 'td-woo'],
		]);

		self::assertSame(
			expected: [
				['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'],
				['key' => 'sloopmelding', 'title' => 'Sloopmelding'],
				['key' => 'omgevingsvergunning-kleinbouw', 'title' => 'Omgevingsvergunning kleine bouwactiviteit'],
				['key' => 'nergens', 'title' => ''],
				['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'],
			],
			actual: $result['byRow']
		);
		self::assertSame(expected: [], actual: $result['unresolved']);
	}//end testATermResolvedThroughItsDefinitionCarriesItsCaseTypesTitle()

	/**
	 * The leftover titles are read in batched searches, by identifier and then by slug, never one per row.
	 *
	 * @return void
	 */
	public function testTheLeftoverTitlesAreReadInBatchedSearches(): void {
		$this->seedTypesReachedOnlyByDefinition();
		$rows = [];
		for ($i = 0; $i < 20; $i++) {
			$rows[] = ['id' => 'ti-' . $i, 'deadlineDefinition' => ['td-sloop', 'td-klein', 'td-gone'][$i % 3]];
			if ($i % 2 === 0) {
				$rows[$i] = ['id' => 'ti-' . $i, 'case' => 'case-1'];
			}
		}

		$result = $this->resolver()->resolve(rows: $rows);

		self::assertSame(expected: 'Omgevingsvergunning kleine bouwactiviteit', actual: $result['byRow'][1]['title']);
		self::assertSame(expected: 5, actual: $this->objects->searches);
	}//end testTheLeftoverTitlesAreReadInBatchedSearches()

	/**
	 * A title already read through a case is not searched again, and when every
	 * other slug is an identifier the slug search is skipped.
	 *
	 * @return void
	 */
	public function testOnlyTheMissingTitlesAreSearched(): void {
		$this->seedTypesReachedOnlyByDefinition();

		$result = $this->resolver()->resolve(rows: [
			['id' => 'ti-1', 'case' => 'case-1', 'deadlineDefinition' => 'td-klein'],
			['id' => 'ti-2', 'deadlineDefinition' => 'td-woo'],
			['id' => 'ti-3', 'deadlineDefinition' => 'td-sloop'],
		]);

		self::assertSame(expected: ['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], actual: $result['byRow'][1]);
		self::assertSame(expected: ['key' => 'sloopmelding', 'title' => 'Sloopmelding'], actual: $result['byRow'][2]);
		self::assertSame(expected: 4, actual: $this->objects->searches);
	}//end testOnlyTheMissingTitlesAreSearched()

	/**
	 * A slug whose title a case already gave needs no title search.
	 *
	 * @return void
	 */
	public function testNoTitleSearchWhenACaseGaveEveryTitle(): void {
		$result = $this->resolver()->resolve(rows: [['id' => 'ti-1', 'case' => 'case-1'], ['id' => 'ti-2', 'deadlineDefinition' => 'td-woo']]);

		self::assertSame(expected: ['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], actual: $result['byRow'][1]);
		self::assertSame(expected: 3, actual: $this->objects->searches);
	}//end testNoTitleSearchWhenACaseGaveEveryTitle()

	/**
	 * An unconfigured schema reads nothing, so the term is unresolved and named.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaLeavesTheTermUnresolved(): void {
		$result = $this->resolver(unconfigured: ['case_schema', 'termijn_definitie_schema'])
			->resolve(rows: [['id' => 'ti-1', 'case' => 'case-1']]);

		self::assertSame(expected: TermCaseTypeResolver::UNRESOLVED, actual: $result['byRow'][0]['key']);
		self::assertSame(expected: ['ti-1'], actual: $result['unresolved']);
		self::assertSame(expected: 0, actual: $this->objects->searches);
	}//end testAnUnconfiguredSchemaLeavesTheTermUnresolved()

	/**
	 * A failed read throws instead of reporting every term as unresolved.
	 *
	 * @return void
	 */
	public function testAFailedReadThrows(): void {
		$failing = new class {
			/**
			 * Always fails.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(): array {
				throw new RuntimeException('store down');
			}
		};

		$this->expectException(exception: RuntimeException::class);
		$this->resolver(store: $failing)->resolve(rows: [['id' => 'ti-1', 'case' => 'case-1']]);
	}//end testAFailedReadThrows()
}//end class
