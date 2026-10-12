<?php

/**
 * DayCount unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Time
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Time;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Dossiq\Service\Time\DayCount;
use PHPUnit\Framework\TestCase;

/**
 * The two day counts, and the difference between them.
 *
 * @covers \OCA\Dossiq\Service\Time\DayCount
 */
class DayCountTest extends TestCase {
	/**
	 * A span is the same number whichever way round it is asked.
	 *
	 * @return void
	 */
	public function testBetweenIsNeverNegative(): void {
		$count = new DayCount();
		$early = new DateTimeImmutable('2026-03-02');
		$late = new DateTimeImmutable('2026-03-12');

		$this->assertSame(expected: 10, actual: $count->between(from: $early, to: $late));
		$this->assertSame(expected: 10, actual: $count->between(from: $late, to: $early));
		$this->assertSame(expected: 0, actual: $count->between(from: $early, to: $early));
	}//end testBetweenIsNeverNegative()

	/**
	 * Days left are negative once the deadline has passed.
	 *
	 * @return void
	 */
	public function testSignedCarriesTheDirection(): void {
		$count = new DayCount();
		$today = new DateTimeImmutable('2026-03-10');

		$this->assertSame(expected: 5, actual: $count->signed(from: $today, to: new DateTimeImmutable('2026-03-15')));
		$this->assertSame(expected: -3, actual: $count->signed(from: $today, to: new DateTimeImmutable('2026-03-07')));
		$this->assertSame(expected: 0, actual: $count->signed(from: $today, to: $today));
		// Days overdue is the same question asked from the deadline.
		$this->assertSame(expected: 3, actual: $count->signed(from: new DateTimeImmutable('2026-03-07'), to: $today));
	}//end testSignedCarriesTheDirection()

	/**
	 * Whole days only: 23 hours is no day.
	 *
	 * @return void
	 */
	public function testOnlyWholeDaysCount(): void {
		$count = new DayCount();
		$from = new DateTimeImmutable('2026-03-10T09:00:00+01:00');

		$this->assertSame(expected: 0, actual: $count->between(from: $from, to: new DateTimeImmutable('2026-03-11T08:00:00+01:00')));
		$this->assertSame(expected: 1, actual: $count->between(from: $from, to: new DateTimeImmutable('2026-03-11T09:00:00+01:00')));
		$this->assertSame(expected: 6, actual: $count->signed(from: $from, to: new DateTimeImmutable('2026-03-17T08:59:59+01:00')));
		$this->assertSame(expected: 0, actual: $count->signed(from: $from, to: new DateTimeImmutable('2026-03-09T10:00:00+01:00')));
		$this->assertSame(expected: -1, actual: $count->signed(from: $from, to: new DateTimeImmutable('2026-03-09T09:00:00+01:00')));
	}//end testOnlyWholeDaysCount()

	/**
	 * Months, leap days and the clock change are counted as the days they are.
	 *
	 * @return void
	 */
	public function testCalendarOdditiesAreCountedAsDays(): void {
		$count = new DayCount();
		$zone = new DateTimeZone('Europe/Amsterdam');

		// Not 1 month and 1 day read as 1.
		$this->assertSame(expected: 32, actual: $count->between(from: new DateTimeImmutable('2026-01-31'), to: new DateTimeImmutable('2026-03-04')));
		$this->assertSame(expected: 367, actual: $count->between(from: new DateTimeImmutable('2024-02-28'), to: new DateTimeImmutable('2025-03-01')));
		// Midnight to midnight over the night the clocks go forward (29 March
		// 2026) is one day, although it is 23 hours long.
		$this->assertSame(
			expected: 1,
			actual: $count->between(from: new DateTimeImmutable('2026-03-29 00:00', $zone), to: new DateTimeImmutable('2026-03-30 00:00', $zone))
		);
		$this->assertSame(
			expected: 42,
			actual: $count->signed(from: new DateTimeImmutable('2026-03-01 00:00', $zone), to: new DateTimeImmutable('2026-04-12 00:00', $zone))
		);
	}//end testCalendarOdditiesAreCountedAsDays()

	/**
	 * A mutable date is counted the same and is left as it was.
	 *
	 * @return void
	 */
	public function testAMutableDateIsNotMoved(): void {
		$from = new DateTime('2026-03-02');
		$to = new DateTime('2026-03-12');

		$this->assertSame(expected: 10, actual: (new DayCount())->between(from: $from, to: $to));
		$this->assertSame(expected: -10, actual: (new DayCount())->signed(from: $to, to: $from));
		$this->assertSame(expected: '2026-03-02', actual: $from->format('Y-m-d'));
		$this->assertSame(expected: '2026-03-12', actual: $to->format('Y-m-d'));
	}//end testAMutableDateIsNotMoved()
}//end class
