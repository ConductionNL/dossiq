<?php

/**
 * Dossiq role permissions
 *
 * Which of dossiq's role permissions the signed-in user holds, from their
 * Nextcloud groups: `privacy` for the privacy officers (data breaches) and
 * `sociaal-domein` for the social-domain staff (indications, case meetings).
 * Admins and beheerders hold both, as they hold
 * the records in OpenRegister. The menu and the routes read these to hide what
 * the user cannot read; OpenRegister's schema authorization is what refuses.
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
 * @spec openspec/specs/admin-data-access/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Maps group membership to the manifest's role permissions.
 *
 * @spec openspec/specs/admin-data-access/spec.md
 */
class RolePermissions {

	/**
	 * The privacy officers' group: reads and handles data breaches.
	 *
	 * @var string
	 */
	public const PRIVACY_GROUP = 'dossiq-privacy';

	/**
	 * The social-domain staff's group: reads and writes indications and case
	 * meetings, and handles the re-integration case type's cases.
	 *
	 * @var string
	 */
	public const SOCIAL_DOMAIN_GROUP = 'dossiq-sociaal-domein';

	/**
	 * The groups that hold every role permission besides Nextcloud's admins.
	 *
	 * @var string
	 */
	public const MANAGERS_GROUP = 'beheerders';

	/**
	 * The role permission each role group grants, as the manifest names it.
	 *
	 * @var array<string, string>
	 */
	public const ROLE_PERMISSIONS = [
		self::PRIVACY_GROUP => 'privacy',
		self::SOCIAL_DOMAIN_GROUP => 'sociaal-domein',
	];

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The current session.
	 * @param IGroupManager $groupManager Nextcloud's groups.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The role permissions the signed-in user holds.
	 *
	 * @return array<int, string> The permissions, in a fixed order; empty when nobody is signed in.
	 *
	 * @spec openspec/specs/admin-data-access/spec.md
	 */
	public function forCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$uid = $user->getUID();
		$all = (
			$this->groupManager->isAdmin($uid) === true
			|| $this->groupManager->isInGroup($uid, self::MANAGERS_GROUP) === true
		);

		$held = [];
		foreach (self::ROLE_PERMISSIONS as $group => $permission) {
			if ($all === true || $this->groupManager->isInGroup($uid, $group) === true) {
				$held[] = $permission;
			}
		}

		return $held;
	}//end forCurrentUser()
}//end class
