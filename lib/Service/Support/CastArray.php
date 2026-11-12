<?php

/**
 * An OpenRegister result cast to an array
 *
 * The loosest of the result readers: an array is kept; an object with
 * jsonSerialize() gives that value cast to an array, whatever it is; any
 * other object gives its public properties; anything else is empty. See
 * SerialisedArray::of() for the reading that refuses a serialisation that is
 * not an array.
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
 * Casts a result to an array.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CastArray {
	/**
	 * The result as an array: itself, its serialisation cast, its properties, or empty.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed> The array.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return (array)$value->jsonSerialize();
		}

		if (is_object($value) === true) {
			return (array)$value;
		}

		return [];
	}//end of()
}//end class
