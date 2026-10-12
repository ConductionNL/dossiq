<?php

/**
 * Dossiq session reader.
 *
 * Reads every session a case type declares in its `sessions[]`, each through
 * {@see SessionDefinition}, and refuses a session key declared twice.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Session
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
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Session;

use InvalidArgumentException;

/**
 * Reads the sessions of a case type.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
 */
final class SessionDefinitionReader {

	/**
	 * Every session a case type declares, keyed by session key.
	 *
	 * @param array<string, mixed> $caseType The case type object.
	 *
	 * @return array<string, SessionDefinition> The sessions.
	 *
	 * @throws InvalidArgumentException When an entry is malformed, or two share a key.
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	public function allOf(array $caseType): array {
		$entries = ($caseType['sessions'] ?? []);
		if (is_array($entries) === false) {
			return [];
		}

		$sessions = [];
		foreach ($entries as $entry) {
			$definition = new SessionDefinition(config: $entry);
			if (isset($sessions[$definition->key]) === true) {
				throw new InvalidArgumentException("Session '{$definition->key}' is declared twice.");
			}

			$sessions[$definition->key] = $definition;
		}

		return $sessions;
	}//end allOf()
}//end class
