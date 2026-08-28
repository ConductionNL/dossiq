<?php

/**
 * Both directions of "which status is this?".
 *
 * A status has two identities: the uuid the case stores, and the name a person
 * reads. Code needs to go both ways — a detail panel turns the id into a name,
 * and a SHIPPED flow can only carry the name, because statusType uuids are
 * minted per installation.
 *
 * They live together because they are one question asked from two sides, and
 * because keeping them apart is how the two ends of it drift: this class reads
 * `statusTypes` and its older spelling `statusses` in ONE place, so a case type
 * whose statuses resolve in one direction cannot silently fail in the other.
 *
 * Split out of {@see CaseStatusStore} when adding the name→id direction pushed
 * that class past its complexity ceiling. The store still exposes
 * `lookupStatusName()` and delegates here, so no caller moved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use Throwable;

/**
 * Resolves a statusType by id or by name within a case type.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */
class StatusTypeLookup {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service and configured schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * A statusType's human-readable name.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The name, or the empty string when unresolvable.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function nameFor(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		$statusType = $this->read(schemaKey: 'status_type_schema', id: $statusTypeId);

		return (string)($statusType['name'] ?? ($statusType['title'] ?? ''));
	}//end nameFor()

	/**
	 * A case type's statusType id, by name.
	 *
	 * Comparison is trimmed and case-insensitive, because the name is authored
	 * by hand in seed data and in the UI. It is NOT fuzzy beyond that: a near
	 * miss returns the empty string so the caller can refuse, rather than
	 * silently moving a case to whichever status looked closest.
	 *
	 * Scoped to the case's OWN type, which is what stops it matching a
	 * same-named status belonging to a different one.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $statusName The status's name, as authored.
	 *
	 * @return string The statusType UUID, or '' when there is no such status.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function idForName(string $caseTypeId, string $statusName): string {
		$wanted = strtolower(trim($statusName));
		if ($caseTypeId === '' || $wanted === '') {
			return '';
		}

		foreach ($this->statusesOf(caseTypeId: $caseTypeId) as $id => $name) {
			if (strtolower(trim($name)) === $wanted) {
				return (string)$id;
			}
		}

		return '';
	}//end idForName()

	/**
	 * A case type's statuses as `id => name`.
	 *
	 * Reads both `statusTypes` and the older `statusses` spelling. One reader
	 * accepting a key another does not is how a case type ends up with statuses
	 * that validate and cannot be resolved.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<string, string> The statuses, keyed by id.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function statusesOf(string $caseTypeId): array {
		$caseType = $this->read(schemaKey: 'case_type_schema', id: $caseTypeId);

		$statuses = ($caseType['statusTypes'] ?? ($caseType['statusses'] ?? []));
		if (is_array($statuses) === false) {
			return [];
		}

		$resolved = [];
		foreach ($statuses as $entry) {
			// An entry is either an embedded object or a bare uuid. Casting
			// FIRST and correcting afterwards raised "Array to string
			// conversion" on every embedded entry — a warning, so it neither
			// failed nor changed the result, which is exactly why it survived
			// until a test looked.
			$id = '';
			$name = '';

			if (is_array($entry) === true) {
				$id = (string)($entry['id'] ?? ($entry['uuid'] ?? ''));
				$name = (string)($entry['name'] ?? ($entry['title'] ?? ''));
			}

			if (is_array($entry) === false) {
				$id = (string)$entry;
			}

			if ($id === '') {
				continue;
			}

			// An embedded entry carries its own name; a bare uuid must be
			// resolved. Reading the embedded one first avoids a lookup per
			// status on the common path.
			if ($name === '') {
				$name = $this->nameFor(statusTypeId: $id);
			}

			$resolved[$id] = $name;
		}

		return $resolved;
	}//end statusesOf()

	/**
	 * Read one object from a configured schema.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $id        The object's id.
	 *
	 * @return array<string, mixed> The object, or an empty array when unreadable.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	private function read(string $schemaKey, string $id): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return [];
		}

		if (is_object($found) === true && method_exists($found, 'jsonSerialize') === true) {
			$found = $found->jsonSerialize();
		}

		if (is_array($found) === false) {
			return [];
		}

		return $found;
	}//end read()
}//end class
