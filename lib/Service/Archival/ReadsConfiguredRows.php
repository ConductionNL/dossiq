<?php

/**
 * The register plumbing the two archival classes share.
 *
 * Both `ArchivalNominationDeriver` and `ArchivalBaseDateResolver` read rows
 * out of the same OpenRegister register, addressed by the same app-config
 * keys the ZGW mappings are seeded from (`LoadDefaultZgwMappings` reads
 * `case_schema`, `result_type_schema` and the rest). Keeping that in one
 * trait is what makes "both paths read the same rows" a fact rather than a
 * claim two constructors happen to agree on.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Archival;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Reads configured-schema rows, and the small coercions that go with them.
 *
 * @spec exclude infrastructure plumbing with no requirement of its own; it is
 *   exercised through the archival derivation that calls it
 */
trait ReadsConfiguredRows {

	use SearchesObjects;

	/**
	 * The settings bridge the using class holds.
	 *
	 * @return SettingsService The bridge to OpenRegister plus app config.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	abstract protected function settings(): SettingsService;

	/**
	 * Read one row of a configured schema by id.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $id The row's UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function findRow(string $schemaKey, string $id): array {
		$context = $this->resolveContext(schemaKey: $schemaKey);
		if ($context === null || $id === '') {
			return [];
		}

		try {
			$row = $this->findObjectAsArray(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				id: $id,
			);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end findRow()

	/**
	 * Search one configured schema, answering an empty list on any failure.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param array<string, mixed> $filters The object-field filters plus pagination keys.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function findRows(string $schemaKey, array $filters): array {
		$context = $this->resolveContext(schemaKey: $schemaKey);
		if ($context === null) {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: $filters,
			);
		} catch (Throwable $e) {
			return [];
		}
	}//end findRows()

	/**
	 * The object service plus the register/schema pair a read needs.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 *
	 * @return array{objectService: mixed, register: string, schema: string}|null
	 *         The context, or null when OpenRegister or the schema is unconfigured.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function resolveContext(string $schemaKey): ?array {
		$objectService = $this->settings()->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settings()->getConfigValue(key: 'register');
		$schema = $this->settings()->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end resolveContext()

	/**
	 * The case's own UUID.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string|null The UUID, or null when the payload carries none.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function caseId(array $case): ?string {
		$id = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end caseId()

	/**
	 * The UUID inside a value that may be a bare id or a ZGW URL.
	 *
	 * @param string $value The raw reference.
	 *
	 * @return string|null The UUID, or null when the value holds none.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function uuidIn(string $value): ?string {
		$pattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		if (preg_match($pattern, $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidIn()

	/**
	 * A value that parses as a date, as Y-m-d.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string|null The date, or null when the value is not one.
	 *
	 * @spec exclude infrastructure plumbing with no requirement of its own; it is
	 *   exercised through the archival derivation that calls it
	 */
	protected function asDate(string $value): ?string {
		if ($value === '' || strtotime($value) === false) {
			return null;
		}

		return substr($value, 0, 10);
	}//end asDate()
}//end trait
