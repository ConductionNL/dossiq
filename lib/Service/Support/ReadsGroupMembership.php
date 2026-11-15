<?php

/**
 * Dossiq ReadsGroupMembership trait
 *
 * Whether a uid is a member of one of a list of groups, or an administrator,
 * read through the using class's group manager.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use Throwable;

/**
 * Group membership or administration through `$this->groupManager`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsGroupMembership {
	/**
	 * Whether the uid is in one of the groups, or else an administrator.
	 *
	 * @param string             $uid    The uid, '' for none.
	 * @param array<int, string> $groups The groups, read in order.
	 *
	 * @return bool True when a group or the admin check says so; false for no uid or a failed check.
	 */
	private function inGroupsOrAdmin(string $uid, array $groups): bool {
		if ($uid === '') {
			return false;
		}

		try {
			foreach ($groups as $group) {
				if ($this->groupManager->isInGroup($uid, $group) === true) {
					return true;
				}
			}

			return $this->groupManager->isAdmin($uid);
		} catch (Throwable $e) {
			// An unresolvable group check is not an authorization.
			return false;
		}
	}//end inGroupsOrAdmin()
}//end trait
