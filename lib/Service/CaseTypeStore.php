<?php

/**
 * Dossiq Case Type Store.
 *
 * Every OpenRegister read the case-type resolver performs.
 *
 * Split out of `CaseTypeResolver` for the reason `CaseStatusStore` was split
 * out of `StatusTransitionService`: the resolver keeps only the decision logic
 * — which ancestor a row came from, which declaration wins, whether a parent
 * closes a loop — while the mechanics of reaching the object store live here.
 * Resolving the bridge, reading the register and schema ids out of
 * configuration, and coercing whichever shape the store answered in are all
 * one concern, and they are not the concern the resolver is read for.
 *
 * Reads only. Nothing here writes, so a resolver bug cannot corrupt a case type.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use Throwable;

/**
 * OpenRegister reads for the case-type resolver.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeStore {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Read one case type.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, mixed> The row, or an empty array when unreadable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function readCaseType(string $caseTypeId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$found = $objectService->find($caseTypeId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRow(value: $found);
	}//end readCaseType()

	/**
	 * Rows of one schema belonging to one case type.
	 *
	 * @param string $schemaKey  The settings key naming the schema.
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function rowsOfType(string $schemaKey, string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		return $this->search(schemaKey: $schemaKey, caseTypeFilter: $caseTypeId);
	}//end rowsOfType()

	/**
	 * Rows of one schema that belong to no case type at all.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/property-definition-management/spec.md
	 */
	public function sharedRows(string $schemaKey): array {
		return $this->search(schemaKey: $schemaKey, caseTypeFilter: 'IS NULL');
	}//end sharedRows()

	/**
	 * An object's id, whichever shape the store answered in.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function rowId(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '' && isset($row['@self']) === true && is_array($row['@self']) === true) {
			$id = ($row['@self']['id'] ?? '');
		}

		return (string)$id;
	}//end rowId()

	/**
	 * The id a reference field points at.
	 *
	 * A `$ref` reaches PHP as a uuid string on a plain read and as an expanded
	 * object when the caller asked for it; reading only the string shape is how
	 * an inheritance chain silently stops one level up.
	 *
	 * @param mixed $value The reference value.
	 *
	 * @return string The id, or the empty string.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return $this->rowId(row: $value);
		}

		return trim((string)$value);
	}//end referenceId()

	/**
	 * Search one configured schema, narrowed on the caseType back-reference.
	 *
	 * Filtered SERVER-side. Fetching everything and filtering here would drop
	 * whatever the first page did not contain, which is a case type quietly
	 * missing the statuses that happened to sort last.
	 *
	 * @param string $schemaKey      The settings key naming the schema.
	 * @param string $caseTypeFilter The caseType id, or `IS NULL` for shared rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function search(string $schemaKey, string $caseTypeFilter): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$found = $objectService->searchObjects(
				[
					'@self' => ['register' => $register, 'schema' => $schema],
					'caseType' => $caseTypeFilter,
					'_limit' => 200,
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		return $this->asRows(value: $found);
	}//end search()

	/**
	 * Normalise one store answer into a plain row.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function asRow(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end asRow()

	/**
	 * Normalise a store search answer into plain rows.
	 *
	 * The store answers with either a bare list or a paged envelope; reading
	 * one shape only is how a reader finds nothing on an instance that answers
	 * the other way.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function asRows(mixed $value): array {
		if (is_array($value) === true && isset($value['results']) === true) {
			$value = $value['results'];
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			$row = $this->asRow(value: $row);
			if ($row !== []) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end asRows()
}//end class
