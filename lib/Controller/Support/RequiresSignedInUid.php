<?php

/**
 * Dossiq RequiresSignedInUid support trait.
 *
 * The uid of the signed-in caller, or null. The using class provides `$userSession` (IUserSession).
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
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

/**
 * The uid of the signed-in caller, or null.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait RequiresSignedInUid {

	/**
	 * Resolve the current user UID or null.
	 *
	 * @return string|null
	 */
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end requireUser()
}//end trait
