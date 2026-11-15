<?php

/**
 * Example data for DqVthInstellingen's inspection checklists: Brandveiligheid
 * version 3, Bouwtoezicht oplevering version 2 and an Asbest concept.
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
 * The checklists the VTH settings list.
 */
class ZuiddrechtVthChecklistsTest extends TestCase {

	/**
	 * The set writes the Asbest concept and renames the shipped oplevering checklist to its second version.
	 *
	 * @return void
	 */
	public function testTheBoardsThreeChecklists(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$fire = $objects['inspectie-checklist-brandveiligheid']['data'];
		$this->assertSame(expected: [3, 'active'], actual: [$fire['version'], $fire['status']]);

		$asbestos = $objects['inspectie-checklist-asbest']['data'];
		$this->assertSame(
			expected: ['Asbest', 1, 'draft', false],
			actual: [$asbestos['name'], $asbestos['version'], $asbestos['status'], $asbestos['active']]
		);

		$change = array_column($set['changes'], null, 'key')['checklist-oplevering-versie-2'];
		$this->assertSame(expected: ['seedKey' => 'bouwtoezicht-fase-3-oplevering'], actual: $change['where']);
		$this->assertSame(expected: ['name' => 'Bouwtoezicht oplevering', 'version' => 2], actual: $change['set']);
	}//end testTheBoardsThreeChecklists()
}//end class
