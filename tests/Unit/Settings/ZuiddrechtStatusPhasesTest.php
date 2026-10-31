<?php

/**
 * The Adviesaanvraag case type the example set declares names the phase of
 * each open status, as the shipped status types do, so DqProcesanalyse's Dagen
 * per fase counts it too.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; the phase vocabulary is the shipped status types'
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The phases of the set's status types.
 */
class ZuiddrechtStatusPhasesTest extends TestCase {

	/**
	 * Ontvangen is Ontvangst, In behandeling is Beoordeling.
	 *
	 * @return void
	 */
	public function testTheAdviceRequestStatusesNameTheirPhase(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertSame(
			['Ontvangst', 'Beoordeling'],
			[$objects['status-adviesaanvraag-ontvangen']['phase'] ?? null, $objects['status-adviesaanvraag-in-behandeling']['phase'] ?? null]
		);
	}//end testTheAdviceRequestStatusesNameTheirPhase()
}//end class
