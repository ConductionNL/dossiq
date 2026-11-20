<?php

/**
 * Dossiq ZTC stored uuid list URLs.
 *
 * OpenRegister keeps some of a ZTC type's relations as a list of uuids, stored either as an
 * array or as a JSON string. The Catalogi API answers them as URLs. Split out of
 * ZtcCrossReferenceEnricher (method-decomposition slice 8m).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

/**
 * Turns a stored uuid list into URLs.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
final class ZtcStoredUuidUrls {

	/**
	 * Turn a stored uuid list (array or JSON string) into URLs.
	 *
	 * @param mixed  $stored The stored list
	 * @param string $prefix The URL each uuid is appended to
	 *
	 * @return array<string>|null The URLs, or null when the stored list is empty
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function urls(mixed $stored, string $prefix): ?array {
		$ids = $this->storedIds(stored: $stored);
		if (empty($ids) === true) {
			return null;
		}

		return $this->prefixedUrls(ids: $ids, prefix: $prefix);
	}//end urls()

	/**
	 * The uuids a stored list holds: a JSON string decoded, an array as it is, anything else none.
	 *
	 * @param mixed $stored The stored list
	 *
	 * @return mixed The decoded list
	 */
	private function storedIds(mixed $stored): mixed {
		if (is_string($stored) === true && $stored !== '') {
			return json_decode($stored, true);
		}

		if (is_array($stored) === true) {
			return $stored;
		}

		return [];
	}//end storedIds()

	/**
	 * The URL of every non-empty string uuid in the list.
	 *
	 * @param mixed  $ids    The decoded list
	 * @param string $prefix The URL each uuid is appended to
	 *
	 * @return array<string> The URLs
	 */
	private function prefixedUrls(mixed $ids, string $prefix): array {
		$urls = [];
		foreach ($ids as $id) {
			if (is_string($id) === true && $id !== '') {
				$urls[] = $prefix.$id;
			}
		}

		return $urls;
	}//end prefixedUrls()
}//end class
