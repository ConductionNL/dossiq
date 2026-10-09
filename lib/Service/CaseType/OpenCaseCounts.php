<?php

/**
 * Open cases per case type, from one terms facet.
 *
 * "Case types in my menu" shows how many open cases each case type has
 * (REQ-CTN-006, board DqPersoonlijkeInstellingen). A user may be offered thirty
 * case types, so the numbers come from ONE aggregate query: OpenRegister's
 * terms facet on the case's `caseType`, over the open cases, under the
 * caller's own RBAC. Never a `count()` per case type.
 *
 * A case points at the case type VERSION it was opened under, and the menu
 * lists the version in use. So each bucket is added to the version its
 * `supersededBy` chain ends at.
 *
 * Unknown is not zero: when OpenRegister or the case schema is missing, or the
 * answer carries no `caseType` facet, the answer is null and the picker shows
 * no number. A facet that throws is not caught here; the controller turns it
 * into the same empty place and logs it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\SettingsService;

/**
 * Counts open cases per current case type in one facet query.
 *
 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
 */
class OpenCaseCounts {

	/**
	 * What an open case is, the same population the dashboard counts.
	 *
	 * Not at a final status, not at a status hidden from lists, not a draft:
	 * `KpiAggregationService::OPEN_WORK`. 0 and not `false`, for the reason
	 * that constant gives: a PHP bool does not compare against stored JSON.
	 *
	 * @var array<string, int>
	 */
	public const OPEN_WORK = ['isFinalStatus' => 0, 'statusHiddenInLists' => 0, 'isDraft' => 0];

	/**
	 * The longest `supersededBy` chain followed, so a cycle cannot hang a request.
	 *
	 * @var int
	 */
	private const MAX_HOPS = 50;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register, case schema and ObjectService.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Open cases per case type version in use.
	 *
	 * @param array<string, string> $supersededBy Each superseded version's uuid => the uuid that replaced it.
	 *
	 * @return array<string, int>|null Counts keyed by the uuid of the version in use, or null when unknown.
	 *
	 * @throws \Throwable When OpenRegister's facet query fails; the caller decides what the reader sees.
	 *
	 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
	 */
	public function byCaseType(array $supersededBy): ?array {
		$buckets = $this->buckets();
		if ($buckets === null) {
			return null;
		}

		$counts = [];
		foreach ($buckets as $bucket) {
			$key = ($bucket['key'] ?? ($bucket['value'] ?? null));
			if (is_string($key) === false || $key === '') {
				continue;
			}

			$current = $this->currentVersionOf(uuid: $key, supersededBy: $supersededBy);
			$counts[$current] = (($counts[$current] ?? 0) + (int)($bucket['results'] ?? ($bucket['count'] ?? 0)));
		}

		return $counts;
	}//end byCaseType()

	/**
	 * The `caseType` facet's buckets over the open cases, or null when unknown.
	 *
	 * @return array<int, array<string, mixed>>|null The buckets.
	 */
	private function buckets(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$query = self::OPEN_WORK;
		$query['@self'] = ['register' => $this->idOrSlug(value: $register), 'schema' => $this->idOrSlug(value: $schema)];
		$query['_facets'] = ['caseType' => ['type' => 'terms']];

		// No catch here: a facet that throws is the caller's to translate
		// (MenuCaseTypesController shows no numbers and logs why), and a
		// swallowing catch in lib/Service would hide it from everyone else.
		$answer = $objectService->getFacetsForObjects($query);

		$facet = ($answer['facets']['caseType'] ?? null);
		if (is_array($facet) === false) {
			return null;
		}

		return array_values((array)($facet['data']['buckets'] ?? ($facet['buckets'] ?? [])));
	}//end buckets()

	/**
	 * The version in use for a case type version, following `supersededBy`.
	 *
	 * @param string                $uuid         A case type version uuid.
	 * @param array<string, string> $supersededBy The superseded links.
	 *
	 * @return string The uuid of the version that is not superseded, or where a cycle stopped.
	 */
	private function currentVersionOf(string $uuid, array $supersededBy): string {
		$hops = 0;
		while (isset($supersededBy[$uuid]) === true && $hops < self::MAX_HOPS) {
			$uuid = $supersededBy[$uuid];
			$hops++;
		}

		return $uuid;
	}//end currentVersionOf()

	/**
	 * A configured register or schema as OpenRegister's metadata filter wants it.
	 *
	 * @param string $value The configured id or slug.
	 *
	 * @return int|string The numeric id as an int, a slug as it is.
	 */
	private function idOrSlug(string $value): int|string {
		if (ctype_digit($value) === true) {
			return (int)$value;
		}

		return $value;
	}//end idOrSlug()
}//end class
