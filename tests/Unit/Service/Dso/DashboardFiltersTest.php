<?php

/**
 * Unit test for DashboardFilters.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Service\Dso\DashboardFilters;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Dso\DashboardFilters
 */
class DashboardFiltersTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$activiteitgroep = 'activiteitgroep-value';
		$regelkwalificatie = 'regelkwalificatie-value';
		$location = 'location-value';

		$value = new DashboardFilters(
			activiteitgroep: $activiteitgroep,
			regelkwalificatie: $regelkwalificatie,
			location: $location,
		);

		$this->assertSame(expected: $activiteitgroep, actual: $value->activiteitgroep);
		$this->assertSame(expected: $regelkwalificatie, actual: $value->regelkwalificatie);
		$this->assertSame(expected: $location, actual: $value->location);
	}//end testEveryPropertyReadsBack()
}//end class
