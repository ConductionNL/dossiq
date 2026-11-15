<?php

/**
 * The uid a listener records as the actor of what it writes.
 *
 * The signed-in user, or `system` when the write happens with nobody signed
 * in. The using class provides `$userSession` (IUserSession).
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec exclude shared actor lookup of several listeners, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

/**
 * The signed-in uid, or `system`.
 *
 * @spec exclude shared actor lookup of several listeners, covered by their own specs
 */
trait NamesTheActor {

	/**
	 * The uid of the signed-in user, or `system` when nobody is signed in.
	 *
	 * @return string
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()
}//end trait
