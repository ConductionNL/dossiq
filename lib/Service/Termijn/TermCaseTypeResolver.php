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
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\ReferenceId;
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
	 * The most case types one title search reads: a slug can name several
	 * versions of a case type, so the slug count is no bound.
	 *
	 * @var int
	 */
	private const TITLE_SEARCH_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LanguageMapText $text = new LanguageMapText(),
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
		$lookups    = $this->lookupsFor(rows: $rows);
		$classified = $this->classify(rows: $rows, lookups: $lookups);
		$titles     = $this->titlesBySlug(caseTypes: $lookups->caseTypes, slugs: $classified['slugs']);
		foreach ($classified['slugs'] as $index => $slug) {
			$classified['byRow'][$index]['title'] = ($titles[$slug] ?? '');
		}

		return ['byRow' => $classified['byRow'], 'unresolved' => $classified['unresolved']];
	}//end resolve()

	/**
	 * The cases the rows name, their case types and the rows' definitions, in three batched reads.
	 *
	 * @param array<int, array<string, mixed>> $rows Instance rows.
	 *
	 * @return TermLookups The reads.
	 */
	private function lookupsFor(array $rows): TermLookups {
		$cases     = $this->batch(configKey: 'case_schema', ids: array_map(fn (array $row): string => $this->ref(value: ($row['case'] ?? null)), $rows));
		$caseTypes = $this->batch(
			configKey: 'case_type_schema',
			ids: array_map(fn (array $case): string => $this->ref(value: ($case['caseType'] ?? null)), array_values($cases))
		);
		$definitions = $this->batch(
			configKey: 'termijn_definitie_schema',
			ids: array_map(fn (array $row): string => $this->ref(value: ($row['deadlineDefinition'] ?? null)), $rows)
		);

		return new TermLookups(cases: $cases, caseTypes: $caseTypes, definitions: $definitions);
	}//end lookupsFor()

	/**
	 * Each row's key and title, the slugs still missing a title, and the unresolved ids.
	 *
	 * @param array<int, array<string, mixed>> $rows    Instance rows.
	 * @param TermLookups                      $lookups The batched reads.
	 *
	 * @return array{byRow: array<int, array{key: string, title: string}>, unresolved: array<int, string>, slugs: array<int, string>}
	 */
	private function classify(array $rows, TermLookups $lookups): array {
		$out = ['byRow' => [], 'unresolved' => [], 'slugs' => []];
		foreach ($rows as $index => $row) {
			$resolved = $this->resolvedRow(row: $row, lookups: $lookups);
			$out['byRow'][$index] = ['key' => $resolved['key'], 'title' => $resolved['title']];
			if ($resolved['via'] === 'definition') {
				$out['slugs'][$index] = $resolved['key'];
			}

			if ($resolved['via'] === 'none') {
				$out['unresolved'][] = (string)($row['id'] ?? '');
			}
		}

		return $out;
	}//end classify()

	/**
	 * One row's key and title: through its case, else through its definition, else unresolved.
	 *
	 * @param array<string, mixed> $row     One instance row.
	 * @param TermLookups          $lookups The batched reads.
	 *
	 * @return array{key: string, title: string, via: string} The answer and the path that gave it.
	 */
	private function resolvedRow(array $row, TermLookups $lookups): array {
		$case       = ($lookups->cases[$this->ref(value: ($row['case'] ?? null))] ?? null);
		$caseTypeId = $this->ref(value: ($case['caseType'] ?? null));
		if ($caseTypeId !== '' && isset($lookups->caseTypes[$caseTypeId]) === true) {
			$caseType = $lookups->caseTypes[$caseTypeId];
			$key      = $this->caseTypeKey(caseType: $caseType);
			return ['key' => $key, 'title' => $this->text->textOf(value: ($caseType['title'] ?? null)), 'via' => 'case'];
		}

		$slug = trim((string)($lookups->definitions[$this->ref(value: ($row['deadlineDefinition'] ?? null))]['caseType'] ?? ''));
		if ($slug !== '') {
			return ['key' => $slug, 'title' => '', 'via' => 'definition'];
		}

		return ['key' => self::UNRESOLVED, 'title' => '', 'via' => 'none'];
	}//end resolvedRow()

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
	 * The title of each case type a slug names: from the case types already
	 * read, and otherwise searched for the leftover slugs only.
	 *
	 * @param array<string, array<string, mixed>> $caseTypes The case types already read.
	 * @param array<int, string>                  $slugs     The slugs that need a title.
	 *
	 * @return array<string, string> The title per slug.
	 */
	private function titlesBySlug(array $caseTypes, array $slugs): array {
		$titles = [];
		foreach ($caseTypes as $caseType) {
			$titles[$this->caseTypeKey(caseType: $caseType)] = $this->text->textOf(value: ($caseType['title'] ?? null));
		}

		$leftover = array_values(array_diff(array_unique($slugs), array_map('strval', array_keys($titles))));
		$byIdentifier = $this->titlesWhere(field: 'identifier', slugs: $leftover);
		$bySlug = $this->titlesWhere(field: 'slug', slugs: array_values(array_diff($leftover, array_keys($byIdentifier))));

		return ($titles + $byIdentifier + $bySlug);
	}//end titlesBySlug()

	/**
	 * The titles of the case types whose identifier, or metadata slug, is one of these slugs, in one search.
	 *
	 * A definition names its case type by the key {@see self::caseTypeKey()}
	 * reports it under, which is either field; OpenRegister matches a list
	 * as any of its values, but cannot match either of two fields at once.
	 *
	 * @param string             $field `identifier`, or `slug` for the metadata slug.
	 * @param array<int, string> $slugs The slugs; none searches nothing.
	 *
	 * @return array<string, string> The title per slug found.
	 *
	 * @throws \Throwable When the search itself fails.
	 */
	private function titlesWhere(string $field, array $slugs): array {
		if ($slugs === []) {
			return [];
		}

		$filter = [$field => $slugs];
		if ($field === 'slug') {
			$filter = ['@self' => ['slug' => $slugs]];
		}

		$titles = [];
		foreach ($this->search(configKey: 'case_type_schema', filters: ($filter + ['_limit' => self::TITLE_SEARCH_LIMIT])) as $caseType) {
			$slug = trim((string)($caseType[$field] ?? ''));
			if ($field === 'slug') {
				$slug = trim((string)($caseType['@self']['slug'] ?? ''));
			}

			$titles[$slug] = $this->text->textOf(value: ($caseType['title'] ?? null));
		}

		return $titles;
	}//end titlesWhere()

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
		if ($ids === []) {
			return [];
		}

		$keyed = [];
		foreach ($this->search(configKey: $configKey, filters: ['_ids' => $ids, '_limit' => count($ids)]) as $row) {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id !== '') {
				$keyed[$id] = $row;
			}
		}

		return $keyed;
	}//end batch()

	/**
	 * One search of one schema with the caller's own access, nothing when it is not configured.
	 *
	 * @param string               $configKey The schema's config key.
	 * @param array<string, mixed> $filters   The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws \Throwable When the search itself fails.
	 */
	private function search(string $configKey, array $filters): array {
		$schema = (string)$this->settingsService->getConfigValue($configKey);
		$register = (string)$this->settingsService->getConfigValue('register');
		$objectService = $this->settingsService->getObjectService();
		if ($schema === '' || $register === '' || $objectService === null) {
			return [];
		}

		// A failed read throws: answering [] would report every term as
		// unresolved and pass a wrong quarterly count off as a true one.
		return $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);
	}//end search()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function ref(mixed $value): string {
		return ReferenceId::of(value: $value);
	}//end ref()

}//end class
