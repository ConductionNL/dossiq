<?php

/**
 * Reading the rules of an authorization block: their identity, their shape and how a report names them.
 *
 * The half of {@see CaseAuthorizationMerge} that looks at one rule at a time.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * Identity, shape and label of authorization rules.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseAuthorizationEntries {

	/**
	 * The teams a block's team rules grant.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return array<int, string> The teams.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function teamsIn(array $block): array {
		$teams = [];
		foreach ($block as $value) {
			foreach ($this->listOf(value: $value) as $entry) {
				if ($this->isTeamRule(entry: $entry) === true) {
					$teams[] = (string)$entry['group'];
				}
			}
		}

		return $teams;
	}//end teamsIn()

	/**
	 * Whether an entry is a team rule for a team the projector manages.
	 *
	 * @param mixed              $entry The entry.
	 * @param array<int, string> $teams The managed teams.
	 *
	 * @return bool True for a managed team rule.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function isManagedTeamRule(mixed $entry, array $teams): bool {
		return $this->isTeamRule(entry: $entry) === true && in_array((string)$entry['group'], $teams, true) === true;
	}//end isManagedTeamRule()

	/**
	 * Whether an entry is shaped like a handling team rule: a group granted on `caseType` and nothing else.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return bool True for a team-shaped rule.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function isTeamRule(mixed $entry): bool {
		return is_array($entry) === true
			&& array_keys($entry) !== [] && array_diff(array_keys($entry), ['group', 'match']) === []
			&& is_string($entry['group'] ?? null) === true
			&& array_keys((array)($entry['match'] ?? [])) === [CaseTeamAccessRules::MATCH_FIELD];
	}//end isTeamRule()

	/**
	 * How a rule is named in a report: the principal it names and what it matches.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function label(mixed $entry): string {
		if (is_array($entry) === false) {
			return (string)json_encode($entry);
		}

		$match = $this->canonical(value: (array)($entry['match'] ?? []));

		return (string)($entry['group'] ?? ($entry['user'] ?? '')) . ':' . (string)json_encode($match);
	}//end label()

	/**
	 * A canonical key for an entry, independent of key order.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function key(mixed $entry): string {
		return (string)json_encode($this->canonical(value: $entry));
	}//end key()

	/**
	 * The value with every map's keys sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonical value.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map($this->canonical(...), $value);
	}//end canonical()

	/**
	 * Whether a value is a list of rules (or absent).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list or null.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function isList(mixed $value): bool {
		return $value === null || (is_array($value) === true && array_is_list($value) === true);
	}//end isList()

	/**
	 * A value as a list of rules.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, mixed> The list.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function listOf(mixed $value): array {
		if (is_array($value) === true && array_is_list($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()

	/**
	 * The entries without repeats, first one kept.
	 *
	 * @param array<int, mixed> $entries The entries.
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function unique(array $entries): array {
		$seen = [];
		$kept = [];
		foreach ($entries as $entry) {
			$key = $this->key(entry: $entry);
			if (isset($seen[$key]) === false) {
				$seen[$key] = true;
				$kept[] = $entry;
			}
		}

		return $kept;
	}//end unique()
}//end class
