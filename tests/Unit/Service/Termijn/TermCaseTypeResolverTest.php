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
				return parent::searchObjectsBySlug($registerSlug, $schemaSlug, $filters);
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
	 * A resolver over the store, with every schema configured unless named.
	 *
	 * @param array<int, string> $unconfigured Config keys that answer empty.
	 * @param object|null        $store        The object service, the seeded store when null.
	 *
	 * @return TermCaseTypeResolver
	 */
	private function resolver(array $unconfigured = [], ?object $store = null): TermCaseTypeResolver {
		$settings = $this->createMock(SettingsService::class);
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

		self::assertSame(['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], $result['byRow'][0]);
		self::assertSame(['key' => 'woo-verzoek', 'title' => 'Woo-verzoek'], $result['byRow'][1]);
		self::assertSame('woo-verzoek', $result['byRow'][2]['key']);
		self::assertSame('melding', $result['byRow'][3]['key']);
		self::assertSame(TermCaseTypeResolver::UNRESOLVED, $result['byRow'][4]['key']);
		self::assertSame(['ti-5'], $result['unresolved']);
	}//end testEachTermResolvesThroughItsCaseThenItsDefinition()

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

		self::assertSame(3, $this->objects->searches);
	}//end testTheLookupIsBatched()

	/**
	 * An unconfigured schema reads nothing, so the term is unresolved and named.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaLeavesTheTermUnresolved(): void {
		$result = $this->resolver(unconfigured: ['case_schema', 'termijn_definitie_schema'])
			->resolve(rows: [['id' => 'ti-1', 'case' => 'case-1']]);

		self::assertSame(TermCaseTypeResolver::UNRESOLVED, $result['byRow'][0]['key']);
		self::assertSame(['ti-1'], $result['unresolved']);
		self::assertSame(0, $this->objects->searches);
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

		$this->expectException(RuntimeException::class);
		$this->resolver(store: $failing)->resolve(rows: [['id' => 'ti-1', 'case' => 'case-1']]);
	}//end testAFailedReadThrows()
}//end class
