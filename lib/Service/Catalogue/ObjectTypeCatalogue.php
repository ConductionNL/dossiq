<?php

/**
 * Dossiq Object Type Catalogue
 *
 * The object types the catalogue page lists: the distinct `objectType`
 * values of the objects cases actually link (`caseObject`), each with the
 * number of cases that link one and the number of distinct case types those
 * cases are of. Schemas are not listed: a register holds case, task and the
 * other record schemas, which are not object types.
 *
 * A case type does not declare its object types, so "used in N case types" is
 * read off the cases that link the type.
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
use OCP\IL10N;
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
	 * @param IL10N                    $l10n            Words the schema line is written in.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ObjectTypeSchemaResolver $schemas,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The object types cases link, most used first, then by name.
	 *
	 * Returns an empty list when the register or the case-object schema is not
	 * configured, or when no case links an object. A read OpenRegister refuses
	 * throws, so the caller can tell a failed read from an empty catalogue.
	 *
	 * @return array<int, array{
	 *     id: string, title: string, cases: int, schema: string, schemaLine: string,
	 *     properties: int|null, objects: int|null, views: array<int, string>
	 * }> The rows.
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

		$caseTypeOf = $this->caseTypesOf(objectService: $objectService, register: $register, caseIds: $this->caseIdsIn(casesByType: $casesByType));

		$rows = [];
		foreach ($casesByType as $type => $cases) {
			$row = $this->rowFor(type: (string)$type, cases: count($cases), objectUrl: ($urlByType[$type] ?? ''));
			$row['caseTypes'] = $this->caseTypeCount(cases: array_keys($cases), caseTypeOf: $caseTypeOf);
			$rows[] = $row;
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
	 * OpenRegister schema, that schema's title, property count, views and
	 * object count, with the line under the type ("Bomenregister · 5
	 * objecten"). A free-text type leaves the schema columns empty.
	 *
	 * @param string $type      The object type.
	 * @param int    $cases     The cases that link one.
	 * @param string $objectUrl A link URL naming the schema, or ''.
	 *
	 * @return array{
	 *     id: string, title: string, cases: int, schema: string, schemaLine: string,
	 *     properties: int|null, objects: int|null, views: array<int, string>, schemaId: string
	 * } The row.
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
			'schemaLine' => $this->schemaLine(title: ($schema['title'] ?? ''), objects: ($schema['objects'] ?? null)),
			'properties' => ($schema['properties'] ?? null),
			'objects'    => ($schema['objects'] ?? null),
			'views'      => ($schema['views'] ?? []),
			'schemaId'   => ($schema['id'] ?? ''),
		];
	}//end rowFor()

	/**
	 * The line under an object type: the schema title and, when it could be
	 * counted, how many objects it holds ("Bomenregister · 5 objecten").
	 *
	 * @param string   $title   The schema title, '' for a free-text type.
	 * @param int|null $objects The object count, null when unknown.
	 *
	 * @return string The line, '' without a schema.
	 *
	 * @spec openspec/specs/catalogue/spec.md
	 */
	private function schemaLine(string $title, ?int $objects): string {
		if ($title === '' || $objects === null) {
			return $title;
		}

		return $title.' · '.$this->l10n->n('%n object', '%n objects', $objects);
	}//end schemaLine()

	/**
	 * Every case id that links an object, once.
	 *
	 * @param array<string, array<string, bool>> $casesByType The cases per type.
	 *
	 * @return array<int, string> The case ids.
	 *
	 * @spec openspec/specs/catalogue/spec.md
	 */
	private function caseIdsIn(array $casesByType): array {
		$ids = [];
		foreach ($casesByType as $cases) {
			foreach (array_keys($cases) as $case) {
				$ids[(string)$case] = true;
			}
		}

		return array_keys($ids);
	}//end caseIdsIn()

	/**
	 * The case type of each case, read in batches; null when the case schema
	 * is not configured, so the count stays unknown rather than zero.
	 *
	 * @param object             $objectService The OpenRegister ObjectService.
	 * @param string             $register      The register id.
	 * @param array<int, string> $caseIds       The cases to read.
	 *
	 * @return array<string, string>|null The case type id per case id.
	 *
	 * @spec openspec/specs/catalogue/spec.md
	 */
	private function caseTypesOf(object $objectService, string $register, array $caseIds): ?array {
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($schema === '') {
			return null;
		}

		$caseTypeOf = [];
		foreach (array_chunk($caseIds, self::PAGE_SIZE) as $chunk) {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_ids' => $chunk, '_limit' => count($chunk)]
			);
			foreach ($cases as $case) {
				$id = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
				$type = ($case['caseType'] ?? '');
				if (is_array($type) === true) {
					$type = ($type['id'] ?? ($type['uuid'] ?? ''));
				}

				if ($id !== '' && is_string($type) === true && $type !== '') {
					$caseTypeOf[$id] = $type;
				}
			}
		}

		return $caseTypeOf;
	}//end caseTypesOf()

	/**
	 * How many distinct case types the given cases are of.
	 *
	 * @param array<int, int|string>     $cases      The case ids.
	 * @param array<string, string>|null $caseTypeOf The case type per case, null when unknown.
	 *
	 * @return int|null The count, null when the case types could not be read.
	 *
	 * @spec openspec/specs/catalogue/spec.md
	 */
	private function caseTypeCount(array $cases, ?array $caseTypeOf): ?int {
		if ($caseTypeOf === null) {
			return null;
		}

		$types = [];
		foreach ($cases as $case) {
			if (isset($caseTypeOf[(string)$case]) === true) {
				$types[$caseTypeOf[(string)$case]] = true;
			}
		}

		return count($types);
	}//end caseTypeCount()

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
