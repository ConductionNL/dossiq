<?php

/**
 * The Woo request's result types name their VNG Selectielijst row by its URL,
 * so the Archiving card can read "6.1, Ingewilligd" (board DqZaakArchivering),
 * at a version above the one installs already hold, so a re-import updates them.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Selectielijst rows of the Woo result types, both versions.
 */
class WooResultTypesSelectionListTest extends TestCase {

	private const ROW = 'https://selectielijst.openzaak.nl/api/v1/resultaten/';

	/**
	 * Every Woo result type names a Selectielijst 2020 row of "Verzoeken behandelen".
	 *
	 * @return void
	 */
	public function testEveryResultTypeNamesItsRow(): void {
		$expected = [
			'Openbaar gemaakt' => self::ROW.'3fc7002b-31b6-4abe-82af-b6d46a9a1736',
			'Deels openbaar gemaakt' => self::ROW.'3fc7002b-31b6-4abe-82af-b6d46a9a1736',
			'Niet openbaar gemaakt' => self::ROW.'9d6e6675-8764-4c55-9a47-626c2dc399f8',
			'Ingetrokken' => self::ROW.'6f5d3859-78c5-467a-9e2f-43d71cf52a6c',
		];
		foreach (['81-woo-verzoek.json', '81-woo-verzoek-version-2.json'] as $file) {
			$fragment = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/'.$file), true);
			$rows     = [];
			$periods  = [];
			foreach ($fragment['components']['objects'] as $object) {
				if (($object['@self']['schema'] ?? '') === 'resultType') {
					$rows[$object['name']] = ($object['selectionListClass'] ?? null);
					$periods[$object['name']] = ($object['archivalPeriod'] ?? null);
					// OpenRegister updates a shipped object only when its version rises.
					$this->assertSame(expected: '1.1.0', actual: ($object['@self']['version'] ?? null), message: $object['@self']['slug']);
				}
			}

			$this->assertSame(expected: $expected, actual: $rows, message: $file);
			// The retention period is the one the Selectielijst row prescribes.
			$this->assertSame(
				expected: ['Openbaar gemaakt' => 'P5Y', 'Deels openbaar gemaakt' => 'P5Y', 'Niet openbaar gemaakt' => 'P1Y', 'Ingetrokken' => 'P1Y'],
				actual: $periods,
				message: $file
			);
		}
	}//end testEveryResultTypeNamesItsRow()
}//end class
