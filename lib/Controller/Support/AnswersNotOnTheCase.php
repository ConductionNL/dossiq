<?php

/**
 * Dossiq AnswersNotOnTheCase support trait.
 *
 * The 403 for a caller who does not work on the case.
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
 * @spec exclude shared refusal of several controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The 403 for a caller who does not work on the case.
 *
 * @spec exclude shared refusal of several controllers, covered by their own specs
 */
trait AnswersNotOnTheCase {

	/**
	 * The 403 for a caller who does not work on the case.
	 *
	 * @return JSONResponse
	 */
	private function notOnTheCase(): JSONResponse {
		return new JSONResponse(
			['message' => 'You do not work on this case.', 'error' => 'not-authorized'],
			Http::STATUS_FORBIDDEN
		);
	}//end notOnTheCase()
}//end trait
