<?php

/**
 * Canonical form of a stored value
 *
 * Object keys sorted at every depth, lists left in order, so two values
 * that differ only in key order read as the same. The seal hashes this form
 * and the guard compares it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\FrozenRecord
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\FrozenRecord;

/**
 * Sorts object keys at every depth and leaves lists in order.
 *
 * @spec openspec/specs/frozen-record/spec.md
 */
final class CanonicalValue {

	/**
	 * The canonical form of a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonical value.
	 *
	 * @spec openspec/specs/frozen-record/spec.md
	 */
	public static function formOf(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$canonical = array_map(static fn (mixed $item): mixed => self::formOf(value: $item), $value);
		if (array_is_list($canonical) === false) {
			ksort($canonical, SORT_STRING);
		}

		return $canonical;
	}//end formOf()
}//end class
