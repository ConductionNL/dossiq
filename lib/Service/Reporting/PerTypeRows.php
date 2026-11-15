<?php

/**
 * Per-type report figures as table rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

/**
 * Turns figures keyed by case type into the list an object table reads.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class PerTypeRows {

	/**
	 * The per-type figures as a list a table can draw, each row named by its
	 * case type's title, or by its key when the type has none.
	 *
	 * @param array<string, array<string, mixed>> $perType The figures keyed by case type.
	 *
	 * @return array<int, array<string, mixed>> One row per case type, with `caseType`, `caseTypeTitle`
	 *                                          and `penaltyTotalEuros` beside the figures.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function listOf(array $perType): array {
		$rows = [];
		foreach ($perType as $key => $figures) {
			$title = (string)($figures['title'] ?? '');
			if ($title === '') {
				$title = (string)$key;
			}

			$rows[] = [
				'caseType' => (string)$key,
				'caseTypeTitle' => $title,
				...$figures,
				'penaltyTotalEuros' => round(((int)($figures['dwangsomTotalCents'] ?? 0)) / 100, 2),
			];
		}

		return $rows;
	}//end listOf()
}//end class
