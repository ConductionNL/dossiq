<?php

/**
 * Example data for DqPersoonlijkeInstellingen: Pieter has registered Anouk
 * Bakker as his substitute for a coming week of leave, so the Waarneming
 * section lists one planned substitution with Intrekken, as the board draws it.
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
 * Pieter's own planned substitution.
 */
class ZuiddrechtPieterPlannedSubstitutionTest extends TestCase {

	/**
	 * Anouk covers all of Pieter's work during a week of leave that has not started yet.
	 *
	 * @return void
	 */
	public function testPieterHasAPlannedSubstitutionByAnouk(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$row = ($objects['waarneming-anouk-voor-pieter'] ?? null);
		$this->assertNotNull(actual: $row);
		$this->assertSame(expected: 'substitution', actual: $row['schema']);
		$this->assertSame(
			expected: [
				'absentee'   => '{{cast:pieter.uid}}',
				'substitute' => '{{cast:anouk.uid}}',
				'startDate'  => '{{date:8}}',
				'endDate'    => '{{date:12}}',
				'scope'      => 'all',
				'reason'     => 'verlof',
				'status'     => 'active',
				'comment'    => 'Vakantie',
				'createdBy'  => '{{cast:pieter.uid}}',
			],
			actual: $row['data']
		);
	}//end testPieterHasAPlannedSubstitutionByAnouk()
}//end class
