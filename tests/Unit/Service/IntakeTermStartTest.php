<?php

/**
 * A Sunday filing does not start the clock on Sunday.
 *
 * 🔴 THE STAMP IS WRITTEN ONCE AND MUST NOT MOVE. The flag is derivable from
 * the two moments, and deriving it on read is what makes it useless in March:
 * the calendar has moved, the holiday list has grown, and the answer a handler
 * gives a complainant then is no longer the answer the citizen was given in
 * January. So the test drives `stampFor` and asserts what it WROTE, and the
 * listener test asserts that a case which already carries a stamp is left
 * alone.
 *
 * 🔴 THE TWO MOMENTS ARE COMPARED AS INSTANTS, NOT AS STRINGS. Two spellings
 * of one moment would read as a difference and put the "starts on the first
 * working day" sentence on a confirmation that does not need it, which is
 * exactly the noise D-4 refuses.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Terms\IntakeTermStart;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The two moments and the flag.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStartTest extends TestCase {
	/**
	 * The service over a real calendar and an administered window.
	 *
	 * The calendar is the REAL WorkingDayCalculator, not a double: the whole
	 * point of D-3 is that one calendar answers the confirmation and the term,
	 * and a double here would be a second definition of a working day living
	 * in the test.
	 *
	 * @param array<string, string> $window The administered window, if any.
	 *
	 * @return IntakeTermStart The service.
	 */
	private function service(array $window = []): IntakeTermStart {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($window): string {
				return ($window[$key] ?? $default);
			}
		);

		return new IntakeTermStart(new WorkingDayCalculator(), $config);
	}//end service()

	/**
	 * Sunday evening starts on Monday morning.
	 *
	 * @return void
	 */
	public function testASundayEveningFilingStartsMondayMorning(): void {
		// 2026-09-13 is a Sunday.
		$stamp = $this->service()->stampFor(new DateTimeImmutable('2026-09-13 20:41:00'));

		$this->assertStringStartsWith('2026-09-13T20:41', $stamp['receivedAt']);
		$this->assertStringStartsWith('2026-09-14T09:00', $stamp['termStartsAt']);
		$this->assertTrue($stamp['receivedOutsideWorkingHours']);
	}//end testASundayEveningFilingStartsMondayMorning()

	/**
	 * A filing inside the window starts at once, and says nothing about it.
	 *
	 * @return void
	 */
	public function testAFilingInsideTheWindowStartsAtOnce(): void {
		// 2026-09-15 is a Tuesday.
		$stamp = $this->service()->stampFor(new DateTimeImmutable('2026-09-15 10:00:00'));

		$this->assertSame($stamp['receivedAt'], $stamp['termStartsAt']);
		$this->assertFalse($stamp['receivedOutsideWorkingHours']);
	}//end testAFilingInsideTheWindowStartsAtOnce()

	/**
	 * Before the desk opens is the same day, not the next one.
	 *
	 * @return void
	 */
	public function testBeforeOpeningStartsTheSameDay(): void {
		$stamp = $this->service()->stampFor(new DateTimeImmutable('2026-09-15 06:12:00'));

		$this->assertStringStartsWith('2026-09-15T09:00', $stamp['termStartsAt']);
		$this->assertTrue($stamp['receivedOutsideWorkingHours']);
	}//end testBeforeOpeningStartsTheSameDay()

	/**
	 * After it closes is the next working day.
	 *
	 * @return void
	 */
	public function testAfterClosingStartsTheNextWorkingDay(): void {
		$stamp = $this->service()->stampFor(new DateTimeImmutable('2026-09-15 19:30:00'));

		$this->assertStringStartsWith('2026-09-16T09:00', $stamp['termStartsAt']);
		$this->assertTrue($stamp['receivedOutsideWorkingHours']);
	}//end testAfterClosingStartsTheNextWorkingDay()

	/**
	 * A Friday evening filing waits for Monday, not for Saturday.
	 *
	 * @return void
	 */
	public function testAFridayEveningFilingWaitsForMonday(): void {
		// 2026-09-18 is a Friday.
		$stamp = $this->service()->stampFor(new DateTimeImmutable('2026-09-18 22:00:00'));

		$this->assertStringStartsWith('2026-09-21T09:00', $stamp['termStartsAt']);
	}//end testAFridayEveningFilingWaitsForMonday()

	/**
	 * A holiday is not a working day, and the calendar that says so is the one
	 * the term is counted on.
	 *
	 * @return void
	 */
	public function testAHolidayIsSkippedByTheSameCalendarTheTermUses(): void {
		$calendar = new WorkingDayCalculator();
		// Second day of Christmas 2026 falls on a Saturday, so take King's Day,
		// 27 April 2026, a Monday the calculator declares a holiday.
		$kingsDay = new DateTimeImmutable('2026-04-27 08:00:00');
		$this->assertFalse($calendar->isWorkingDay($kingsDay), 'the calendar no longer calls King\'s Day a holiday');

		$stamp = $this->service()->stampFor($kingsDay);

		$this->assertStringStartsWith('2026-04-28T09:00', $stamp['termStartsAt']);
		$this->assertTrue($stamp['receivedOutsideWorkingHours']);
	}//end testAHolidayIsSkippedByTheSameCalendarTheTermUses()

	/**
	 * An administered window is honoured.
	 *
	 * @return void
	 */
	public function testAnAdministeredWindowIsHonoured(): void {
		$service = $this->service(['working_hours_start' => '08:30', 'working_hours_end' => '16:00']);

		$early = $service->stampFor(new DateTimeImmutable('2026-09-15 08:40:00'));
		$this->assertFalse($early['receivedOutsideWorkingHours'], 'a gemeente opening at 08:30 is open at 08:40');

		$late = $service->stampFor(new DateTimeImmutable('2026-09-15 16:30:00'));
		$this->assertStringStartsWith('2026-09-16T08:30', $late['termStartsAt']);
	}//end testAnAdministeredWindowIsHonoured()

	/**
	 * A window with no working moment in it falls back rather than pushing
	 * every request to the next day for ever.
	 *
	 * @return void
	 */
	public function testAWindowThatClosesBeforeItOpensFallsBack(): void {
		$service = $this->service(['working_hours_start' => '17:00', 'working_hours_end' => '09:00']);

		$stamp = $service->stampFor(new DateTimeImmutable('2026-09-15 10:00:00'));

		$this->assertFalse($stamp['receivedOutsideWorkingHours']);
		$this->assertSame($stamp['receivedAt'], $stamp['termStartsAt']);
	}//end testAWindowThatClosesBeforeItOpensFallsBack()

	/**
	 * A window somebody typed a word into is refused the same way.
	 *
	 * @return void
	 */
	public function testAMalformedWindowFallsBack(): void {
		$service = $this->service(['working_hours_start' => 'ochtend', 'working_hours_end' => '17:00']);

		$stamp = $service->stampFor(new DateTimeImmutable('2026-09-15 10:00:00'));

		$this->assertFalse($stamp['receivedOutsideWorkingHours']);
	}//end testAMalformedWindowFallsBack()
}//end class
