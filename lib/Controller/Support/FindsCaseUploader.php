<?php

/**
 * Dossiq FindsCaseUploader support trait.
 *
 * The caller lookup of the endpoints that add documents to a case. The using
 * class provides `$userSession` and `$uploadHandler`
 * ({@see \OCA\Dossiq\Kernel\Contracts\DossierUploadHandlerInterface}).
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
 * @spec exclude shared caller lookup of the document upload endpoints, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;

/**
 * The caller when they may add documents to the case, else the 401 or 403.
 *
 * @spec exclude shared caller lookup of the document upload endpoints, covered by their own specs
 */
trait FindsCaseUploader {

	/**
	 * The signed-in caller when they may add documents to this case.
	 *
	 * @param string $caseId The case.
	 *
	 * @return IUser|JSONResponse The caller, or the 401 or 403.
	 */
	private function uploader(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return $user;
	}//end uploader()
}//end trait
