<?php

/**
 * The people on the Jeugdwet example case, as DqSociaalDomein's Wie card draws
 * them: Fatma Kaya as "regisseur", her team shown apart from her role.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 * @spec exclude package example data; no requirement covers the example roles
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The example roles on the Jeugdwet case.
 */
class JeugdwetExampleRolesTest extends TestCase {

	/**
	 * Fatma Kaya's role reads as the board words it.
	 *
	 * @return void
	 */
	public function testFatmaIsTheRegisseur(): void {
		$people = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/packages/jeugdwet/examples/40-people.json'), true);
		$byName = array_column($people['rows'], 'description', 'name');

		$this->assertSame('regisseur', $byName['Fatma Kaya']);
	}//end testFatmaIsTheRegisseur()
}//end class
