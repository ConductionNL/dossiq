<?php

/**
 * Example data for the case lists and pager boards: permit and subsidy cases
 * are handled in Vergunningen, not by the traffic adviser Lars de Koning.
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
 * Who handles the permit and subsidy cases.
 */
class ZuiddrechtHandlersFitTheirTeamTest extends TestCase {

	/**
	 * Lars advises on traffic; he handles no case of this fragment.
	 *
	 * @return void
	 */
	public function testTheTrafficAdviserHandlesNoCase(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		foreach ($objects as $key => $object) {
			if ($object['schema'] === 'case') {
				$this->assertNotSame(expected: '{{cast:lars.uid}}', actual: ($object['data']['assignee'] ?? ''), message: $key);
			}
		}

		foreach (['subsidie-buurtfeest-oosthaven', 'besloten-subsidie-jeugdtheater'] as $key) {
			$this->assertSame(
				expected: ['{{cast:mehmet.uid}}', 'zuiddrecht-vergunningen'],
				actual: [$objects[$key]['data']['assignee'], $objects[$key]['data']['assignedGroup']]
			);
		}
	}//end testTheTrafficAdviserHandlesNoCase()
}//end class
