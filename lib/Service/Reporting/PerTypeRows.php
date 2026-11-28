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
 * Turns figures keyed by case type into the list an object table reads, and
 * into the quarterly CSV; reads the counted figures and the average duration
 * of one case type's tally.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
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

	/**
	 * The quarterly CSV of the per-type figures: the header, then one line per case type.
	 *
	 * @param array<string|int, mixed> $perType The figures keyed by case type.
	 *
	 * @return string The CSV.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function csv(array $perType): string {
		$lines = [implode(',', $this->csvHeader())];
		foreach ($perType as $type => $row) {
			$lines[] = $this->csvLine(type: (string)$type, row: $row);
		}

		return implode("\n", $lines);
	}//end csv()

	/**
	 * The counted figures of one zaaktype's aggregate.
	 *
	 * @param array<string, mixed> $b         The raw tally, with the number of terms counted as `totaal`.
	 * @param float|null           $binnenPct The share of decided terms that were met.
	 *
	 * @return array<string, mixed> The counted figures.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function counts(array $b, ?float $binnenPct): array {
		return [
			'verlengingen' => $b['verlengingen'],
			'overschrijdingen' => $b['overschrijdingen'],
			'ingebrekestellingen' => $b['ingebrekestellingen'],
			'dwangsomTotalCents' => $b['dwangsomTotalCents'],
			// The listing selects terms by start date in the quarter, so every
			// counted term arrived in it.
			'received' => $b['totaal'],
			'met' => $b['met'],
			'missed' => $b['missed'],
			'running' => $b['running'],
			'suspended' => $b['suspended'],
			'metShare' => $binnenPct,
		];
	}//end counts()

	/**
	 * The average of the durations in days, to one decimal; 0.0 when there are none.
	 *
	 * @param array<int, int|float> $durations The durations in days.
	 *
	 * @return float The average.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function averageDays(array $durations): float {
		$avg = 0.0;

		$count = count($durations);
		if ($count > 0) {
			$avg = round(array_sum($durations) / $count, 1);
		}

		return $avg;
	}//end averageDays()

	/**
	 * The columns of the quarterly CSV.
	 *
	 * @return array<int, string> The header.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function csvHeader(): array {
		return [
			'caseType',
			'totaal',
			'binnenTermijnPct',
			'gemiddeldeDoorlooptijdDagen',
			'verlengingen',
			'overschrijdingen',
			'ingebrekestellingen',
			'dwangsomTotalCents',
		];
	}//end csvHeader()

	/**
	 * One case type's line of the quarterly CSV.
	 *
	 * @param string $type The case type.
	 * @param mixed  $row  Its tally.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-09-reporting-dashboard/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function csvLine(string $type, $row): string {
		$line = [
			$type,
			(string)($row['totaal'] ?? 0),
			(string)($row['binnenTermijnPct'] ?? ''),
			(string)($row['gemiddeldeDoorlooptijdDagen'] ?? 0),
			(string)($row['verlengingen'] ?? 0),
			(string)($row['overschrijdingen'] ?? 0),
			(string)($row['ingebrekestellingen'] ?? 0),
			(string)($row['dwangsomTotalCents'] ?? 0),
		];

		return implode(',', $line);
	}//end csvLine()
}//end class
