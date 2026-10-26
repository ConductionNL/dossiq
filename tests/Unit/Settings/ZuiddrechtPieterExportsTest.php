<?php

/**
 * Example data for DqZoekresultaten's "Uw exports": Pieter's two exports, a
 * spreadsheet and a CSV file, each a real file with the export run that names
 * it (openregister's example-set `exportRuns` part).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Pieter's exports.
 *
 * @spec openspec/changes/example-set-export-runs/specs/example-sets/spec.md#requirement-a-fragment-may-give-an-account-an-export-it-made
 */
class ZuiddrechtPieterExportsTest extends TestCase {

	/**
	 * Two exports for Pieter, the spreadsheet a readable workbook, both made before the install day.
	 *
	 * @return void
	 */
	public function testPieterHasHisTwoExports(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$exports = array_column(array_column($set['exportRuns'], 'data'), null, 'fileName');

		$this->assertSame(expected: ['zaken-verlichting.xlsx', 'woo-2026-q3.csv'], actual: array_keys($exports));
		foreach ($exports as $export) {
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $export['user']);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{datetime:-\d+ \d\d:\d\d\}\}$/', string: $export['producedAt']);
		}

		$bytes = base64_decode($exports['zaken-verlichting.xlsx']['contentBase64'], true);
		$this->assertNotFalse($bytes);
		$path = tempnam(sys_get_temp_dir(), 'xlsx');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$this->assertStringContainsString(needle: 'Verlichting fietspad Lindelaan', haystack: (string)$zip->getFromName('xl/worksheets/sheet1.xml'));
		$zip->close();
		unlink($path);
	}//end testPieterHasHisTwoExports()
}//end class
