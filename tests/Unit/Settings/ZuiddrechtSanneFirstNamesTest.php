<?php

/**
 * Example data for DqContact: Sanne de Vries's person record carries her BRP
 * first names, "Sanne Johanna", from the cast (openregister
 * feat/zuiddrecht-cast-sanne-first-names), where it carried only "Sanne".
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
 * Sanne's first names on her person record.
 */
class ZuiddrechtSanneFirstNamesTest extends TestCase {

	/**
	 * The person record quotes her first names, not her given name.
	 *
	 * @return void
	 */
	public function testSannesPersonRecordCarriesHerFirstNames(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertSame(expected: '{{cast:sanne.firstNames}}', actual: $objects['persoon-sanne']['name']['givenNames']);
	}//end testSannesPersonRecordCarriesHerFirstNames()
}//end class
