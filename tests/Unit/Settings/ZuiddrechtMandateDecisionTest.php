<?php

/**
 * The mandate decision DqBeheerGeavanceerd's Mandaatmatrix lists, by the name
 * the board gives it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example mandate decision
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The mandate decision's name.
 */
class ZuiddrechtMandateDecisionTest extends TestCase {

	/**
	 * It is the municipality's mandate decision for 2026, determined.
	 *
	 * @return void
	 */
	public function testTheMandateDecisionIsNamedAsTheBoardDraws(): void {
		$set      = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$decision = array_column($set['objects'], 'data', 'key')['mandaatbesluit-vth-vergunningverlening'];

		$this->assertSame(['Mandaatbesluit Zuiddrecht 2026', 'determined'], [$decision['decisionName'], $decision['status']]);
	}//end testTheMandateDecisionIsNamedAsTheBoardDraws()
}//end class
