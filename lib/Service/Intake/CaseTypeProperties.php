<?php

/**
 * Dossiq Case Type Properties
 *
 * Answers that answer the case type's own questions: an answer whose name
 * is a property definition of the case type becomes an entry of the case's
 * `properties` bag, where a handler reads it on the case screen. A
 * definition the type does not declare is skipped, never invented. A read
 * that fails throws, and the caller keeps the case without the entries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Turns answers into the case type's property entries.
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */
class CaseTypeProperties {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Names the propertyDefinition schema.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The property entries the answers give, or [] when there are none.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseTypeId    The case type whose questions these answer.
	 * @param array<string, mixed> $answers       The answers, by name.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @throws Throwable When the case type's property definitions cannot be read.
	 *
	 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
	 */
	public function forAnswers(object $objectService, string $register, string $caseTypeId, array $answers): array {
		$filled = [];
		foreach ($answers as $name => $value) {
			if (is_string($name) === true && is_scalar($value) === true && trim((string)$value) !== '') {
				$filled[$name] = trim((string)$value);
			}
		}

		if ($filled === []) {
			return [];
		}

		$definitions = $this->definitions(objectService: $objectService, register: $register, caseTypeId: $caseTypeId);

		$entries = [];
		foreach ($filled as $name => $value) {
			if (isset($definitions[$name]) === true) {
				$entries[] = ['propertyDefinition' => $definitions[$name], 'name' => $name, 'value' => $value];
			}
		}

		return $entries;
	}//end forAnswers()

	/**
	 * The case type's property definitions: name to id.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseTypeId    The case type.
	 *
	 * @return array<string, string>
	 */
	private function definitions(object $objectService, string $register, string $caseTypeId): array {
		$schema = (string)$this->settingsService->getConfigValue('property_definition_schema');
		if ($schema === '') {
			return [];
		}

		// A read that fails throws to the caller, which decides what a missing
		// definition costs: the entries, never the case.
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseType' => $caseTypeId],
		);

		$definitions = [];
		foreach ($rows as $row) {
			$name = trim((string)($row['name'] ?? ''));
			$id   = $this->idOfRow(row: $row);
			if ($name !== '' && $id !== '') {
				$definitions[$name] = $id;
			}
		}

		return $definitions;
	}//end definitions()

	/**
	 * A row's id from wherever the store put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOfRow(array $row): string {
		foreach ([($row['@self']['id'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $candidate) {
			$id = trim((string)($candidate ?? ''));
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}//end idOfRow()
}//end class
