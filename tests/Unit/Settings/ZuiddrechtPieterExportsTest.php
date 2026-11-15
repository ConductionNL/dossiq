<?php

/**
 * Pieter's two exports on DqZoekresultaten "Uw exports": the street-lighting
 * cases made yesterday and downloaded three times, and the Woo quarter made a
 * week ago, downloaded once, which expires today (exports are kept seven days).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example exports
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pieter's exports.
 */
class ZuiddrechtPieterExportsTest extends TestCase {

	/**
	 * When each was made and how often it was downloaded.
	 *
	 * @return void
	 */
	public function testTheExportsAreDatedAsTheBoardDraws(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$runs = array_column($set['exportRuns'], 'data', 'key');

		$this->assertSame(['{{datetime:-1 16:20}}', 3], [$runs['export-zaken-verlichting']['producedAt'], $runs['export-zaken-verlichting']['downloadCount']]);
		$this->assertSame(['{{datetime:-7 23:30}}', 1], [$runs['export-woo-2026-q3']['producedAt'], $runs['export-woo-2026-q3']['downloadCount']]);
	}//end testTheExportsAreDatedAsTheBoardDraws()

	/**
	 * DqFilterInMenu draws Anouk's shared view with who made it and for which team under its name.
	 *
	 * @return void
	 */
	public function testAnouksSharedViewSaysWhoMadeIt(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$views = array_column($set['views'], 'data', 'key');

		$this->assertSame('Door Anouk Bakker, team Woo en informatie', $views['anouk-woo-verzoeken-team']['description'] ?? null);
	}//end testAnouksSharedViewSaysWhoMadeIt()
}//end class
