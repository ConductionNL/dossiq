<?php

/**
 * An OpenRegister result as an array, through whatever the object offers
 *
 * An array is kept. An object gives its jsonSerialize() array when it has
 * one, else its toArray() array, else its public properties. Anything else
 * is empty. Routing, role resolution and the recommendation store read
 * OpenRegister's answers this way. SerialisedArray::of() is the stricter
 * reading that ignores toArray() and does not cast.
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
 * Turns a result into an array by the first representation the object offers.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ObjectArray {
	/**
	 * The result as an array: itself, the object's own array, or empty.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed> The array; empty when the value is neither an array nor an object.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			return self::ofObject(value: $value);
		}

		return [];
	}//end of()

	/**
	 * An object's jsonSerialize() array, else its toArray() array, else its public properties.
	 *
	 * @param object $value The object.
	 *
	 * @return array<mixed> The array.
	 */
	private static function ofObject(object $value): array {
		if (method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (method_exists($value, 'toArray') === true) {
			$arr = $value->toArray();
			if (is_array($arr) === true) {
				return $arr;
			}
		}

		return (array)$value;
	}//end ofObject()
}//end class
