<?php

/**
 * An OpenRegister result read as a row, for code that does not search itself
 *
 * OpenRegister answers a plain array or an entity depending on the call path.
 * SearchesObjects::objectToArrayOrNull() is the reading the search helpers
 * already use: an array is kept, an entity's jsonSerialize() array is used,
 * and anything else is no row. This class offers that same reading to the
 * services and controllers that do not compose SearchesObjects, so they stop
 * carrying their own copy of it.
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
 * Reads a result the way SearchesObjects does.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class SerialisedResult {

	use SearchesObjects;

	/**
	 * The result as a row: itself, or its jsonSerialize() array, or null.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed>|null The row; null when the value serialises to no array.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function rowOf(mixed $value): ?array {
		return (new self())->objectToArrayOrNull(value: $value);
	}//end rowOf()

	/**
	 * The result as an array: its row, or an empty array when it is none.
	 *
	 * @param mixed $value An array, an entity or anything else OpenRegister answered.
	 *
	 * @return array<mixed> The row, or an empty array.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function arrayOf(mixed $value): array {
		return (self::rowOf(value: $value) ?? []);
	}//end arrayOf()
}//end class
