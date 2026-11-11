<?php

/**
 * A per-user preference stored as a JSON object.
 *
 * The personal stages and the hidden queue groups are both kept in one
 * user config value each, as a JSON object; an empty or unreadable value
 * reads as no entries.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

/**
 * Reads a JSON object preference.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-personal-work-queue-endpoint-mvp
 */
final class JsonPreference {
	/**
	 * The entries of a stored JSON object preference.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return array<int|string, mixed> The decoded object, empty when blank or not an object.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-personal-work-queue-endpoint-mvp
	 */
	public static function decode(string $raw): array {
		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decode()
}//end class
