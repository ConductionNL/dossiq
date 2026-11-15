<?php

/**
 * A stored value as an array, however it was stored
 *
 * A property declared as an array comes back as JSON from a store that
 * round-trips it through a text column. A JSON string is decoded first, and
 * anything that is then not an array is an empty array.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Reads a stored value as an array.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class StoredArray {
	/**
	 * The array a stored value holds.
	 *
	 * @param mixed $value A JSON string, an array, or anything else.
	 *
	 * @return array<mixed> The array, empty when the value holds none.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function from(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end from()
}//end class
