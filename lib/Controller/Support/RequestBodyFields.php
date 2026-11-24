<?php

/**
 * Dossiq request body fields.
 *
 * The fields of a JSON request body as a controller asks for them: the body
 * itself, a field that may be absent, and a field that must be one of a fixed
 * set of values.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reads a request body's fields and refuses a missing or unknown choice.
 *
 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
 */
final class RequestBodyFields {

	/**
	 * The request body, decoded.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function read(IRequest $request): array {
		return (new JsonRequestBody())->read(request: $request);
	}//end read()

	/**
	 * A field as a string, or null when it was not sent.
	 *
	 * @param array<string, mixed> $body The request body.
	 * @param string               $key  The field.
	 *
	 * @return string|null The value.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function optionalString(array $body, string $key): ?string {
		if (isset($body[$key]) === false) {
			return null;
		}

		return (string)$body[$key];
	}//end optionalString()

	/**
	 * A 400 for a choice that is missing or not one of the allowed values, or null.
	 *
	 * @param string                                    $value    The value sent.
	 * @param list<string>                              $allowed  The values the field takes.
	 * @param array{required: string, invalid: string} $messages What each refusal says.
	 *
	 * @return JSONResponse|null The refusal, or null when the value is allowed.
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/tasks.md#T07
	 */
	public function choiceRefusal(string $value, array $allowed, array $messages): ?JSONResponse {
		if ($value === '') {
			return new JSONResponse(['error' => $messages['required']], Http::STATUS_BAD_REQUEST);
		}

		if (in_array(needle: $value, haystack: $allowed, strict: true) === false) {
			return new JSONResponse(['error' => $messages['invalid']], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end choiceRefusal()
}//end class
