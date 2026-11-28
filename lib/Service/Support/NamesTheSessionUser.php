<?php

/**
 * The user id of the signed-in session user.
 *
 * Services that credit a write to the acting user read it from the session,
 * never from the request. This trait gives the two answers they use: the uid
 * or 'system' outside a user session, and the uid or an empty string.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * The session user's uid, read through `$this->userSession`.
 *
 * @spec exclude shared session lookup; each using class carries its own spec
 */
trait NamesTheSessionUser {

	/**
	 * The acting user id from the session.
	 *
	 * @return string The user id, or 'system' outside a user session.
	 */
	private function sessionActor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end sessionActor()

	/**
	 * The signed-in user's id from the session.
	 *
	 * @return string The user id, or '' when no user is signed in.
	 */
	private function sessionUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end sessionUid()
}//end trait
