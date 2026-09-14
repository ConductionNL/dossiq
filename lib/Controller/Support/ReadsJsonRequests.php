<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Dossiq\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Reading a JSON request body, and answering a bad one.
 *
 * `OCP\IRequest::getContent()` is protected on the concrete OC request, so a
 * controller that wants the raw payload reads `php://input` itself. Three
 * controllers wrote the same two helpers out character for character:
 * MandaatMatrixController, TermijnController and DwangsomController.
 *
 * Only MandaatMatrixController moves to this copy here; the other two are
 * untouched by this change and still carry their own.
 */
trait ReadsJsonRequests {
	/**
	 * Read and decode the JSON request body into an array.
	 *
	 * @return array<string, mixed> The decoded body, or `[]` when it is not an object.
	 */
	private function jsonBody(): array {
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()

	/**
	 * Build a 400 Bad Request JSON response.
	 *
	 * @param string $msg The message.
	 *
	 * @return JSONResponse The 400.
	 */
	private function badRequest(string $msg): JSONResponse {
		return new JSONResponse(['message' => $msg], Http::STATUS_BAD_REQUEST);
	}//end badRequest()
}//end trait
