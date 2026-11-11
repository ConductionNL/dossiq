<?php

/**
 * Reads the JSON bodies the Haal Centraal style registries answer with.
 *
 * The BRK and WOZ adapters read their answers the same way: a search answers
 * with a HAL `_embedded` list, an object lookup with the object, either bare
 * or wrapped under one key. This holds those reads once. It is pure: it takes
 * the body as a string and touches neither client nor logger.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External;

use OCP\Http\Client\IResponse;

/**
 * The list or the object a registry answer carries.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
final class HalResponseBody {

	/**
	 * The `_embedded` list under one key, or an empty list.
	 *
	 * @param string $body The response body.
	 * @param string $key  The key under `_embedded`.
	 *
	 * @return array<int|string, mixed> The listed objects.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function embeddedList(string $body, string $key): array {
		$data = json_decode($body, true);
		if (is_array($data) === false) {
			return [];
		}

		$embedded = ($data['_embedded'] ?? []);
		if (is_array($embedded) === false) {
			return [];
		}

		return (array)($embedded[$key] ?? []);
	}//end embeddedList()

	/**
	 * The object an object lookup answered with, wrapped under a key or bare.
	 *
	 * @param string $body The response body.
	 * @param string $key  The key the object may be wrapped under.
	 *
	 * @return array<string, mixed> The object, or an empty array when there is none.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function singleObject(string $body, string $key): array {
		$data = json_decode($body, true);
		$object = ($data[$key] ?? $data);
		if (is_array($object) === false) {
			return [];
		}

		return $object;
	}//end singleObject()

	/**
	 * What an object lookup answered: the object, nothing, or a failed status.
	 *
	 * A 404 and a 2xx answer without an object are both nothing (an empty
	 * array); any other status outside 2xx comes back as that status.
	 *
	 * @param IResponse $response The answer.
	 * @param string    $key      The key the object may be wrapped under.
	 *
	 * @return array<string, mixed>|int The object, an empty array, or the failed status.
	 *
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function objectOf(IResponse $response, string $key): array|int {
		$status = (int)$response->getStatusCode();
		if ($status === 404) {
			return [];
		}

		if ($status < 200 || $status >= 300) {
			return $status;
		}

		return $this->singleObject(body: (string)$response->getBody(), key: $key);
	}//end objectOf()
}//end class
