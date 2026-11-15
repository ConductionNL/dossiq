<?php

/**
 * A stored list of rows, however it was stored
 *
 * Declarations and steps are stored as a JSON string or as an array,
 * depending on who wrote them. A JSON string is decoded first; anything that
 * is then not an array is an empty list; otherwise the entries that are rows
 * are kept, in order, and every other entry is dropped.
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
 * Reads a stored value as a list of rows.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class RowList {
	/**
	 * The rows a stored value holds.
	 *
	 * @param mixed $value A JSON string, an array, or anything else.
	 *
	 * @return array<int, array<mixed>> The rows, re-indexed; empty when there are none.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function from(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		return self::rowsIn(list: $value);
	}//end from()

	/**
	 * The entries of a list that are rows, in order.
	 *
	 * @param array<mixed> $list The list.
	 *
	 * @return array<int, array<mixed>> The rows.
	 */
	private static function rowsIn(array $list): array {
		$rows = [];
		foreach ($list as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsIn()
}//end class
