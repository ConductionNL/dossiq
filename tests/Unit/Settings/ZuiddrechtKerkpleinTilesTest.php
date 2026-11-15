<?php

/**
 * Example data for DqZakenKaart and the case lists: an anonymous report of
 * loose paving stones at Kerkplein 7, received on the install day.
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
 * The Kerkplein paving report.
 */
class ZuiddrechtKerkpleinTilesTest extends TestCase {

	/**
	 * Anonymous, open, on the map in Zuiddrecht, with Handhaving.
	 *
	 * @return void
	 */
	public function testAnAnonymousOpenReportOnTheMap(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$case = array_column($set['objects'], null, 'key')['melding-stoeptegels-kerkplein'];

		$this->assertSame(expected: 'Melding losse stoeptegels Kerkplein', actual: $case['data']['title']);
		$this->assertArrayNotHasKey(key: 'requester', array: $case['data']);
		$this->assertSame(expected: 'Ontvangen', actual: $case['lookups']['status']['where']['name']);
		$this->assertSame(expected: 'zuiddrecht-toezicht-en-handhaving', actual: $case['data']['assignedGroup']);
		[$lon, $lat] = json_decode($case['data']['geometry'], true)['coordinates'];
		$this->assertTrue(condition: ($lon > 4.6 && $lon < 4.7 && $lat > 51.8 && $lat < 51.83));
	}//end testAnAnonymousOpenReportOnTheMap()
}//end class
