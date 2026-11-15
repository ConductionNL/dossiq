<?php

/**
 * Dossiq RefusesAnonymousCallers support trait.
 *
 * The session check of the controllers that answer an anonymous caller with
 * a 403 `Not authenticated`. The using class provides `$userSession`
 * (IUserSession).
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
 * @spec exclude shared session check of several controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A 403 for a caller without a session, else null.
 *
 * @spec exclude shared session check of several controllers, covered by their own specs
 */
trait RefusesAnonymousCallers {

	/**
	 * The 403 for a caller without a session, or null when someone is signed in.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()
}//end trait
