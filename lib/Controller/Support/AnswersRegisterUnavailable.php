<?php

/**
 * Dossiq AnswersRegisterUnavailable support trait.
 *
 * The 503 for a register that is not available.
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
 * The 503 for a register that is not available.
 *
 * @spec exclude shared refusal of several controllers, covered by their own specs
 */
trait AnswersRegisterUnavailable {

	/**
	 * The 503 for a register that is not available.
	 *
	 * @return JSONResponse
	 */
	private function registerUnavailable(): JSONResponse {
		return new JSONResponse(
			['error' => 'The register is not available'],
			Http::STATUS_SERVICE_UNAVAILABLE
		);
	}//end registerUnavailable()
}//end trait
