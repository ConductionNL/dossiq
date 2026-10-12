<?php

/**
 * Dossiq JSON request body.
 *
 * Reads the JSON body of a request into an array, and takes from it only
 * what the caller names. `OCP\IRequest::getContent()` is protected on the
 * concrete request, so the body is read from `php://input`; a request that
 * exposes a public `getContent()` (a test double does) is read from there.
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\IRequest;
use Throwable;

/**
 * Reads a JSON request body and picks named fields from it.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
 */
class JsonRequestBody {

	/**
	 * Read and decode the JSON request body.
	 *
	 * @param IRequest $request The HTTP request.
	 *
	 * @return array<string, mixed> The decoded body, or [] when it is empty or not an object.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
	 */
	public function read(IRequest $request): array {
		// Prefer the request object's getContent() when reachable — test
		// stubs expose a public getContent() so unit tests can drive
		// controllers without faking php://input.
		$content = '';
		if (method_exists($request, 'getContent') === true) {
			try {
				$raw = $request->getContent();
				if (is_string($raw) === true) {
					$content = $raw;
				}
			} catch (Throwable $e) {
				$content = '';
			}
		}

		if ($content === '') {
			$content = (string)file_get_contents('php://input');
		}

		if ($content === '') {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end read()

	/**
	 * The named fields a body carries, and nothing else.
	 *
	 * A field that was sent as null is kept; a field that was not sent is left out.
	 *
	 * @param array<string, mixed> $body   The request parameters.
	 * @param array<int, string>   $fields The fields to take.
	 *
	 * @return array<string, mixed> The fields that were sent.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
	 */
	public function only(array $body, array $fields): array {
		$taken = [];
		foreach ($fields as $field) {
			if (array_key_exists($field, $body) === true) {
				$taken[$field] = $body[$field];
			}
		}

		return $taken;
	}//end only()

	/**
	 * A field as trimmed text, or null when it was not sent or is blank.
	 *
	 * @param array<string, mixed> $body  The request parameters.
	 * @param string               $field The field.
	 *
	 * @return string|null The text.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T05
	 */
	public function textOrNull(array $body, string $field): ?string {
		$text = trim((string)($body[$field] ?? ''));
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()
}//end class
