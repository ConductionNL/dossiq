<?php

/**
 * Dossiq inherited row values.
 *
 * Two questions the case type resolver asks of what a type declares, kept
 * apart from the merge itself: whether a child left a field empty (so the
 * parent's value stands), and whether a type's own statuses make a whole
 * lifecycle (so they replace the parent's instead of merging by name).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * What a child type's own declaration means for the merge.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class InheritedRowValues {

	/**
	 * Whether a value counts as "the child said nothing".
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean True when it is null, an empty string or an empty array.
	 */
	public function isEmpty(mixed $value): bool {
		if ($value === null || $value === []) {
			return true;
		}

		if (is_string($value) === true) {
			return (trim($value) === '');
		}

		return false;
	}//end isEmpty()

	/**
	 * Whether status rows make a lifecycle on their own: a start (a status that
	 * is not final) and an end (one that is).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows a type declares.
	 *
	 * @return bool True when the rows hold both.
	 */
	public function isWholeLifecycle(array $rows): bool {
		$final = array_filter($rows, static fn (array $row): bool => (($row['isFinal'] ?? false) === true));

		return $final !== [] && count($final) < count($rows);
	}//end isWholeLifecycle()
}//end class
