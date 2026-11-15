<?php

/**
 * Example data for IqGesynchroniseerdVan: a melding that reaches dossiq from
 * another case system through an Integriq synchronization. integriq's
 * fragment finds this case by its title and writes the synchronization
 * contract that points at it, so the case's "Gesynchroniseerd van" tab has
 * something to show.
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
 * The synchronized melding.
 */
class ZuiddrechtSynchronizedCaseTest extends TestCase {

	/**
	 * The melding came in through the API, is in progress, and its title is
	 * unique in the set so a lookup on it finds one case.
	 *
	 * @return void
	 */
	public function testTheSynchronizedMeldingIsInProgress(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$cases = array_values(
			array_filter(
				$set['objects'],
				fn (array $o): bool => $o['schema'] === 'case' && ($o['data']['title'] ?? '') === 'Melding afval Lindelaan'
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $cases);
		$this->assertSame(expected: 'melding-afval-lindelaan', actual: $cases[0]['key']);
		$this->assertSame(expected: 'zgw-api', actual: $cases[0]['data']['intakeChannel']);
		$this->assertSame(expected: ['identifier' => 'melding-openbare-ruimte'], actual: $cases[0]['lookups']['caseType']['where']);
		$this->assertSame(expected: 'In behandeling', actual: $cases[0]['lookups']['status']['where']['name']);
	}//end testTheSynchronizedMeldingIsInProgress()
}//end class
