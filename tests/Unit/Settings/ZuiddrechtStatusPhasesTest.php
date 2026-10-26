<?php

/**
 * Every status the example set declares before a case ends names its phase,
 * as the shipped status types do, so DqProcesanalyse's Dagen per fase counts
 * the set's own case types too.
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
	 * A non-final status names one of the five phases.
	 *
	 * @return void
	 */
	public function testEveryOpenStatusNamesItsPhase(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'statusType' && ($object['data']['isFinal'] ?? false) === false) {
				$this->assertContains($object['data']['phase'] ?? null, ['Ontvangst', 'Beoordeling', 'Advies', 'Besluit', 'Verzending'], $object['key']);
			}
		}
	}//end testEveryOpenStatusNamesItsPhase()
}//end class
