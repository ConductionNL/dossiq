<?php

/**
 * Dossiq FindsCaseChangers support trait.
 *
 * The signed-in caller who may change a case, or the refusal the case-gesture controllers answer otherwise.
 * The using class provides `$userSession` and `$caseAccessGuard` properties and the `notSignedIn()` step of AnswersSignedOutCallers.
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
 * Finds the caller who may change a case.
 *
 * @spec exclude shared refusal step; each using controller carries its own spec
 */
trait FindsCaseChangers {

	/**
	 * The caller, the not-signed-in answer without a session, or a 403 carrying the refusal.
	 *
	 * @param string $caseId  The case.
	 * @param string $refusal The translated sentence a caller without change access reads.
	 *
	 * @return IUser|JSONResponse
	 */
	private function caseChanger(string $caseId, string $refusal): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notSignedIn();
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => $refusal], Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end caseChanger()
}//end trait
