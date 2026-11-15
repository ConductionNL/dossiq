<?php

/**
 * Dossiq CollectsUniqueNames trait
 *
 * A list of declared names, trimmed, without empties and each one once, in
 * the order they were declared.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Trimmed, non-empty, unique names out of a list of scalar entries.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait CollectsUniqueNames {
	/**
	 * The entries as names: trimmed, non-empty and each one once.
	 *
	 * @param array<mixed> $entries The entries, each castable to a string.
	 *
	 * @return array<int, string> The names, in declaration order.
	 */
	private function uniqueNames(array $entries): array {
		$names = [];
		foreach ($entries as $entry) {
			$name = trim((string)$entry);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end uniqueNames()
}//end trait
