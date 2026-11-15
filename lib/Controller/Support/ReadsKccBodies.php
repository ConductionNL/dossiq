<?php

/**
 * Dossiq ReadsKccBodies support trait.
 *
 * The request body of a KCC write, without its identifiers, and the 401 of the KCC endpoints. The using class provides the controller `$request`.
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
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The request body of a KCC write, without its identifiers, and the 401 of the KCC endpoints.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait ReadsKccBodies {

	/**
	 * Read the JSON / form body parameters, excluding routing params.
	 *
	 * @return array<string, mixed> The body parameters.
	 */
	private function bodyParams(): array {
		$params = $this->request->getParams();
		unset($params['id'], $params['uuid'], $params['@self'], $params['_route']);
		return $params;
	}//end bodyParams()

	/**
	 * Build a 401 Unauthorized response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end trait
