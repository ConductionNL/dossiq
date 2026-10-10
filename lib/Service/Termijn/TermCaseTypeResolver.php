<?php

/**
 * Dossiq term case-type resolver.
 *
 * The quarterly term report groups terms by the case type they really belong
 * to (REQ-WTR-004): through the case the term names, and otherwise through its
 * definition's case type. A term neither resolves lands in `unresolved`. The
 * lookups are batched, three searches at most, never one per row.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Resolve the case type each term instance belongs to, in batched reads.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class TermCaseTypeResolver {

	use SearchesObjects;

	/**
	 * The bucket for a term whose case type resolves neither through its case
	 * nor through its definition (REQ-WTR-004).
	 *
	 * @var string
	 */
	public const UNRESOLVED = 'unresolved';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The case type each term belongs to, resolved in batched reads.
	 *
	 * Through the case the term names (`deadlineInstance.case` to
	 * `case.caseType`), and otherwise through its definition's case type. A
	 * term neither resolves is `unresolved`, and its id is listed. Three
	 * searches at most, each restricted by `_ids`, never one per row.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 *
	 * @return array{byRow: array<int, array{key: string, title: string}>, unresolved: array<int, string>}
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function resolve(array $rows): array {
		$cases = $this->batch(configKey: 'case_schema', ids: array_map(fn (array $row): string => $this->ref(value: ($row['case'] ?? null)), $rows));
		$caseTypes = $this->batch(
			configKey: 'case_type_schema',
			ids: array_map(fn (array $case): string => $this->ref(value: ($case['caseType'] ?? null)), array_values($cases))
		);
		$definitions = $this->batch(
			configKey: 'termijn_definitie_schema',
			ids: array_map(fn (array $row): string => $this->ref(value: ($row['deadlineDefinition'] ?? null)), $rows)
		);

		$titlesBySlug = [];
		foreach ($caseTypes as $caseType) {
			$titlesBySlug[$this->caseTypeKey(caseType: $caseType)] = (string)($caseType['title'] ?? '');
		}

		$byRow = [];
		$unresolved = [];
		foreach ($rows as $index => $row) {
			$case = ($cases[$this->ref(value: ($row['case'] ?? null))] ?? null);
			$caseTypeId = $this->ref(value: ($case['caseType'] ?? null));
			if ($caseTypeId !== '' && isset($caseTypes[$caseTypeId]) === true) {
				$key = $this->caseTypeKey(caseType: $caseTypes[$caseTypeId]);
				$byRow[$index] = ['key' => $key, 'title' => (string)($caseTypes[$caseTypeId]['title'] ?? '')];
				continue;
			}

			$slug = trim((string)($definitions[$this->ref(value: ($row['deadlineDefinition'] ?? null))]['caseType'] ?? ''));
			if ($slug !== '') {
				$byRow[$index] = ['key' => $slug, 'title' => ($titlesBySlug[$slug] ?? '')];
				continue;
			}

			$byRow[$index] = ['key' => self::UNRESOLVED, 'title' => ''];
			$unresolved[] = (string)($row['id'] ?? '');
		}//end foreach

		return ['byRow' => $byRow, 'unresolved' => $unresolved];
	}//end resolve()

	/**
	 * The key a case type is reported under: its identifier, then its slug, then its id.
	 *
	 * The identifier is what a term definition names as its `caseType`, so a
	 * term resolved through its case and one resolved through its definition
	 * land in the same bucket.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return string The key.
	 */
	private function caseTypeKey(array $caseType): string {
		$candidates = [
			($caseType['identifier'] ?? null),
			($caseType['@self']['slug'] ?? null),
			($caseType['slug'] ?? null),
			($caseType['id'] ?? null),
		];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return self::UNRESOLVED;
	}//end caseTypeKey()

	/**
	 * Read the rows with these ids from one schema, in one search.
	 *
	 * @param string             $configKey The schema's config key.
	 * @param array<int, string> $ids       The ids; empties and repeats are dropped.
	 *
	 * @return array<string, array<string, mixed>> The rows, keyed by id.
	 *
	 * @throws \Throwable When the search itself fails.
	 */
	private function batch(string $configKey, array $ids): array {
		$ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($configKey);
		if ($ids === [] || $objectService === null || $register === '' || $schema === '') {
			return [];
		}

		// A failed read throws: answering [] would report every term as
		// unresolved and pass a wrong quarterly count off as a true one.
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_ids' => $ids, '_limit' => count($ids)]
		);

		$keyed = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id !== '') {
				$keyed[$id] = $row;
			}
		}

		return $keyed;
	}//end batch()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function ref(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end ref()

}//end class
