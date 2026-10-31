<?php

/**
 * Example data for DqZaakDialogen "Flow starten": the Woo case type lists
 * three flows a handler may start, which the set declares.
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
 * The flows the Woo case type allows.
 */
class ZuiddrechtWooStartableFlowsTest extends TestCase {

	/**
	 * The change on the Woo case type lists exactly the three flows of the
	 * fragment, by the names the dialog shows.
	 *
	 * @return void
	 */
	public function testTheWooCaseTypeListsTheThreeFlows(): void {
		$set   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$flows = array_column($set['flows'], 'data', 'key');

		$this->assertSame(
			expected: ['Zienswijze derde vragen', 'Documenten opvragen bij een afdeling', 'Verdaging aankondigen'],
			actual: array_values(array_column($flows, 'name'))
		);

		$change = array_column($set['changes'], null, 'key')['woo-verzoek-startbare-flows'];
		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $change['where']);
		$this->assertSame(
			expected: array_map(static fn (string $key): string => '{{flow:'.$key.'.uuid}}', array_keys($flows)),
			actual: $change['set']['startableFlows']
		);

		foreach ($flows as $key => $flow) {
			$types = array_column($flow['nodes'], 'type');
			$this->assertSame(expected: 'openregister.trigger-manual', actual: $types[0], message: $key);
			$this->assertSame(expected: 'openregister.end', actual: end($types), message: $key);
		}
	}//end testTheWooCaseTypeListsTheThreeFlows()
}//end class
