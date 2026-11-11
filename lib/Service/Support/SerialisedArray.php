<?php

/**
 * An OpenRegister result as an array, through its own serialisation only
 *
 * OpenRegister answers a plain array or an entity depending on the call path.
 * Many readers keep an array, take an entity's jsonSerialize() array, and
 * treat anything else as empty. Unlike NormalisesObjectRows::objectToArray(),
 * an object without jsonSerialize() is not cast: it is empty.
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
 * Turns a result into the array it serialises to, or an empty array.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class SerialisedArray {
	/**
	 * The result as an array: itself, or its jsonSerialize() array, or empty.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed> The array; empty when the value serialises to no array.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end of()
}//end class
