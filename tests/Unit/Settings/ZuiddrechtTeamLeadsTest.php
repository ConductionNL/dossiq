<?php

/**
 * The team leads DqAfdelingen names under a team's name: Marleen Postma for
 * Vergunningen and Anouk Bakker for Woo en informatie.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude example set data; no requirement covers the example team leads
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The example team leads.
 */
class ZuiddrechtTeamLeadsTest extends TestCase {

	/**
	 * Both teams name their lead.
	 *
	 * @return void
	 */
	public function testTheTeamsNameTheirLead(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$changes = array_column($set['changes'], null, 'key');
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertSame('{{cast:marleen.uid}}', $changes['team-vergunningen-in-de-boom']['set']['teamLead']);
		$this->assertSame('{{cast:anouk.uid}}', $objects['afdeling-informatie-en-woo']['teamLead']);
	}//end testTheTeamsNameTheirLead()
}//end class
