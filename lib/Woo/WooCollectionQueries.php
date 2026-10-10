<?php

/**
 * Dossiq Woo collection queries
 *
 * Every search run from a Woo case, stored so someone else can run it again
 * (woo-request-corpus-collection, REQ-WRC-004). A stored query keeps its
 * source, terms, period, filters, who ran it and when, and the stable key of
 * every row any run of it returned. A re-run searches again with the caller's
 * own access and marks a row `new` when no earlier run returned it and it is
 * not already on the case.
 *
 * A re-run asks the same source the dialog asked: a person's own files
 * through their Nextcloud user folder, other cases' documents through
 * OpenRegister, and Microsoft 365 through integriq.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use RuntimeException;

/**
 * Stores and re-runs the searches of a Woo case.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
 */
class WooCollectionQueries {

	use SearchesObjects;

	/**
	 * The query's config key.
	 */
	public const QUERY_SCHEMA = 'woo_collection_query_schema';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService The register and the schemas.
	 * @param IRootFolder      $rootFolder      The caller's own files, for a files re-run.
	 * @param WooSources       $sources         integriq's search, for a Microsoft 365 re-run.
	 * @param WooCaseDocuments $caseDocuments   What is already on the case.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly WooSources $sources,
		private readonly WooCaseDocuments $caseDocuments,
	) {
	}//end __construct()

	/**
	 * Store one search.
	 *
	 * @param string               $caseId The case uuid.
	 * @param array<string, mixed> $search `{source, terms, periodFrom, periodTo, filters, resultKeys}`.
	 * @param string               $userId Who ran it.
	 *
	 * @return array<string, mixed> The query as stored.
	 *
	 * @throws WooCorpusRefused 400 for an unknown source.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function store(string $caseId, array $search, string $userId): array {
		$source = (string)($search['source'] ?? '');
		if (WooSources::isKnown(source: $source) === false) {
			throw new WooCorpusRefused(reason: 'unknown_source', status: 400);
		}

		$row = [
			'case' => $caseId,
			'source' => $source,
			'terms' => trim((string)($search['terms'] ?? '')),
			'runBy' => $userId,
			'runAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'resultKeys' => $this->keys(values: (array)($search['resultKeys'] ?? [])),
		];
		$filters = (array)($search['filters'] ?? []);
		if ($filters !== []) {
			// An empty list would be written as a JSON array, which the object-typed property refuses.
			$row['filters'] = $filters;
		}

		foreach (['periodFrom', 'periodTo'] as $key) {
			$date = trim((string)($search[$key] ?? ''));
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
				$row[$key] = $date;
			}
		}

		return $this->save(row: $row, uuid: '');
	}//end store()

	/**
	 * Every stored query of the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The queries.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function forCase(string $caseId): array {
		[$objectService, $register, $schema] = $this->target();
		$filters = ['case' => $caseId, '_limit' => 500];
		$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters);

		return array_values(array_filter($rows, static fn (array $row): bool => (string)($row['case'] ?? '') === $caseId));
	}//end forCase()

	/**
	 * Run a stored query again as the caller and mark what is new.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $queryId The stored query's uuid.
	 * @param string $userId  The caller.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, refusal: string} Each row with `new`.
	 *
	 * @throws WooCorpusRefused 404 when the query is not one of this case.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-selecting-query-is-saved-and-can-be-re-run-req-wrc-004
	 */
	public function rerun(string $caseId, string $queryId, string $userId): array {
		$query = null;
		foreach ($this->forCase(caseId: $caseId) as $row) {
			if ($this->idOf(row: $row) === $queryId) {
				$query = $row;
			}
		}

		if ($query === null) {
			throw new WooCorpusRefused(reason: 'query_not_found', status: 404);
		}

		$answer = $this->search(query: $query, userId: $userId);
		if ($answer['refusal'] !== '') {
			return $answer;
		}

		$seen = array_flip($this->keys(values: (array)($query['resultKeys'] ?? [])));
		$onCase = array_flip($this->caseDocuments->idsFor(caseId: $caseId));
		$rows = [];
		foreach ($answer['rows'] as $row) {
			$row['new'] = (isset($seen[$row['key']]) === false && isset($onCase[$row['key']]) === false);
			$rows[] = $row;
		}

		$query['resultKeys'] = $this->keys(values: array_merge(array_keys($seen), array_column($rows, 'key')));
		$this->save(row: $query, uuid: $queryId);

		return ['rows' => $rows, 'refusal' => ''];
	}//end rerun()

	/**
	 * Search the query's source again.
	 *
	 * @param array<string, mixed> $query  The stored query.
	 * @param string               $userId The caller.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, refusal: string}
	 */
	private function search(array $query, string $userId): array {
		$terms = (string)($query['terms'] ?? '');
		$from = ($query['periodFrom'] ?? null);
		$until = ($query['periodTo'] ?? null);

		return match ((string)($query['source'] ?? '')) {
			WooSources::SOURCE_FILES => ['rows' => $this->searchFiles(terms: $terms, userId: $userId), 'refusal' => ''],
			WooSources::SOURCE_CASES => ['rows' => $this->searchCases(terms: $terms), 'refusal' => ''],
			default => $this->searchMicrosoft365(terms: $terms, from: $from, until: $until, userId: $userId),
		};
	}//end search()

	/**
	 * The caller's own files whose name matches the terms.
	 *
	 * @param string $terms  The terms.
	 * @param string $userId The caller.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function searchFiles(string $terms, string $userId): array {
		$rows = [];
		foreach ($this->rootFolder->getUserFolder($userId)->search($terms) as $node) {
			if ($node instanceof File) {
				$rows[] = ['key' => (string)$node->getId(), 'name' => $node->getName(), 'location' => $node->getPath(), 'date' => '', 'snippet' => ''];
			}
		}

		return array_slice($rows, 0, WooSources::ROW_LIMIT);
	}//end searchFiles()

	/**
	 * Documents of cases whose text matches the terms.
	 *
	 * @param string $terms The terms.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function searchCases(string $terms): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$filters = ['_search' => $terms, '_limit' => WooSources::ROW_LIMIT];
		$rows = [];
		foreach ($this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: $filters) as $row) {
			$rows[] = ['key' => $this->idOf(row: $row), 'name' => (string)($row['title'] ?? ''), 'location' => '', 'date' => '', 'snippet' => ''];
		}

		return $rows;
	}//end searchCases()

	/**
	 * integriq's Microsoft 365 search.
	 *
	 * @param string     $terms  The terms.
	 * @param mixed      $from   Start of the period.
	 * @param mixed      $until  End of the period.
	 * @param string     $userId The caller.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, refusal: string}
	 */
	private function searchMicrosoft365(string $terms, mixed $from, mixed $until, string $userId): array {
		if (is_string($from) === false) {
			$from = null;
		}

		if (is_string($until) === false) {
			$until = null;
		}

		$answer = $this->sources->searchMicrosoft365(terms: $terms, from: $from, to: $until, userId: $userId);

		return ['rows' => $answer['rows'], 'refusal' => $answer['refusal']];
	}//end searchMicrosoft365()

	/**
	 * Distinct non-empty string keys.
	 *
	 * @param array<int|string, mixed> $values The values.
	 *
	 * @return array<int, string> The keys.
	 */
	private function keys(array $values): array {
		$keys = array_map('strval', array_filter($values, 'is_scalar'));

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end keys()

	/**
	 * Save a query, new or existing.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $uuid The uuid, '' for a new one.
	 *
	 * @return array<string, mixed> The row as stored.
	 */
	private function save(array $row, string $uuid): array {
		[$objectService, $register, $schema] = $this->target();
		unset($row['id'], $row['uuid'], $row['@self']);
		if ($uuid === '') {
			$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema);
		} else {
			$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema, uuid: $uuid);
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		return (array)$saved;
	}//end save()

	/**
	 * OpenRegister, the register and the query schema.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When they are not there.
	 */
	private function target(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue(self::QUERY_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			throw new RuntimeException('The Woo collection query schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end target()

	/**
	 * A row's uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
}//end class
