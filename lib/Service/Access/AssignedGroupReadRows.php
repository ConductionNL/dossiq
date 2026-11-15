<?php

/**
 * The matrix rows that let the members of a case's assigned group read it.
 *
 * Ruben decided on 3 November 2026 that the members of a case's assigned group
 * may read that case: read only, so no edit, no move and no status change.
 * OpenRegister keys the case matrix on `assignedGroup`, so each group of the
 * signed-in user becomes one row: members of that group read the cases whose
 * `assignedGroup` is that group. The active organisation still decides which
 * cases a reader can reach at all.
 *
 * The group reads up to and including `vertrouwelijk` (Ruben, 3 November
 * 2026). A case marked confidentieel, geheim or zeer geheim needs its
 * behandelaar or explicit rights, and so does a case whose level is missing or
 * unknown: the row names the levels it covers, so anything else is not
 * matched.
 *
 * 🔴 ONE ROW PER GROUP, NOT ONE ROW FOR EVERYBODY. OpenRegister drops a
 * provider row that grants `authenticated`, `public` or `admin`, so "anybody
 * whose groups hold the case's group" cannot be one row. A row names the group
 * it grants, and the group check comes before the match.
 *
 * 🔴 THE ORGANISATION BOUNDARY IS OPENREGISTER'S. A matrix row may only name
 * schema properties in `where`, so it cannot carry `_organisation` itself.
 *
 * Rows are built from the session user's groups. A decision for another user
 * in the same request reads rows for the session user's groups; each row still
 * requires membership of its own group, so that can only grant less.
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

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Builds the assigned group's read rows for the signed-in user.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class AssignedGroupReadRows {

	/**
	 * Groups OpenRegister refuses in a provider row.
	 *
	 * @var array<int, string>
	 */
	private const RESERVED_GROUPS = ['public', 'authenticated', 'admin'];

	/**
	 * The highest confidentiality level the assigned group reads.
	 */
	public const HIGHEST_LEVEL = 'vertrouwelijk';

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $session The signed-in user.
	 * @param IGroupManager $groups  Their groups.
	 */
	public function __construct(
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * One read row per group of the signed-in user; none without a user.
	 *
	 * @return array<int, array{group: string, values: array<int, string>, where: array<string, mixed>, actions: array<int, string>}> The rows.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function rows(): array {
		$user = $this->session->getUser();
		if ($user === null) {
			return [];
		}

		$levels = array_slice(
			CaseTypeRightsMatrixRows::LEVELS,
			0,
			((int)array_search(self::HIGHEST_LEVEL, CaseTypeRightsMatrixRows::LEVELS, true) + 1)
		);
		$rows = [];
		foreach ($this->groups->getUserGroupIds($user) as $group) {
			$group = trim((string)$group);
			if ($group === '' || in_array(strtolower($group), self::RESERVED_GROUPS, true) === true) {
				continue;
			}

			$rows[] = [
				'group' => $group,
				'values' => [$group],
				'where' => ['confidentiality' => ['$in' => $levels]],
				'actions' => ['read'],
			];
		}

		return $rows;
	}//end rows()
}//end class
