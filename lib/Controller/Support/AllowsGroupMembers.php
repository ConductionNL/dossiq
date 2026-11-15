<?php

/**
 * Dossiq AllowsGroupMembers support trait.
 *
 * The group check of the controllers that open to the members of a short
 * list of groups and to admins. The using class provides `$groupManager`
 * (IGroupManager) and the `ALLOWED_GROUPS` constant.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec exclude shared group check of the reporting controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * Whether a user is in one of `ALLOWED_GROUPS`, or an admin.
 *
 * @spec exclude shared group check of the reporting controllers, covered by their own specs
 */
trait AllowsGroupMembers {

	/**
	 * Check whether the given user id belongs to an allowed group (or is an
	 * NC admin, defensive default).
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return bool
	 */
	private function isAllowed(string $uid): bool {
		foreach (self::ALLOWED_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return $this->groupManager->isAdmin($uid) === true;
	}//end isAllowed()
}//end trait
