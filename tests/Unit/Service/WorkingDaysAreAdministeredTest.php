<?php

/**
 * Which days this organisation works is configuration, and the calculator
 * reads it.
 *
 * WHAT WAS WRONG. `WorkingDayCalculator`'s header said it was the fallback
 * and that the administered calendar was the authority. That was true of one
 * caller, the statutory roll. Thirteen others, the complaint deadline and the
 * KCC callback among them, went on asking the built-in list of five fixed
 * Dutch dates and six Easter offsets. An administrator who added a local
 * closure day saw it honoured on the term badge and ignored everywhere else,
 * and both dates looked perfectly ordinary.
 *
 * So every assertion here is about which list won. The interesting pair is a
 * closure day the built-in list has never heard of, and 25 December on a
 * calendar that works it: configuration has to beat the hardcoded list in
 * BOTH directions, because a reader that only ever adds days would pass the
 * first and fail the second.
 *
 * THE DOUBLE USES `onlyMethods`. A double that can invent the method it is
 * asked for cannot fail, and `worksOn` and `workingWeekdays` are exactly the
 * two methods this change added to `WorkingDayRoll`: a typo in either name
 * would otherwise read as a passing test of a call site that does not exist.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The calculator answers from the administered calendar when one answers.
 *
 * @covers \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Termijn\WorkingDayRoll
 */
class WorkingDaysAreAdministeredTest extends TestCase {
	/**
	 * A calendar reader that answers, with the closures this test names.
	 *
	 * @param array<int, string> $closures ISO dates the organisation is closed.
	 * @param array<int, int>    $weekdays The ISO weekdays it works.
	 *
	 * @return WorkingDayRoll&MockObject The double.
	 */
	private function administering(array $closures, array $weekdays = [1, 2, 3, 4, 5]): WorkingDayRoll {
		$roll = $this->getMockBuilder(WorkingDayRoll::class)
			->disableOriginalConstructor()
			->onlyMethods(['isAvailable', 'worksOn', 'workingWeekdays'])
			->getMock();

		$roll->method('isAvailable')->willReturn(true);
		$roll->method('workingWeekdays')->willReturn($weekdays);
		$roll->method('worksOn')->willReturnCallback(
			static function (DateTimeInterface $moment) use ($closures, $weekdays): bool {
				if (in_array((int)$moment->format('N'), $weekdays, true) === false) {
					return false;
				}

				return (in_array($moment->format('Y-m-d'), $closures, true) === false);
			}
		);

		return $roll;
	}//end administering()

	/**
	 * A closure day the built-in list has never heard of closes the office.
	 *
	 * 1 September 2026 is an ordinary Tuesday to the hardcoded list. To an
	 * organisation that administered it as a closure day it is not a working
	 * day, and this is the assertion that says which of the two decided.
	 *
	 * @return void
	 */
	public function testAnAdministeredClosureDayIsNotAWorkingDay(): void {
		$calculator = new WorkingDayCalculator(
			administered: $this->administering(closures: ['2026-09-01'])
		);

		$tuesday = new DateTimeImmutable('2026-09-01 10:00:00');

		self::assertFalse($calculator->isWorkingDay(date: $tuesday));
		self::assertTrue($calculator->isHoliday(date: $tuesday));
		self::assertFalse($calculator->isWeekend(date: $tuesday));
		self::assertTrue($calculator->readsAdministeredCalendar());
	}//end testAnAdministeredClosureDayIsNotAWorkingDay()

	/**
	 * 🔴 CONFIGURATION WINS IN THE OTHER DIRECTION TOO. An organisation that
	 * works on 25 December works on 25 December, and the built-in list does
	 * not get a vote. A reader that only ever ADDED closure days would pass
	 * the test above and fail here, and the failure would be invisible: a
	 * deadline one day late looks exactly like a deadline.
	 *
	 * @return void
	 */
	public function testTheBuiltInHolidayListDoesNotOverrideTheCalendar(): void {
		$calculator = new WorkingDayCalculator(administered: $this->administering(closures: []));

		$christmas = new DateTimeImmutable('2026-12-25 10:00:00');

		self::assertSame(5, (int)$christmas->format('N'), 'the fixture date is a Friday');
		self::assertTrue($calculator->isWorkingDay(date: $christmas));
		self::assertFalse($calculator->isHoliday(date: $christmas));
	}//end testTheBuiltInHolidayListDoesNotOverrideTheCalendar()

	/**
	 * The working week is configuration. An organisation working Tuesday to
	 * Saturday is closed on Monday and open on Saturday, and neither answer
	 * is reachable from a weekday number.
	 *
	 * @return void
	 */
	public function testTheWorkingWeekComesFromTheCalendar(): void {
		$calculator = new WorkingDayCalculator(
			administered: $this->administering(closures: [], weekdays: [2, 3, 4, 5, 6])
		);

		$monday = new DateTimeImmutable('2026-09-07 10:00:00');
		$saturday = new DateTimeImmutable('2026-09-12 10:00:00');

		self::assertTrue($calculator->isWeekend(date: $monday));
		self::assertFalse($calculator->isWorkingDay(date: $monday));
		self::assertFalse($calculator->isWeekend(date: $saturday));
		self::assertTrue($calculator->isWorkingDay(date: $saturday));
	}//end testTheWorkingWeekComesFromTheCalendar()

	/**
	 * The arithmetic built on top follows the configuration, which is the
	 * point: no caller had to change for a closure day to be honoured.
	 *
	 * @return void
	 */
	public function testTheArithmeticSkipsAnAdministeredClosureDay(): void {
		$calculator = new WorkingDayCalculator(
			administered: $this->administering(closures: ['2026-09-02'])
		);

		$landed = $calculator->addWorkingDays(
			start: new DateTimeImmutable('2026-09-01 09:00:00'),
			days: 2
		);

		// Tuesday plus two working days is Thursday, the Wednesday being
		// closed. Without the calendar it would be Wednesday.
		self::assertSame('2026-09-04 09:00', $landed->format('Y-m-d H:i'));
		// Tuesday to Sunday holds four weekdays, and the administered closure
		// takes the Wednesday out: three.
		self::assertSame(
			3,
			$calculator->countWorkingDays(
				start: new DateTimeImmutable('2026-09-01'),
				end: new DateTimeImmutable('2026-09-06')
			)
		);
	}//end testTheArithmeticSkipsAnAdministeredClosureDay()

	/**
	 * With no calendar answering, the built-in Dutch list decides exactly as
	 * it always has, and the instance can be told that it did.
	 *
	 * This is the posture of an instance without OpenRegister, and the
	 * assertion that this change moved no date on one.
	 *
	 * @return void
	 */
	public function testWithoutACalendarTheBuiltInListStillAnswers(): void {
		$calculator = new WorkingDayCalculator();

		self::assertFalse($calculator->readsAdministeredCalendar());
		self::assertTrue($calculator->isHoliday(date: new DateTimeImmutable('2026-12-25')));
		self::assertFalse($calculator->isWorkingDay(date: new DateTimeImmutable('2026-12-25')));
		self::assertTrue($calculator->isWorkingDay(date: new DateTimeImmutable('2026-09-01')));
	}//end testWithoutACalendarTheBuiltInListStillAnswers()

	/**
	 * A calendar that is present but silent degrades to the built-in list
	 * rather than calling every day a working day.
	 *
	 * `worksOn` answers null when the engine refuses, and null is not false:
	 * a reader that treated it as false would close the office permanently,
	 * and one that treated it as true would open it on Christmas.
	 *
	 * @return void
	 */
	public function testASilentCalendarFallsBackRatherThanAnsweringNull(): void {
		$roll = $this->getMockBuilder(WorkingDayRoll::class)
			->disableOriginalConstructor()
			->onlyMethods(['isAvailable', 'worksOn', 'workingWeekdays'])
			->getMock();
		$roll->method('isAvailable')->willReturn(false);
		$roll->method('worksOn')->willReturn(null);
		$roll->method('workingWeekdays')->willReturn(null);

		$calculator = new WorkingDayCalculator(administered: $roll);

		self::assertFalse($calculator->readsAdministeredCalendar());
		self::assertFalse($calculator->isWorkingDay(date: new DateTimeImmutable('2026-12-25')));
		self::assertTrue($calculator->isWorkingDay(date: new DateTimeImmutable('2026-09-01')));
	}//end testASilentCalendarFallsBackRatherThanAnsweringNull()
}//end class
