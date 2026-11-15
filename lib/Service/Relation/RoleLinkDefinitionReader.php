<?php

/**
 * Dossiq relation role reader.
 *
 * Reads every relation role a case type declares in its `relations[]`, each
 * through {@see RoleLinkDefinition}, and refuses a role key declared twice.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
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
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

use InvalidArgumentException;

/**
 * Reads the relation roles of a case type.
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-case-type-declares-the-roles-a-case-can-be-linked-with-req-lcr-1
 */
final class RoleLinkDefinitionReader {

	/**
	 * Every role a case type declares, keyed by role.
	 *
	 * @param array<string, mixed> $caseType The case type object.
	 *
	 * @return array<string, RoleLinkDefinition> The roles.
	 *
	 * @throws InvalidArgumentException When an entry is not a well-formed role, or two share a key.
	 *
	 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-a-case-type-declares-the-roles-a-case-can-be-linked-with-req-lcr-1
	 */
	public function allOf(array $caseType): array {
		$entries = ($caseType['relations'] ?? []);
		if (is_array($entries) === false) {
			return [];
		}

		$roles = [];
		foreach ($entries as $entry) {
			$definition = new RoleLinkDefinition(config: $entry);
			if (isset($roles[$definition->role]) === true) {
				throw new InvalidArgumentException("Relation role '{$definition->role}' is declared twice.");
			}

			$roles[$definition->role] = $definition;
		}

		return $roles;
	}//end allOf()
}//end class
