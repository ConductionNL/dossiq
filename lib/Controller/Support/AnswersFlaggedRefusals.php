<?php

/**
 * Dossiq AnswersFlaggedRefusals support trait.
 *
 * The 401 and 403 of the controllers whose answers carry a `success` flag.
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
 * @spec exclude shared refusals of several controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * `{success: false, error}` refusals for a caller without a session or without access.
 *
 * @spec exclude shared refusals of several controllers, covered by their own specs
 */
trait AnswersFlaggedRefusals {

	/**
	 * The 401 for a caller without a session.
	 *
	 * @return JSONResponse
	 */
	private function notAuthenticated(): JSONResponse {
		return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
	}//end notAuthenticated()

	/**
	 * The 403 for a caller without access.
	 *
	 * @return JSONResponse
	 */
	private function notAuthorized(): JSONResponse {
		return new JSONResponse(
			['success' => false, 'error' => 'Not authorized'],
			Http::STATUS_FORBIDDEN
		);
	}//end notAuthorized()
}//end trait
