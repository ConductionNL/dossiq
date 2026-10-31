<?php

/**
 * A case type's rights rows, in OpenRegister's matrix row vocabulary.
 *
 * The pure half of {@see \OCA\Dossiq\Listener\CaseTypeRightsRowsListener}.
 * dossiq declares rights per case type (`caseType.rightsMatrix`); OpenRegister
 * enforces a matrix on the case schema keyed on `assignedGroup`. This turns one
 * into the other and decides nothing about a person: OpenRegister answers who
 * may do what (REQ-ACC-01).
 *
 * Per rights row:
 *
 * - the role becomes its organisation role's Nextcloud group (`ncGroupId`);
 * - the department becomes the groups of the roles in that department, every
 *   role's group for `*`, or `$self` for `$ownTeam` (the caller's own teams);
 * - the case type, and the confidentiality levels up to and including the
 *   row's, become `where` conditions;
 * - read verbs hold in every status; every edit verb, create included, and
 *   adding or deleting documents hold only in the row's `statuses` (Ruben,
 *   23 October 2026). The assignee's own rule is not a matrix row and is
 *   untouched.
 *
 * 🔴 A ROW THAT DOES NOT RESOLVE IS LEFT OUT WHOLE. No group for the role, no
 * team for the department, a level nobody knows: each would otherwise become a
 * row wider than the one written.
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
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * Translates case type rights rows into matrix rows.
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class CaseTypeRightsMatrixRows {

	/**
	 * The department value for the caller's own teams.
	 */
	public const OWN_TEAM = '$ownTeam';

	/**
	 * Department values that stand for every team.
	 *
	 * @var array<int, string>
	 */
	private const EVERY_DEPARTMENT = ['*', 'all', 'alle'];

	/**
	 * Confidentiality levels, lowest first, as the case and the case type spell them.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = [
		'openbaar',
		'beperkt_openbaar',
		'intern',
		'zaakvertrouwelijk',
		'vertrouwelijk',
		'confidentieel',
		'geheim',
		'zeer_geheim',
	];

	/**
	 * Rights verbs that read a case.
	 *
	 * @var array<int, string>
	 */
	private const READ_VERBS = ['read', 'list'];

	/**
	 * Rights verbs that change a case, as OpenRegister names them.
	 *
	 * @var array<int, string>
	 */
	private const EDIT_VERBS = ['create', 'update', 'delete', 'handle'];

	/**
	 * Document rights and the file verb each one grants.
	 *
	 * @var array<string, string>
	 */
	private const DOCUMENT_VERBS = ['read' => 'readFiles', 'add' => 'addFiles', 'delete' => 'deleteFiles'];

	/**
	 * Matrix rows for every case type.
	 *
	 * @param array<int, array{id: string, rightsMatrix: mixed}> $caseTypes The current case types.
	 * @param array<int, array<string, mixed>>                    $roles     The organisation roles.
	 *
	 * @return array<int, array<string, mixed>> The matrix rows.
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function rows(array $caseTypes, array $roles): array {
		$rows = [];
		foreach ($caseTypes as $caseType) {
			$id = trim((string)$caseType['id']);
			if ($id === '' || is_array($caseType['rightsMatrix']) === false) {
				continue;
			}

			foreach ($caseType['rightsMatrix'] as $right) {
				if (is_array($right) === true) {
					$rows = array_merge($rows, $this->rowsFor(right: $right, caseTypeId: $id, roles: $roles));
				}
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The matrix rows one rights row becomes: read, edit and documents.
	 *
	 * @param array<string, mixed>             $right      The rights row.
	 * @param string                           $caseTypeId The case type it belongs to.
	 * @param array<int, array<string, mixed>> $roles      The organisation roles.
	 *
	 * @return array<int, array<string, mixed>> The rows; none when the row does not resolve.
	 */
	private function rowsFor(array $right, string $caseTypeId, array $roles): array {
		$group = $this->groupOf(role: (string)($right['role'] ?? ''), roles: $roles);
		$values = $this->valuesOf(department: (string)($right['department'] ?? ''), roles: $roles);
		$levels = $this->levelsUpTo(level: (string)($right['confidentiality'] ?? ''));
		if ($group === null || $values === [] || $levels === []) {
			return [];
		}

		$base = [
			'group' => $group,
			'values' => $values,
			'where' => ['caseType' => $caseTypeId, 'confidentiality' => ['$in' => $levels]],
		];
		if (is_string($right['until'] ?? null) === true && $right['until'] !== '') {
			$base['until'] = $right['until'];
		}

		$statuses = $this->strings(value: ($right['statuses'] ?? []));
		$inStatuses = $base;
		if ($statuses !== []) {
			$inStatuses['where']['status'] = ['$in' => $statuses];
		}

		$verbs = $this->strings(value: ($right['actions'] ?? []));
		[$readFiles, $changeFiles] = $this->fileVerbs(right: $right, verbs: $verbs);

		$rows = [];
		$readVerbs = [];
		if (array_intersect($verbs, self::READ_VERBS) !== []) {
			$readVerbs = ['read'];
		}

		$rows[] = $base + ['actions' => $readVerbs];
		$rows[] = $inStatuses + ['actions' => array_values(array_intersect(self::EDIT_VERBS, $verbs))];
		$rows[] = $base + ['actions' => $readFiles];
		$rows[] = $inStatuses + ['actions' => $changeFiles];

		return array_values(array_filter($rows, static fn (array $row): bool => $row['actions'] !== []));
	}//end rowsFor()

	/**
	 * The file verbs a rights row grants: reading, and adding or deleting.
	 *
	 * `documentActions` when the row has it; otherwise the case verbs read as
	 * before it existed (delete is all, create is add, read is read), so a
	 * stored row keeps its meaning.
	 *
	 * @param array<string, mixed> $right The rights row.
	 * @param array<int, string>   $verbs Its case verbs.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} Read verbs, change verbs.
	 */
	private function fileVerbs(array $right, array $verbs): array {
		$documents = $this->documentRights(right: $right, verbs: $verbs);
		$granted = array_values(array_intersect_key(self::DOCUMENT_VERBS, array_flip($documents)));

		return [
			array_values(array_intersect($granted, ['readFiles'])),
			array_values(array_diff($granted, ['readFiles'])),
		];
	}//end fileVerbs()

	/**
	 * What a rights row lets the role do with documents: of read, add, delete.
	 *
	 * @param array<string, mixed> $right The rights row.
	 * @param array<int, string>   $verbs Its case verbs.
	 *
	 * @return array<int, string>
	 */
	private function documentRights(array $right, array $verbs): array {
		if (array_key_exists('documentActions', $right) === true) {
			return $this->strings(value: $right['documentActions']);
		}

		if (in_array('delete', $verbs, true) === true) {
			return ['read', 'add', 'delete'];
		}

		if (in_array('create', $verbs, true) === true) {
			return ['read', 'add'];
		}

		return array_values(array_intersect(['read'], $verbs));
	}//end documentRights()

	/**
	 * The Nextcloud group of a role named by id or role name, or null.
	 *
	 * @param string                           $role  The role as the rights row names it.
	 * @param array<int, array<string, mixed>> $roles The organisation roles.
	 *
	 * @return string|null The group.
	 */
	private function groupOf(string $role, array $roles): ?string {
		$role = trim($role);
		if ($role === '') {
			return null;
		}

		foreach ($roles as $candidate) {
			$named = in_array($role, [(string)($candidate['id'] ?? ''), (string)($candidate['roleName'] ?? '')], true);
			$group = trim((string)($candidate['ncGroupId'] ?? ''));
			if ($named === true && $group !== '') {
				return $group;
			}
		}

		return null;
	}//end groupOf()

	/**
	 * The `assignedGroup` values a department stands for.
	 *
	 * @param string                           $department The department as the rights row names it.
	 * @param array<int, array<string, mixed>> $roles      The organisation roles.
	 *
	 * @return array<int, string> The values; empty when nothing resolves.
	 */
	private function valuesOf(string $department, array $roles): array {
		$department = trim($department);
		if ($department === self::OWN_TEAM) {
			return ['$self'];
		}

		$every = in_array(strtolower($department), self::EVERY_DEPARTMENT, true);
		$groups = [];
		foreach ($roles as $role) {
			$group = trim((string)($role['ncGroupId'] ?? ''));
			$inDepartment = strcasecmp(trim((string)($role['department'] ?? '')), $department) === 0;
			if ($group !== '' && $department !== '' && ($every === true || $inDepartment === true)) {
				$groups[] = $group;
			}
		}

		$groups = array_values(array_unique($groups));
		sort($groups, SORT_STRING);
		return $groups;
	}//end valuesOf()

	/**
	 * The confidentiality levels up to and including one, or none for an unknown level.
	 *
	 * @param string $level The row's level.
	 *
	 * @return array<int, string> The levels.
	 */
	private function levelsUpTo(string $level): array {
		$position = array_search($level, self::LEVELS, true);
		if ($position === false) {
			return [];
		}

		return array_slice(self::LEVELS, 0, $position + 1);
	}//end levelsUpTo()

	/**
	 * The non-empty strings of a list.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, string> The strings.
	 */
	private function strings(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter(array_map('trim', array_filter($value, 'is_string')), static fn (string $item): bool => $item !== ''));
	}//end strings()
}//end class
