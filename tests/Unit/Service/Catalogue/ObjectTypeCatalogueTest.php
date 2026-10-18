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
use OCA\Dossiq\Service\SettingsService;
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
				['id' => 'building', 'title' => 'building', 'cases' => 2],
				['id' => 'Boom', 'title' => 'Boom', 'cases' => 1],
				['id' => 'vehicle', 'title' => 'vehicle', 'cases' => 1],
			],
			$rows
		);
		$this->assertSame(['register' => 23, 'schema' => 41], $service->queries[0]['@self']);
	}

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
	 * Build the catalogue over a fake configuration.
	 *
	 * @param string      $register      The register id.
	 * @param string      $schema        The case-object schema id.
	 * @param object|null $objectService The fake ObjectService, null when absent.
	 *
	 * @return ObjectTypeCatalogue The catalogue under test.
	 */
	private function catalogue(string $register, string $schema, ?object $objectService): ObjectTypeCatalogue {
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getConfigValue', 'getObjectService'])
			->getMock();
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => $register,
				'case_object_schema' => $schema,
				default => $default,
			}
		);
		$settings->method('getObjectService')->willReturn($objectService);

		return new ObjectTypeCatalogue(settingsService: $settings);
	}

	/**
	 * A fake ObjectService that pages through the given links.
	 *
	 * @param array<int, array<string, string>> $links The case-object links.
	 *
	 * @return object The fake, with the queries it was asked in `queries`.
	 */
	private function objectService(array $links): object {
		return new class($links) {
			/**
			 * The queries asked, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $queries = [];

			/**
			 * @param array<int, array<string, string>> $links The links.
			 */
			public function __construct(private array $links) {
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

				return array_slice($this->links, (int)$query['_offset'], (int)$query['_limit']);
			}
		};
	}
}
