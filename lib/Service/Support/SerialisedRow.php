<?php

/**
 * An OpenRegister result as a row, or nothing when it serialises to none
 *
 * The nullable sibling of SerialisedArray::of(): an array is kept and an
 * entity's jsonSerialize() array is used, but anything else (an object
 * without jsonSerialize(), a serialisation that is not an array, a scalar,
 * null) is null rather than an empty array, so a caller can tell an absent
 * row from an empty one.
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
 * Turns a result into the row it serialises to, or null.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class SerialisedRow {
	/**
	 * The result as a row: itself, or its jsonSerialize() array, or null.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed>|null The row; null when the value serialises to no array.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;
	}//end of()
}//end class
