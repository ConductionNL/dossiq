<?php

/**
 * Unit tests for PlannedSeriesCalendar: a planned series repeats monthly,
 * quarterly, half-yearly or yearly from its anchor date, keeps the anchor's
 * day where every month it lands in has it and moves to the last day where
 * one does not, and finds the next date after a moment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\PlannedSeriesCalendar;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 */
class PlannedSeriesCalendarTest extends TestCase {

	private PlannedSeriesCalendar $calendar;

	protected function setUp(): void {
		parent::setUp();
		$this->calendar = new PlannedSeriesCalendar();
	}//end setUp()

	private function day(string $date): DateTimeImmutable {
		return new DateTimeImmutable($date);
	}//end day()

	private function occurrence(string $recurrence, string $anchor, int $index): string {
		return $this->calendar->occurrence($recurrence, $this->day($anchor), $index)->format('Y-m-d');
	}//end occurrence()

	public function testARecurrenceIsTrimmedEmptyMeansNoneAndAnUnknownOneIsRefused(): void {
		$this->assertSame('none', $this->calendar->recurrenceOf('  '));
		$this->assertSame('quarterly', $this->calendar->recurrenceOf(' quarterly '));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_recurrence');
		$this->calendar->recurrenceOf('weekly');
	}//end testARecurrenceIsTrimmedEmptyMeansNoneAndAnUnknownOneIsRefused()

	public function testAMonthlySeriesKeepsADayEveryMonthHas(): void {
		$this->assertSame('2026-01-15', $this->occurrence('monthly', '2026-01-15', 0));
		$this->assertSame('2026-02-15', $this->occurrence('monthly', '2026-01-15', 1));
		$this->assertSame('2027-02-15', $this->occurrence('monthly', '2026-11-15', 3));
	}//end testAMonthlySeriesKeepsADayEveryMonthHas()

	public function testAMonthlySeriesOnTheThirtyFirstFallsOnEachMonthsLastDay(): void {
		$this->assertSame('2026-02-28', $this->occurrence('monthly', '2026-01-31', 1));
		$this->assertSame('2026-03-31', $this->occurrence('monthly', '2026-01-31', 2));
		$this->assertSame('2026-04-30', $this->occurrence('monthly', '2026-01-31', 3));
		$this->assertTrue($this->calendar->usesLastDay('monthly', $this->day('2026-01-29')));
	}//end testAMonthlySeriesOnTheThirtyFirstFallsOnEachMonthsLastDay()

	public function testAQuarterlySeriesLooksOnlyAtTheMonthsItLandsIn(): void {
		$this->assertFalse($this->calendar->usesLastDay('quarterly', $this->day('2026-01-30')));
		$this->assertSame('2026-04-30', $this->occurrence('quarterly', '2026-01-30', 1));
		$this->assertSame('2026-10-30', $this->occurrence('quarterly', '2026-01-30', 3));

		$this->assertTrue($this->calendar->usesLastDay('quarterly', $this->day('2026-01-31')));
		$this->assertSame('2026-04-30', $this->occurrence('quarterly', '2026-01-31', 1));
		$this->assertSame('2026-07-31', $this->occurrence('quarterly', '2026-01-31', 2));
	}//end testAQuarterlySeriesLooksOnlyAtTheMonthsItLandsIn()

	public function testAYearlySeriesFromALeapDayUsesTheLastDayOfFebruary(): void {
		$this->assertSame('2025-02-28', $this->occurrence('yearly', '2024-02-29', 1));
		$this->assertSame('2028-02-29', $this->occurrence('yearly', '2024-02-29', 4));
	}//end testAYearlySeriesFromALeapDayUsesTheLastDayOfFebruary()

	public function testTheMonthsASeriesLandsInAreSorted(): void {
		$this->assertSame([2, 8], $this->calendar->monthsOf('halfYearly', $this->day('2026-08-10')));
		$this->assertSame([2, 5, 8, 11], $this->calendar->monthsOf('quarterly', $this->day('2026-11-01')));
		$this->assertSame([7], $this->calendar->monthsOf('yearly', $this->day('2026-07-01')));
		$this->assertSame(range(1, 12), $this->calendar->monthsOf('monthly', $this->day('2026-07-01')));
		$this->assertSame(range(1, 12), $this->calendar->monthsOf('none', $this->day('2026-07-01')));
		$this->assertSame(range(1, 12), $this->calendar->monthsOf('fortnightly', $this->day('2026-07-01')));
	}//end testTheMonthsASeriesLandsInAreSorted()

	public function testTheNextOccurrenceIsStrictlyAfterTheMoment(): void {
		$next = fn (string $recurrence, string $anchor, string $from): ?string => $this->calendar
			->nextOccurrence($recurrence, $this->day($anchor), $this->day($from))?->format('Y-m-d');

		$this->assertSame('2026-04-30', $next('monthly', '2026-01-31', '2026-03-31'));
		$this->assertSame('2026-05-10', $next('monthly', '2026-05-10', '2026-01-01'));
		$this->assertSame('2026-04-15', $next('quarterly', '2026-01-15', '2026-02-20'));
		$this->assertSame('2027-03-01', $next('yearly', '2026-03-01', '2026-03-01'));
	}//end testTheNextOccurrenceIsStrictlyAfterTheMoment()

	public function testAOneOffIsNextOnlyWhileItLiesAhead(): void {
		$this->assertSame('2026-06-01', $this->calendar->nextOccurrence('none', $this->day('2026-06-01'), $this->day('2026-05-31'))?->format('Y-m-d'));
		$this->assertNull($this->calendar->nextOccurrence('none', $this->day('2026-06-01'), $this->day('2026-06-01')));
		$this->assertNull($this->calendar->nextOccurrence('none', $this->day('2026-06-01'), $this->day('2026-07-01')));
	}//end testAOneOffIsNextOnlyWhileItLiesAhead()

	public function testTheRecurrenceInWords(): void {
		$this->assertSame('month', $this->calendar->inWords('monthly'));
		$this->assertSame('quarter', $this->calendar->inWords('quarterly'));
		$this->assertSame('half year', $this->calendar->inWords('halfYearly'));
		$this->assertSame('year', $this->calendar->inWords('yearly'));
		$this->assertSame('occurrence', $this->calendar->inWords('none'));
	}//end testTheRecurrenceInWords()

	public function testNextDateFollowsTheAnchorAndRecurrence(): void {
		$this->assertSame('2026-09-01', $this->calendar->nextDate(['anchor' => '2026-09-01', 'recurrence' => 'none'], '', $this->day('2030-01-01')));
		$this->assertSame('2026-04-30', $this->calendar->nextDate(['anchor' => '2026-01-31', 'recurrence' => 'monthly'], '', $this->day('2026-03-31')));
		$this->assertSame('2999-01-10', $this->calendar->nextDate(['anchor' => '2999-01-10', 'recurrence' => 'monthly'], '', null));
	}//end testNextDateFollowsTheAnchorAndRecurrence()

	public function testWithoutAnAnchorTheCronDayIsUsed(): void {
		$today = new DateTimeImmutable('today');
		$cron = sprintf('0 9 %d %d *', (int)$today->format('j'), (int)$today->format('n'));

		$this->assertSame($today->format('Y-m-d'), $this->calendar->nextDate(['anchor' => '', 'recurrence' => 'monthly'], $cron, null));
		$this->assertSame($today->format('Y-m-d'), $this->calendar->dateOfCron('  ' . $cron . ' '));
	}//end testWithoutAnAnchorTheCronDayIsUsed()

	public function testACronDayAlreadyPassedThisYearFallsNextYear(): void {
		$today = new DateTimeImmutable('today');
		$passed = $today->modify('-1 day');
		$cron = sprintf('0 9 %d %d *', (int)$passed->format('j'), (int)$passed->format('n'));
		$year = (int)$today->format('Y');
		if ((int)$passed->format('Y') === $year) {
			$year++;
		}

		$this->assertSame(sprintf('%04d-%s', $year, $passed->format('m-d')), $this->calendar->dateOfCron($cron));
	}//end testACronDayAlreadyPassedThisYearFallsNextYear()

	public function testACronWithoutAFixedDayAndMonthGivesNoDate(): void {
		$this->assertSame('', $this->calendar->dateOfCron('0 9 * *'));
		$this->assertSame('', $this->calendar->dateOfCron('0 9 * 5 *'));
		$this->assertSame('', $this->calendar->dateOfCron('0 9 12 * *'));
		$this->assertSame('', $this->calendar->dateOfCron(''));
	}//end testACronWithoutAFixedDayAndMonthGivesNoDate()
}//end class
