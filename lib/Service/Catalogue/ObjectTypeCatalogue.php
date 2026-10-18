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
	 * @param SettingsService $settingsService Config and OpenRegister's ObjectService.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The object types cases link, most used first, then by name.
	 *
	 * Returns an empty list when the register or the case-object schema is not
	 * configured, or when no case links an object. A read OpenRegister refuses
	 * throws, so the caller can tell a failed read from an empty catalogue.
	 *
	 * @return array<int, array{id: string, title: string, cases: int}> The rows.
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
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$links = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::PAGE_SIZE, '_offset' => (($page - 1) * self::PAGE_SIZE)]
			);
			$casesByType = $this->collect(casesByType: $casesByType, links: $links);
			if (count($links) < self::PAGE_SIZE) {
				break;
			}
		}

		$rows = [];
		foreach ($casesByType as $type => $cases) {
			$rows[] = ['id' => (string)$type, 'title' => (string)$type, 'cases' => count($cases)];
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
