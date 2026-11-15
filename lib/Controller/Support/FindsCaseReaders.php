<?php

/**
 * Dossiq FindsCaseReaders support trait.
 *
 * The signed-in caller who may read a case, or the refusal the case-detail controllers answer otherwise. The using class provides `$userSession` and `$caseAccess` properties.
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
use OCP\IUser;

/**
 * Finds the caller who may read a case.
 *
 * @spec exclude shared refusal step; each using controller carries its own spec
 */
trait FindsCaseReaders {

	/**
	 * The caller, or a 401 without a session and a 403 without read access.
	 *
	 * @param string $caseId The case.
	 *
	 * @return IUser|JSONResponse
	 */
	private function caseReader(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end caseReader()
}//end trait
