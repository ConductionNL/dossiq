<?php

/**
 * Unit tests for QuarterPeriod: a quarter is read from YYYY-Qn or defaults to
 * the last full quarter, knows its first and last day and the twelve months
 * ending with it, and holds the dates inside it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Complaint;

use DateTimeImmutable;
use OCA\Dossiq\Service\Complaint\QuarterPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Complaint\QuarterPeriod
 */
class QuarterPeriodTest extends TestCase {

	public function testAQuarterIsReadFromItsParameter(): void {
		$period = QuarterPeriod::fromParam(' 2026-Q3 ');

		$this->assertSame([2026, 3], [$period->year, $period->quarter]);
		$this->assertNull(QuarterPeriod::fromParam('2026-Q5'));
		$this->assertNull(QuarterPeriod::fromParam('2026Q1'));
		$this->assertNull(QuarterPeriod::fromParam('Q1-2026'));
		$this->assertNull(QuarterPeriod::fromParam('2026-q1'));
	}//end testAQuarterIsReadFromItsParameter()

	public function testWithoutAParameterTheLastFullQuarterIsUsed(): void {
		$autumn = QuarterPeriod::fromParam('', new DateTimeImmutable('2026-11-12'));
		$winter = QuarterPeriod::fromParam('', new DateTimeImmutable('2026-02-01'));

		$this->assertSame([2026, 3], [$autumn->year, $autumn->quarter]);
		$this->assertSame([2025, 4], [$winter->year, $winter->quarter]);
		$this->assertInstanceOf(QuarterPeriod::class, QuarterPeriod::fromParam(''));
	}//end testWithoutAParameterTheLastFullQuarterIsUsed()

	public function testTheFirstAndLastDayOfEachQuarter(): void {
		$days = static fn (int $year, int $quarter): array => [(new QuarterPeriod($year, $quarter))->firstDay(), (new QuarterPeriod($year, $quarter))->lastDay()];

		$this->assertSame(['2024-01-01', '2024-03-31'], $days(2024, 1));
		$this->assertSame(['2024-04-01', '2024-06-30'], $days(2024, 2));
		$this->assertSame(['2024-07-01', '2024-09-30'], $days(2024, 3));
		$this->assertSame(['2024-10-01', '2024-12-31'], $days(2024, 4));
	}//end testTheFirstAndLastDayOfEachQuarter()

	public function testTheTwelveMonthsEndWithTheQuarter(): void {
		$this->assertSame(
			['2025-04', '2025-05', '2025-06', '2025-07', '2025-08', '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'],
			(new QuarterPeriod(2026, 1))->trailingMonths()
		);
	}//end testTheTwelveMonthsEndWithTheQuarter()

	public function testAQuarterHoldsTheDatesInsideItByDay(): void {
		$period = new QuarterPeriod(2026, 3);

		$this->assertTrue($period->holds('2026-07-01'));
		$this->assertTrue($period->holds('2026-09-30T23:59:00+02:00'));
		$this->assertFalse($period->holds('2026-06-30'));
		$this->assertFalse($period->holds('2026-10-01'));
		$this->assertFalse($period->holds(''));
	}//end testAQuarterHoldsTheDatesInsideItByDay()
}//end class
