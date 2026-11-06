<?php

/**
 * Example data for DqZakenKaart: the open cases the map draws stand in
 * Zuiddrecht, not at a test coordinate.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Where the example cases are.
 */
class ZuiddrechtCasePlacesTest extends TestCase {

	/**
	 * Every case with a place is a GeoJSON point inside Zuiddrecht, and the
	 * board's Molenweg, binnenstad, Lindelaan and Oosthaven cases have one.
	 *
	 * @return void
	 */
	public function testThePlacesAreInZuiddrecht(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$placed = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'case' || isset($object['data']['geometry']) === false) {
				continue;
			}

			$point = json_decode($object['data']['geometry'], true);
			$this->assertSame(expected: 'Point', actual: $point['type'], message: $object['key']);
			[$lon, $lat] = $point['coordinates'];
			$this->assertTrue(condition: ($lon > 4.6 && $lon < 4.7 && $lat > 51.8 && $lat < 51.83), message: $object['key']);
			$placed[] = $object['key'];
		}

		foreach (['kapvergunning-molenweg', 'woo-parkeervergunningen-binnenstad', 'melding-afval-lindelaan', 'subsidie-buurtfeest-oosthaven'] as $key) {
			$this->assertContains(needle: $key, haystack: $placed);
		}
	}//end testThePlacesAreInZuiddrecht()
}//end class
