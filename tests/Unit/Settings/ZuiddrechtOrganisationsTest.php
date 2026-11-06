<?php

/**
 * Example data for DqOrganisaties: every organisation of the Zuiddrecht cast
 * is a company the organisations list shows.
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
 * The 23 organisations of Zuiddrecht.
 */
class ZuiddrechtOrganisationsTest extends TestCase {

	/**
	 * One company per cast organisation, every field quoted from that one cast entry.
	 *
	 * @return void
	 */
	public function testEveryCastOrganisationIsACompanyOnce(): void {
		$set  = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$cast = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] !== 'kvkCompany') {
				continue;
			}

			$found = preg_match('/^\{\{cast:([a-z]+)\.kvk\}\}$/', $object['data']['kvkNumber'], $match);
			$this->assertSame(expected: 1, actual: $found, message: $object['key']);
			$this->assertSame(expected: '{{cast:'.$match[1].'.name}}', actual: $object['data']['tradeName'], message: $object['key']);
			$this->assertSame(expected: '{{cast:'.$match[1].'.address.city}}', actual: $object['data']['address']['place'], message: $object['key']);
			$cast[] = $match[1];
		}

		$this->assertCount(expectedCount: 23, haystack: $cast);
		$this->assertSame(expected: $cast, actual: array_values(array_unique($cast)));
	}//end testEveryCastOrganisationIsACompanyOnce()
}//end class
