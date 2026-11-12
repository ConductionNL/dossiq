<?php

/**
 * The 401 a register lookup answers a caller without a session with.
 *
 * The BRK and WOZ lookups both refuse an anonymous caller the same way,
 * before any adapter is asked; this answers it once.
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
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;

/**
 * Refuses a caller without a session.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
final class SignedIn {

	/**
	 * The 401 when nobody is signed in, else null.
	 *
	 * @param IUserSession $session The session.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public static function refusal(IUserSession $session): ?JSONResponse {
		if ($session->getUser() === null) {
			return new JSONResponse(
				['error' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		return null;
	}//end refusal()
}//end class
