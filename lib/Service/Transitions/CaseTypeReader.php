<?php

/**
 * The case type's own rules, read as booleans.
 *
 * A case type says whether its cases may be suspended, whether their term may
 * be extended and by how long, and which status a case of that type starts in.
 * Every one of those is a flag on a row in OpenRegister, and every caller that
 * reads them by hand ends up writing the same defensive coercion, because a
 * boolean that travelled through JSON arrives as `true`, `"true"` or `1`
 * depending on who wrote it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;

/**
 * Reads a case type's lifecycle rules and its statuses.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseTypeReader {

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The lifecycle rules of one case type.
	 *
	 * A case type that cannot be read allows nothing, which is the safe
	 * reading: an unreadable rule is not permission.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array{suspensionAllowed: bool, extensionAllowed: bool, extensionPeriod: string, initialStatus: string, title: string}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function read(string $caseTypeId): array {
		$row = $this->findRow(schemaKey: 'case_type_schema', id: $caseTypeId);

		return [
			'suspensionAllowed' => $this->flag(value: ($row['suspensionAllowed'] ?? false)),
			'extensionAllowed' => $this->flag(value: ($row['extensionAllowed'] ?? false)),
			'extensionPeriod' => (string)($row['extensionPeriod'] ?? ''),
			'initialStatus' => (string)($row['initialStatus'] ?? ''),
			'title' => (string)($row['title'] ?? ($row['name'] ?? '')),
		];
	}//end read()

	/**
	 * Whether a statusType closes the case it is on.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return bool True when the statusType carries isFinal.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isFinalStatus(string $statusTypeId): bool {
		$row = $this->findRow(schemaKey: 'status_type_schema', id: $statusTypeId);

		return $this->flag(value: ($row['isFinal'] ?? false));
	}//end isFinalStatus()

	/**
	 * Whether a statusType belongs to a case type.
	 *
	 * Asked of the CHILD, because `statusType.caseType` is where the relation
	 * actually lives; the case type carries no list of its statuses.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return bool True when the statusType names this case type as its parent.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function statusBelongsTo(string $statusTypeId, string $caseTypeId): bool {
		if ($statusTypeId === '' || $caseTypeId === '') {
			return false;
		}

		$row = $this->findRow(schemaKey: 'status_type_schema', id: $statusTypeId);
		$parent = ($row['caseType'] ?? '');
		if (is_array($parent) === true) {
			$parent = ($parent['id'] ?? ($parent['@self']['id'] ?? ''));
		}

		return (string)$parent === $caseTypeId;
	}//end statusBelongsTo()

	/**
	 * Coerce a JSON-shaped boolean.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return bool True only for the values that mean true.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function flag(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}//end flag()

	/**
	 * Read one row of a configured schema by id.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $id The row's UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function findRow(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $id === '') {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->toArray(value: $objectService->find($id, register: $register, schema: $schema));
		} catch (\Throwable $e) {
			return [];
		}
	}//end findRow()

	/**
	 * Coerce an ObjectService result to an array.
	 *
	 * @param mixed $value The raw result.
	 *
	 * @return array<string, mixed> The coerced array, empty when uncoercible.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
