<?php

/**
 * Dossiq case type row origins.
 *
 * Which rows of a case type's merged blueprint a parent gave and which the
 * type overrides, as board DqZaaktypeVelden counts them on the parent type
 * card ("Geërfde velden 6 van 14", "Overschreven").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 *
 * @spec openspec/changes/case-type-inherited-field-counts/specs/case-types/spec.md#requirement-the-blueprint-counts-inherited-and-overridden-fields
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Marks and counts the origin of merged blueprint rows.
 *
 * @spec openspec/changes/case-type-inherited-field-counts/specs/case-types/spec.md#requirement-the-blueprint-counts-inherited-and-overridden-fields
 */
final class RowOrigins {

	/**
	 * Mark an own row that replaces an ancestor's row under the same key.
	 *
	 * @param array<string, mixed>                $row    The tagged row about to be merged.
	 * @param boolean                             $isOwn  Whether the row is the type's own.
	 * @param array<string, array<string, mixed>> $merged The rows merged so far, by merge key.
	 * @param string                              $key    The row's merge key.
	 *
	 * @return array<string, mixed> The row, with `overrides: true` when it replaces one.
	 *
	 * @spec openspec/changes/case-type-inherited-field-counts/specs/case-types/spec.md#requirement-the-blueprint-counts-inherited-and-overridden-fields
	 */
	public function markOverride(array $row, bool $isOwn, array $merged, string $key): array {
		if ($isOwn === true && isset($merged[$key]) === true) {
			$row['overrides'] = true;
		}

		return $row;
	}//end markOverride()

	/**
	 * How many rows there are, how many a parent gave and how many the type overrides.
	 *
	 * @param array<int, array<string, mixed>> $rows            The merged rows, each with `origin`.
	 * @param string                           $inheritedOrigin The origin value of an inherited row.
	 *
	 * @return array{total: int, inherited: int, overridden: int} The counts.
	 *
	 * @spec openspec/changes/case-type-inherited-field-counts/specs/case-types/spec.md#requirement-the-blueprint-counts-inherited-and-overridden-fields
	 */
	public function counts(array $rows, string $inheritedOrigin): array {
		$inherited  = 0;
		$overridden = 0;
		foreach ($rows as $row) {
			if (($row['origin'] ?? '') === $inheritedOrigin) {
				$inherited++;
			}

			if (($row['overrides'] ?? false) === true) {
				$overridden++;
			}
		}

		return ['total' => count($rows), 'inherited' => $inherited, 'overridden' => $overridden];
	}//end counts()
}//end class
