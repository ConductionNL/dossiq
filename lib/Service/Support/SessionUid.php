<?php

/**
 * Dossiq session uid
 *
 * The user id of the signed-in caller, or the empty string without one: the
 * answer the access guards and the case journal each read off the session.
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
 * @spec exclude shared session read; each caller carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCP\IUserSession;

/**
 * Reads the signed-in caller's user id off the session.
 *
 * @spec exclude shared session read; each caller carries its own spec
 */
final class SessionUid {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The session.
	 *
	 * @spec exclude shared session read; each caller carries its own spec
	 */
	public function __construct(
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The signed-in caller's user id, or '' without one.
	 *
	 * @return string
	 *
	 * @spec exclude shared session read; each caller carries its own spec
	 */
	public function uid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uid()
}//end class
