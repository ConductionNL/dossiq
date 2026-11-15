<?php

/**
 * The bodies the process-mining report answers with.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use OCA\Dossiq\Kernel\Contracts\ReportAnswerInterface;
use OCA\Dossiq\Service\Csv\CsvWriter;
use OCP\IL10N;

/**
 * Shapes a report as JSON, whole or narrowed to one view, or as the days per
 * phase CSV.
 *
 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
 */
final class ReportAnswer implements ReportAnswerInterface {

	/**
	 * Constructor.
	 *
	 * @param ReportViews $views     Shapes the display blocks.
	 * @param IL10N       $l10n      The translations, for the download's column labels.
	 * @param CsvWriter   $csvWriter Writes the download.
	 */
	public function __construct(
		private readonly ReportViews $views,
		private readonly IL10N $l10n,
		private readonly CsvWriter $csvWriter=new CsvWriter(),
	) {
	}//end __construct()

	/**
	 * The report with every display block, or narrowed to one view.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param mixed                $view   The requested view, when any.
	 *
	 * @return array<string, mixed> The JSON body.
	 *
	 * @spec openspec/changes/archive/2026-07-14-process-mining-bottlenecks/tasks.md#T02
	 */
	public function json(array $report, mixed $view): array {
		if (is_string($view) === true && $view !== '') {
			return [
				'period' => ($report['period'] ?? null),
				'clock' => ($report['clock'] ?? null),
				'truncated' => ($report['truncated'] ?? null),
				...$this->views->shape(report: $report, view: $view),
			];
		}

		return [...$report, ...$this->views->shape(report: $report, view: null)];
	}//end json()

	/**
	 * The days per phase per case type of one statistic as CSV: a column per
	 * phase, a row per case type, a phase the case type never passes in words.
	 *
	 * @param array<string, mixed> $report    The report.
	 * @param string               $statistic The statistic, mean or median.
	 *
	 * @return string The CSV, with a byte order mark so a spreadsheet reads it as UTF-8.
	 *
	 * @spec openspec/specs/procesanalyse-csv/spec.md#requirement-the-days-per-phase-download-as-csv
	 */
	public function csv(array $report, string $statistic): string {
		$matrix = (array)($report['phaseDays'][$statistic] ?? []);
		$none = $this->l10n->t('none');
		$rows = [];
		foreach ((array)($matrix['rows'] ?? []) as $row) {
			$cells = [(string)($row['label'] ?? '')];
			foreach ((array)($row['values'] ?? []) as $value) {
				if ($value === 'none') {
					$value = $none;
				}

				$cells[] = $value;
			}

			$rows[] = $cells;
		}

		$header = [$this->l10n->t('Case type'), ...array_map('strval', (array)($matrix['columns'] ?? []))];

		return "\u{FEFF}".$this->csvWriter->document(header: $header, rows: $rows);
	}//end csv()
}//end class
