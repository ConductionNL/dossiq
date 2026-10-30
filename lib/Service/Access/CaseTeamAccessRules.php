<?php

/**
 * The handling team rules on the case schema, as arithmetic.
 *
 * The pure half of {@see CaseTeamAccessProjector}. That class reads the case
 * types and writes the live schema; this one turns the shipped case block and
 * a list of case types into the block OpenRegister evaluates.
 *
 * One conditional entry per handling team, for read and for update:
 *
 *     {"group": "vergunningen", "match": {"caseType": {"$in": ["<uuid>", ...]}}}
 *
 * OpenRegister compiles `$in` into the list query and evaluates it on a single
 * object with the same operator, so the list and the get agree by construction.
 * dossiq decides nothing here about a person: it says which case types each
 * group handles, and OpenRegister answers who may read (REQ-ACC-01).
 *
 * 🔴 THE WHOLE BLOCK IS COMPUTED AGAIN, NEVER PATCHED. The result is the shipped
 * block plus the team entries of the case types as they are now. A team taken
 * off a case type therefore loses its rule on the next run, with no ledger to
 * keep in step; a ledger that an import wiped would leave a rule nobody can
 * take off.
 *
 * 🔴 THE ORDER IS FIXED. Groups and case type ids are sorted, so the same case
 * types produce the same block byte for byte, and the projector can tell "no
 * change" from "changed" without writing the schema on every case type save.
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
 * Builds the case schema's authorization block from the shipped block and the case types.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessRules {

	/**
	 * The verbs a handling team holds on the cases of its case type.
	 *
	 * Delete is not one of them: it stays with administrators (Ruben, 2026-10-11).
	 * The file verbs keep what a team could do with a case's files before the
	 * case schema named them (case-type-rights-enforced): read, add and delete,
	 * as reading and updating the case allowed.
	 */
	public const TEAM_ACTIONS = ['read', 'update', 'readFiles', 'addFiles', 'deleteFiles'];

	/**
	 * The case property the rule matches on.
	 */
	public const MATCH_FIELD = 'caseType';

	/**
	 * The block OpenRegister should evaluate for the case schema.
	 *
	 * @param array<string, mixed>                                        $shipped   The case block the register JSON declares.
	 * @param array<int, array{id: string, teams: array<int, string>}> $caseTypes Every case type, with its handling teams.
	 *
	 * @return array<string, mixed> The block.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function block(array $shipped, array $caseTypes): array {
		$entries = $this->teamEntries(caseTypes: $caseTypes);
		if ($entries === []) {
			return $shipped;
		}

		$block = $shipped;
		foreach (self::TEAM_ACTIONS as $action) {
			$existing = ($block[$action] ?? []);
			if (is_array($existing) === false) {
				$existing = [];
			}

			$block[$action] = array_merge(array_values($existing), $entries);
		}

		return $block;
	}//end block()

	/**
	 * One conditional entry per handling team, over the case types it handles.
	 *
	 * @param array<int, array{id: string, teams: array<int, string>}> $caseTypes Every case type, with its handling teams.
	 *
	 * @return array<int, array<string, mixed>> The entries, sorted by group.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function teamEntries(array $caseTypes): array {
		$byTeam = [];
		foreach ($caseTypes as $caseType) {
			$id = trim($caseType['id']);
			if ($id === '') {
				continue;
			}

			foreach ($caseType['teams'] as $team) {
				$team = trim($team);
				if ($team === '') {
					continue;
				}

				$byTeam[$team][$id] = true;
			}
		}

		ksort($byTeam, SORT_STRING);

		$entries = [];
		foreach ($byTeam as $team => $ids) {
			$caseTypeIds = array_map('strval', array_keys($ids));
			sort($caseTypeIds, SORT_STRING);
			$entries[] = [
				'group' => (string)$team,
				'match' => [self::MATCH_FIELD => ['$in' => $caseTypeIds]],
			];
		}

		return $entries;
	}//end teamEntries()
}//end class
