<?php

/**
 * Procest mandaat CSV parser.
 *
 * Turns a Decidesk CSV export into data rows and parses the individual cell
 * dialects it uses: `1/true/ja/yes/y` for a boolean and a semicolon-separated
 * list for a multi-value column. Split out of MandaatImportService so that
 * service keeps the import *decision* — what is new, changed or removed
 * against the prior besluit version — while the wire format it happens to
 * arrive in is parsed here.
 *
 * The required-column check lives here too, and it fails loudly: an export
 * missing a mandatory column must abort the import rather than silently
 * produce mandaten with empty fields.
 *
 * @category Service
 * @package  OCA\Procest\Service\Mandaat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Mandaat;

use RuntimeException;

/**
 * Parses the Decidesk mandaat CSV export and its cell dialects.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
class MandaatCsvParser {
	/**
	 * Columns an import CSV must carry.
	 *
	 * These are CSV HEADERS, not schema property names — an external input
	 * contract that operators' existing files already use. They deliberately do
	 * NOT move with the mandaat -> mandate rename; MandaatImportService maps
	 * them onto the renamed properties and accepts either spelling. Renaming
	 * them here would reject every import file in the field.
	 *
	 * @var string[]
	 */
	public const REQUIRED_COLUMNS = ['mandaatNummer', 'omschrijving', 'roleName', 'plafondCents'];

	/**
	 * Values a boolean CSV cell may carry for "true".
	 *
	 * @var string[]
	 */
	private const TRUTHY_VALUES = ['1', 'true', 'ja', 'yes', 'y'];

	/**
	 * Parse RFC-4180-ish CSV with first row = header.
	 *
	 * @param string $csv CSV.
	 *
	 * @return array<int, array<string, string>> The data rows keyed by header name.
	 *
	 * @throws RuntimeException When a required column is missing from the header.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function parse(string $csv): array {
		$lines = preg_split('/\r\n|\n|\r/', trim($csv));
		if ($lines === false || count($lines) < 2) {
			return [];
		}

		$header = str_getcsv($lines[0]);
		$missing = array_diff(self::REQUIRED_COLUMNS, $header);
		if (count($missing) > 0) {
			throw new RuntimeException('Missing required CSV columns: ' . implode(', ', $missing));
		}

		$rows = [];
		$lineCount = count($lines);
		for ($i = 1; $i < $lineCount; $i++) {
			$line = trim((string)$lines[$i]);
			if ($line === '') {
				continue;
			}

			$values = str_getcsv($line);
			$rows[] = array_combine($header, array_pad($values, count($header), ''));
		}

		return $rows;
	}//end parse()

	/**
	 * Parse a boolean from CSV text.
	 *
	 * @param string $value Boolean text.
	 *
	 * @return bool True for `1`, `true`, `ja`, `yes` or `y` (case-insensitive).
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function parseBool(string $value): bool {
		return in_array(strtolower(trim($value)), self::TRUTHY_VALUES, true);
	}//end parseBool()

	/**
	 * Parse a semicolon-separated list from CSV text.
	 *
	 * @param string $value Semicolon-separated list.
	 *
	 * @return array<int, string> The trimmed, non-empty entries.
	 *
	 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
	 */
	public function parseList(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode(';', $value))));
	}//end parseList()
}//end class
