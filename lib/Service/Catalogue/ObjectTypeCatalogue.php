<?php

/**
 * Dossiq Object Type Catalogue
 *
 * The object types the catalogue page lists: the distinct `objectType`
 * values of the objects cases actually link (`caseObject`), each with the
 * number of cases that link one. Schemas are not listed: a register holds
 * case, task and the other record schemas, which are not object types.
 *
 * A case type does not reference an object type, so no "used in N case types"
 * count is derived here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Catalogue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Catalogue;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Lists the object types cases link, with the number of cases per type.
 *
 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
 */
class ObjectTypeCatalogue {

	use SearchesObjects;

	/**
	 * Case-object links read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Pages read at most, so a runaway store cannot hold the request.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 100;

	/**
	 * Constructor.
	 *
	 * @param SettingsService          $settingsService Config and OpenRegister's ObjectService.
	 * @param ObjectTypeSchemaResolver $schemas         The schema a linked object's URL names.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ObjectTypeSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * The object types cases link, most used first, then by name.
	 *
	 * Returns an empty list when the register or the case-object schema is not
	 * configured, or when no case links an object. A read OpenRegister refuses
	 * throws, so the caller can tell a failed read from an empty catalogue.
	 *
	 * @return array<int, array{id: string, title: string, cases: int, schema: string, properties: int|null, views: array<int, string>}> The rows.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/catalogue-object-types/specs/catalogue/spec.md
	 */
	public function list(): array {
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_object_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$casesByType = [];
		$urlByType   = [];
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$links = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE, '_offset' => (($page - 1) * self::PAGE_SIZE)]
			);
			$casesByType = $this->collect(casesByType: $casesByType, links: $links);
			$urlByType   = $this->collectUrls(urlByType: $urlByType, links: $links);
			if (count($links) < self::PAGE_SIZE) {
				break;
			}
		}

		$rows = [];
		foreach ($casesByType as $type => $cases) {
			$rows[] = $this->rowFor(type: (string)$type, cases: count($cases), objectUrl: ($urlByType[$type] ?? ''));
		}

		usort($rows, static fn (array $left, array $right): int => self::compareRows(left: $left, right: $right));

		return $rows;
	}//end list()

	/**
	 * Add one page of case-object links to the cases seen per object type.
	 * A link without a type or a case is left out.
	 *
	 * @param array<string, array<string, true>> $casesByType The cases seen so far, by type.
	 * @param array<int, array<string, mixed>>   $links       One page of links.
	 *
	 * @return array<string, array<string, true>> The cases seen, by type.
	 */
	private function collect(array $casesByType, array $links): array {
		foreach ($links as $link) {
			$type = trim((string)($link['objectType'] ?? ''));
			$case = (string)($link['case'] ?? '');
			if ($type !== '' && $case !== '') {
				$casesByType[$type][$case] = true;
			}
		}

		return $casesByType;
	}//end collect()

	/**
	 * Remember, per object type, the first link whose URL names an OpenRegister
	 * schema, so the row can show that schema.
	 *
	 * @param array<string, string>            $urlByType The URL seen so far, by type.
	 * @param array<int, array<string, mixed>> $links     One page of links.
	 *
	 * @return array<string, string> The URL, by type.
	 */
	private function collectUrls(array $urlByType, array $links): array {
		foreach ($links as $link) {
			$type = trim((string)($link['objectType'] ?? ''));
			$url  = (string)($link['objectUrl'] ?? '');
			if ($type === '' || isset($urlByType[$type]) === true) {
				continue;
			}

			if ($this->schemas->namesSchema(objectUrl: $url) === true) {
				$urlByType[$type] = $url;
			}
		}

		return $urlByType;
	}//end collectUrls()

	/**
	 * One catalogue row: the type, its case count and, when a link names an
	 * OpenRegister schema, that schema's title, property count and views. A
	 * free-text type leaves the schema columns empty.
	 *
	 * @param string $type      The object type.
	 * @param int    $cases     The cases that link one.
	 * @param string $objectUrl A link URL naming the schema, or ''.
	 *
	 * @return array{id: string, title: string, cases: int, schema: string, properties: int|null, views: array<int, string>} The row.
	 */
	private function rowFor(string $type, int $cases, string $objectUrl): array {
		$schema = null;
		if ($objectUrl !== '') {
			$schema = $this->schemas->describe(objectUrl: $objectUrl);
		}

		return [
			'id'         => $type,
			'title'      => $type,
			'cases'      => $cases,
			'schema'     => ($schema['title'] ?? ''),
			'properties' => ($schema['properties'] ?? null),
			'views'      => ($schema['views'] ?? []),
		];
	}//end rowFor()

	/**
	 * Order rows by the number of cases, most first, then by name.
	 *
	 * @param array{id: string, title: string, cases: int} $left  One row.
	 * @param array{id: string, title: string, cases: int} $right The other row.
	 *
	 * @return int The comparison.
	 */
	private static function compareRows(array $left, array $right): int {
		if ($left['cases'] !== $right['cases']) {
			return ($right['cases'] <=> $left['cases']);
		}

		return strcasecmp($left['title'], $right['title']);
	}//end compareRows()
}//end class
