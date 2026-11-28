<?php

/**
 * Dossiq admin caller.
 *
 * Whether the signed-in caller is a Nextcloud administrator: the one question
 * a settings read asks before it decides how much of the configuration to show.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The signed-in caller's admin standing, read from the session and the group manager.
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */
final class AdminCaller {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The session the caller is read from.
	 * @param IGroupManager $groupManager The group manager that knows who is an admin.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the caller is signed in and an administrator.
	 *
	 * Without a session the answer is false, and the group manager is not asked.
	 *
	 * @return bool True for a signed-in administrator.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()

	/**
	 * Whether a caller is signed in at all.
	 *
	 * @return bool True when the session carries a user.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isSignedIn(): bool {
		return $this->userSession->getUser() !== null;
	}//end isSignedIn()
}//end class
