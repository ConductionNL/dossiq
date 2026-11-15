<?php

/**
 * Unit test for ResidentPortal.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
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

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\ResidentPortal;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Portal\ResidentPortal
 */
class ResidentPortalTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$collections = ['collections' => 0];
		$actions = ['actions' => 1];
		$group = 'group-value';
		$labels = ['labels' => 3];

		$value = new ResidentPortal(
			collections: $collections,
			actions: $actions,
			group: $group,
			labels: $labels,
		);

		$this->assertSame(expected: $collections, actual: $value->collections);
		$this->assertSame(expected: $actions, actual: $value->actions);
		$this->assertSame(expected: $group, actual: $value->group);
		$this->assertSame(expected: $labels, actual: $value->labels);
	}//end testEveryPropertyReadsBack()
}//end class
