<?php

/**
 * Dossiq CSV writer
 *
 * One CSV line per row: every cell quoted (RFC 4180), and a cell that starts
 * like a formula prefixed with `'` so a spreadsheet shows it rather than runs
 * it. The same neutralising rule as nextcloud-vue's rowsToCsv, so a download
 * from either side of the app reads the same.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Csv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/doorlooptijd-csv/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Csv;

/**
 * Writes rows as CSV text.
 *
 * @spec openspec/specs/doorlooptijd-csv/spec.md
 */
class CsvWriter {

	/**
	 * The characters that make a spreadsheet read a cell as a formula.
	 */
	private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

	/**
	 * One CSV line.
	 *
	 * @param list<string|int|float|null> $cells The cells, in column order.
	 *
	 * @return string The line, without its line ending.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md
	 */
	public function line(array $cells): string {
		$out = [];
		foreach ($cells as $cell) {
			$out[] = '"' . str_replace('"', '""', $this->neutralise(cell: (string)($cell ?? ''))) . '"';
		}

		return implode(',', $out);
	}//end line()

	/**
	 * A header line and the rows, each line ended with CRLF.
	 *
	 * @param list<string> $header The column labels.
	 * @param list<list<string|int|float|null>> $rows The rows.
	 *
	 * @return string The CSV text.
	 *
	 * @spec openspec/specs/doorlooptijd-csv/spec.md
	 */
	public function document(array $header, array $rows): string {
		$lines = [$this->line(cells: $header)];
		foreach ($rows as $row) {
			$lines[] = $this->line(cells: $row);
		}

		return implode("\r\n", $lines) . "\r\n";
	}//end document()

	/**
	 * A cell that starts like a formula, prefixed with `'`.
	 *
	 * @param string $cell The cell text.
	 *
	 * @return string The cell, safe to open in a spreadsheet.
	 */
	private function neutralise(string $cell): string {
		if ($cell !== '' && in_array($cell[0], self::FORMULA_LEADS, true) === true) {
			return "'" . $cell;
		}

		return $cell;
	}//end neutralise()
}//end class
