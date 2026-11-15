<?php

/**
 * Dossiq RefusesUnreadableCases support trait.
 *
 * The refusal the money controllers give a caller who is not signed in or may not read the case. The using class provides `$userSession` and `$caseAccess` properties.
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
 * @spec exclude shared refusal step; each using controller carries its own spec
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Refuses a caller who may not read the case.
 *
 * @spec exclude shared refusal step; each using controller carries its own spec
 */
trait RefusesUnreadableCases {

	/**
	 * The refusal for a caller without a session or read access, or null when they may read.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse|null
	 */
	private function unreadableCaseRefusal(string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end unreadableCaseRefusal()
}//end trait
