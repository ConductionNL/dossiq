<?php

/**
 * Unit tests for ProcessingTimeCalculator::percent(): a share is a rounded
 * whole percentage, and there is none of nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Reporting\ProcessingTimeCalculator
 */
class ProcessingTimePercentTest extends TestCase {

	public function testAShareIsARoundedWholePercentage(): void {
		$calculator = new ProcessingTimeCalculator();

		$this->assertSame(67, $calculator->percent(2, 3));
		$this->assertSame(33, $calculator->percent(1, 3));
		$this->assertSame(50, $calculator->percent(1, 2));
		$this->assertSame(100, $calculator->percent(7, 7));
		$this->assertSame(0, $calculator->percent(0, 5));
		$this->assertSame(150, $calculator->percent(3, 2));
	}//end testAShareIsARoundedWholePercentage()

	public function testThereIsNoShareOfNothing(): void {
		$this->assertNull((new ProcessingTimeCalculator())->percent(0, 0));
		$this->assertNull((new ProcessingTimeCalculator())->percent(3, 0));
	}//end testThereIsNoShareOfNothing()
}//end class
