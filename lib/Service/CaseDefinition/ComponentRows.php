<?php

/**
 * Dossiq case definition component rows.
 *
 * Reads the rows of an imported component file, collection by collection,
 * with the schema each collection is written to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\SettingsService;
use RuntimeException;

/**
 * The rows of a component file, with the schema each goes to.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
 */
final class ComponentRows {

	/**
	 * The settings key naming the schema each exported collection is written to.
	 *
	 * The keys are the collection names the export writes, so the two services
	 * agree by construction: a collection the export invents and the import does
	 * not know is REFUSED by name rather than dropped, which is the whole
	 * difference between an import and a count.
	 *
	 * @var array<string, string>
	 */
	private const COLLECTION_SCHEMAS = [
		'caseType' => 'case_type_schema',
		'propertyDefinitions' => 'property_definition_schema',
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'resultTypes' => 'result_type_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings The schema names.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Every row of every collection, with the schema it goes to.
	 *
	 * Lazy on purpose: a collection's schema is looked up only when its rows
	 * are reached, so a missing schema stops the write after the rows before it.
	 *
	 * @param array<string, mixed> $data        The package.
	 * @param array<int, string>   $collections The collections to write.
	 *
	 * @return \Generator<int, array{0: string, 1: array<string, mixed>}> The schema and row, in order.
	 *
	 * @throws RuntimeException When a collection has no schema configured for it.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-import-writes-the-objects-or-says-it-did-not-req-ct-42
	 */
	public function schemaRows(array $data, array $collections): \Generator {
		foreach ($collections as $collection) {
			$schema = $this->settings->getConfigValue(key: self::COLLECTION_SCHEMAS[$collection]);
			if ($schema === '') {
				throw new RuntimeException(
					"No schema is configured for '{$collection}', so its rows cannot be written."
				);
			}

			foreach ($this->rowsOf(data: $data, collection: $collection) as $row) {
				yield [$schema, $row];
			}
		}
	}//end schemaRows()

	/**
	 * The rows of one collection, whichever shape the package carries.
	 *
	 * `caseType` is one object rather than a list, so it is wrapped; every
	 * other collection is a list.
	 *
	 * @param array<string, mixed> $data The decoded component file.
	 * @param string $collection The collection name.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(array $data, string $collection): array {
		$value = ($data[$collection] ?? null);
		if (is_array($value) === false || $value === []) {
			return [];
		}

		if (array_is_list($value) === false) {
			return [$value];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end rowsOf()
}//end class
