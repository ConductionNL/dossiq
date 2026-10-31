<?php

/**
 * Dossiq Object Type Catalogue Test
 *
 * The catalogue lists the object types cases link through `caseObject`, with
 * the number of distinct cases per type, and nothing else.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Catalogue;

use OCA\Dossiq\Service\Catalogue\ObjectTypeCatalogue;
use OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Object types from what cases link.
 */
final class ObjectTypeCatalogueTest extends TestCase {
	/**
	 * Each object type is counted once per case that links it, most used
	 * first, and links without a type or a case are left out.
	 *
	 * @return void
	 */
	public function testCountsTheCasesPerObjectType(): void {
		$service = $this->objectService(links: [
			['case' => 'c1', 'objectType' => 'building'],
			['case' => 'c1', 'objectType' => 'building'],
			['case' => 'c2', 'objectType' => 'building'],
			['case' => 'c3', 'objectType' => 'vehicle'],
			['case' => 'c4', 'objectType' => 'Boom'],
			['case' => 'c5', 'objectType' => ' '],
			['case' => '', 'objectType' => 'vehicle'],
		]);

		$rows = $this->catalogue(register: '23', schema: '41', objectService: $service)->list();

		$this->assertSame(
			[
				$this->freeTextRow(type: 'building', cases: 2),
				$this->freeTextRow(type: 'Boom', cases: 1),
				$this->freeTextRow(type: 'vehicle', cases: 1),
			],
			$rows
		);
		$this->assertSame(['register' => 23, 'schema' => 41], $service->queries[0]['@self']);
	}

	/**
	 * A type whose link names an OpenRegister schema shows that schema; a
	 * free-text type leaves the schema columns empty.
	 *
	 * @return void
	 */
	public function testShowsTheSchemaAnObjectUrlNames(): void {
		$url     = 'https://cloud.example/index.php/apps/openregister/api/objects/7/trees/abc';
		$service = $this->objectService(links: [
			['case' => 'c1', 'objectType' => 'Boom', 'objectUrl' => 'https://elsewhere.example/tree/1'],
			['case' => 'c2', 'objectType' => 'Boom', 'objectUrl' => $url],
			['case' => 'c3', 'objectType' => 'building'],
		]);
		$schemas = [$url => ['id' => '17', 'title' => 'Bomenregister', 'properties' => 9, 'views' => ['map', 'table'], 'objects' => 5]];

		$rows = $this->catalogue(register: '23', schema: '41', objectService: $service, schemas: $schemas)->list();

		$this->assertSame(
			[
				'id'         => 'Boom',
				'title'      => 'Boom',
				'cases'      => 2,
				'schema'     => 'Bomenregister',
				'schemaLine' => 'Bomenregister · 5 objects',
				'properties' => 9,
				'objects'    => 5,
				'views'      => ['map', 'table'],
				'schemaId'   => '17',
				'caseTypes'  => null,
			],
			$rows[0]
		);
		$this->assertSame(['', null, []], [$rows[1]['schema'], $rows[1]['properties'], $rows[1]['views']]);
	}//end testShowsTheSchemaAnObjectUrlNames()

	/**
	 * A schema whose objects could not be counted shows its title alone; one
	 * object reads in the singular.
	 *
	 * @return void
	 */
	public function testTheSchemaLineLeavesOutACountItCannotRead(): void {
		$counted   = 'https://cloud.example/index.php/apps/openregister/api/objects/7/pitches/a';
		$uncounted = 'https://cloud.example/index.php/apps/openregister/api/objects/7/paths/b';
		$service   = $this->objectService(links: [
			['case' => 'c1', 'objectType' => 'Standplaats', 'objectUrl' => $counted],
			['case' => 'c2', 'objectType' => 'Wegdeel', 'objectUrl' => $uncounted],
		]);
		$schemas = [
			$counted   => ['id' => '3', 'title' => 'Markt', 'properties' => 6, 'views' => ['table'], 'objects' => 1],
			$uncounted => ['id' => '4', 'title' => 'Wegen en paden', 'properties' => 6, 'views' => ['map', 'table'], 'objects' => null],
		];

		$rows = $this->catalogue(register: '23', schema: '41', objectService: $service, schemas: $schemas)->list();

		$this->assertSame(expected: ['Markt · 1 object', 'Wegen en paden'], actual: array_column($rows, 'schemaLine'));
		$this->assertSame(expected: [1, null], actual: array_column($rows, 'objects'));
	}//end testTheSchemaLineLeavesOutACountItCannotRead()

	/**
	 * With the case schema configured, each type says in how many distinct
	 * case types it is used: the case types of the cases that link it (board
	 * DqCatalogus "Gebruikt in 3 zaaktypen").
	 *
	 * @return void
	 */
	public function testCountsTheCaseTypesOfTheLinkingCases(): void {
		$service = $this->objectService(
			links: [
				['case' => 'c1', 'objectType' => 'Boom'],
				['case' => 'c2', 'objectType' => 'Boom'],
				['case' => 'c3', 'objectType' => 'Boom'],
				['case' => 'c4', 'objectType' => 'building'],
				['case' => 'gone', 'objectType' => 'building'],
			],
			cases: [
				['id' => 'c1', 'caseType' => 'woo'],
				['id' => 'c2', 'caseType' => ['id' => 'woo']],
				['id' => 'c3', 'caseType' => 'permit'],
				['id' => 'c4', 'caseType' => ''],
			]
		);

		$rows = $this->catalogue(register: '23', schema: '41', objectService: $service, caseSchema: '42')->list();

		$this->assertSame([3, 2], [$rows[0]['cases'], $rows[0]['caseTypes']]);
		$this->assertSame(['building', 2, 0], [$rows[1]['id'], $rows[1]['cases'], $rows[1]['caseTypes']]);
		$caseQueries = array_values(array_filter($service->queries, static fn (array $query): bool => $query['@self']['schema'] === 42));
		$this->assertCount(1, $caseQueries);
		$this->assertEqualsCanonicalizing(['c1', 'c2', 'c3', 'c4', 'gone'], $caseQueries[0]['_ids']);
	}//end testCountsTheCaseTypesOfTheLinkingCases()

	/**
	 * Links are read page by page until a page comes back short.
	 *
	 * @return void
	 */
	public function testReadsEveryPage(): void {
		$links = [];
		for ($i = 0; $i < 501; $i++) {
			$links[] = ['case' => 'c' . $i, 'objectType' => 'building'];
		}

		$service = $this->objectService(links: $links);
		$rows = $this->catalogue(register: '23', schema: '41', objectService: $service)->list();

		$this->assertSame(501, $rows[0]['cases']);
		$this->assertSame([0, 500], array_column($service->queries, '_offset'));
	}

	/**
	 * No case links an object: the catalogue is empty, not filled with schemas.
	 *
	 * @return void
	 */
	public function testNoLinksListsNothing(): void {
		$this->assertSame([], $this->catalogue(register: '23', schema: '41', objectService: $this->objectService(links: []))->list());
	}

	/**
	 * Without a configured case-object schema nothing is read.
	 *
	 * @return void
	 */
	public function testUnconfiguredListsNothing(): void {
		$service = $this->objectService(links: [['case' => 'c1', 'objectType' => 'building']]);

		$this->assertSame([], $this->catalogue(register: '23', schema: '', objectService: $service)->list());
		$this->assertSame([], $service->queries);
	}

	/**
	 * Without OpenRegister the read fails loudly, so the endpoint answers 503.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->catalogue(register: '23', schema: '41', objectService: null)->list();
	}

	/**
	 * The row of a free-text object type: no schema, so every schema column empty.
	 *
	 * @param string $type  The object type.
	 * @param int    $cases The cases that link one.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function freeTextRow(string $type, int $cases): array {
		return [
			'id'         => $type,
			'title'      => $type,
			'cases'      => $cases,
			'schema'     => '',
			'schemaLine' => '',
			'properties' => null,
			'objects'    => null,
			'views'      => [],
			'schemaId'   => '',
			'caseTypes'  => null,
		];
	}//end freeTextRow()

	/**
	 * Build the catalogue over a fake configuration.
	 *
	 * @param string      $register      The register id.
	 * @param string      $schema        The case-object schema id.
	 * @param string      $caseSchema    The case schema id, '' when not configured.
	 * @param object|null $objectService The fake ObjectService, null when absent.
	 * @param array<string, array{title: string, properties: int, views: array<int, string>}> $schemas What the resolver answers, by URL.
	 *
	 * @return ObjectTypeCatalogue The catalogue under test.
	 */
	private function catalogue(string $register, string $schema, ?object $objectService, array $schemas = [], string $caseSchema = ''): ObjectTypeCatalogue {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getConfigValue', 'getObjectService'])
			->getMock();
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => $register,
				'case_object_schema' => $schema,
				'case_schema' => $caseSchema,
				default => $default,
			}
		);
		$settings->method('getObjectService')->willReturn($objectService);

		$resolver = $this->createMock(ObjectTypeSchemaResolver::class);
		$resolver->method('describe')->willReturnCallback(static fn (string $objectUrl): ?array => ($schemas[$objectUrl] ?? null));
		$resolver->method('namesSchema')->willReturnCallback(static fn (string $objectUrl): bool => ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: $objectUrl) !== '');

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count): string {
				$text = $plural;
				if ($count === 1) {
					$text = $singular;
				}

				return str_replace('%n', (string)$count, $text);
			}
		);

		return new ObjectTypeCatalogue(settingsService: $settings, schemas: $resolver, l10n: $l10n);
	}

	/**
	 * A fake ObjectService that pages through the given links.
	 *
	 * @param array<int, array<string, string>> $links The case-object links.
	 * @param array<int, array<string, mixed>>  $cases The cases, answered to an `_ids` read.
	 *
	 * @return object The fake, with the queries it was asked in `queries`.
	 */
	private function objectService(array $links, array $cases = []): object {
		return new class($links, $cases) {
			/**
			 * The queries asked, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @param array<int, array<string, string>> $links The links.
			 * @param array<int, array<string, mixed>>  $cases The cases.
			 */
			public function __construct(private array $links, private array $cases) {
			}

			/**
			 * Mirrors ObjectService::searchObjects() with `_limit` and `_offset`.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, string>> The page.
			 */
			public function searchObjects(array $query): array {
				$this->queries[] = $query;
				if (isset($query['_ids']) === true) {
					return array_values(array_filter($this->cases, static fn (array $case): bool => in_array($case['id'], $query['_ids'], true)));
				}

				return array_slice($this->links, (int)$query['_offset'], (int)$query['_limit']);
			}
		};
	}
}
