<?php

/**
 * Unit test for SummaryTarget.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SummaryTarget;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Repair\SummaryTarget
 */
class SummaryTargetTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$objectService = new \stdClass();
		$target = ['target' => 1];

		$value = new SummaryTarget(
			objectService: $objectService,
			target: $target,
		);

		$this->assertSame(expected: $objectService, actual: $value->objectService);
		$this->assertSame(expected: $target, actual: $value->target);
	}//end testEveryPropertyReadsBack()
}//end class
