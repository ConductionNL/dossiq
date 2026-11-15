<?php

/**
 * Unit test for DecisionSchedule.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Money
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

namespace OCA\Dossiq\Tests\Unit\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\DecisionSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Every property reads back what the constructor was given.
 *
 * @covers \OCA\Dossiq\Service\Money\DecisionSchedule
 */
class DecisionScheduleTest extends TestCase {
	/**
	 * Each property reads back unchanged.
	 *
	 * @return void
	 */
	public function testEveryPropertyReadsBack(): void {
		$decision = ['id' => 'decision-1'];
		$schedule = ['instalments' => [['amountCents' => 100]]];
		$today = new DateTimeImmutable('2026-11-15');

		$value = new DecisionSchedule(decision: $decision, schedule: $schedule, today: $today);

		$this->assertSame(expected: $decision, actual: $value->decision);
		$this->assertSame(expected: $schedule, actual: $value->schedule);
		$this->assertSame(expected: $today, actual: $value->today);
	}//end testEveryPropertyReadsBack()
}//end class
