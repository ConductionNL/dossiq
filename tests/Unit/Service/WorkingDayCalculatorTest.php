<?php

/**
 * WorkingDayCalculator Unit Tests
 *
 * These tests exist because of a production crash. Two services computed Dutch
 * holidays with PHP's easter_date(), which lives in ext-calendar; no Nextcloud
 * image we run ships that extension, so every date that got past the weekend
 * and fixed-holiday checks threw "Call to undefined function easter_date()".
 * An ordinary Tuesday was enough.
 *
 * The suite could not see it: tests/bootstrap.php defined a polyfill, so the
 * tests ran against a function production did not have. That polyfill is gone.
 *
 * Two kinds of assertion guard the fix, and they guard different things:
 *
 *  - The behaviour tests below name real calendar dates around Easter across
 *    five years and assert the working-day answer. They fail on any regression
 *    that gets the holidays wrong, including the specific one that shipped:
 *    Goede Vrijdag is the only Easter offset that runs backwards, and it was
 *    the one DsoCaseService omitted.
 *  - testLibDoesNotDependOnExtCalendar asserts the dependency itself. It is
 *    needed because a behaviour test cannot catch a return to easter_date() on
 *    a developer box that HAS ext-calendar — and this box does. That is exactly
 *    the shape of the original failure: green locally, dead in the container.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkingDayCalculator.
 *
 * @covers \OCA\Dossiq\Service\WorkingDayCalculator
 */
class WorkingDayCalculatorTest extends TestCase {

	private WorkingDayCalculator $calculator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->calculator = new WorkingDayCalculator();
	}//end setUp()

	/**
	 * An ordinary weekday must answer, not throw.
	 *
	 * This is the crash, reduced. The old code reached easter_date() only after
	 * the weekend and fixed-holiday checks passed, so a plain Tuesday in
	 * September was the failing input.
	 *
	 * @return void
	 */
	public function testAnOrdinaryTuesdayIsAWorkingDay(): void {
		// 2026-09-08 is a Tuesday, in no holiday period at all.
		$this->assertTrue($this->calculator->isWorkingDay(new DateTimeImmutable('2026-09-08')));
	}//end testAnOrdinaryTuesdayIsAWorkingDay()

	/**
	 * Goede Vrijdag is a holiday in every year checked.
	 *
	 * It is the only Easter offset that runs backwards (-2), and the one
	 * DsoCaseService omitted, so it gets its own test.
	 *
	 * @dataProvider goodFridayProvider
	 *
	 * @param string $date The Goede Vrijdag date as 'Y-m-d'.
	 *
	 * @return void
	 */
	public function testGoodFridayIsNotAWorkingDay(string $date): void {
		$day = new DateTimeImmutable($date);

		$this->assertSame('Fri', $day->format('D'), $date . ' should be a Friday');
		$this->assertFalse($this->calculator->isWorkingDay($day), $date . ' is Goede Vrijdag');
	}//end testGoodFridayIsNotAWorkingDay()

	/**
	 * Goede Vrijdag for five consecutive years.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function goodFridayProvider(): array {
		return [
			'2026' => ['2026-04-03'],
			'2027' => ['2027-03-26'],
			'2028' => ['2028-04-14'],
			'2029' => ['2029-03-30'],
			'2030' => ['2030-04-19'],
		];
	}//end goodFridayProvider()

	/**
	 * Every Easter-derived weekday holiday, across five years.
	 *
	 * Tweede Paasdag (+1, Monday), Hemelvaartsdag (+39, Thursday) and Tweede
	 * Pinksterdag (+50, Monday) all fall on weekdays, so each one changes a
	 * working-day answer. Eerste Paasdag and Eerste Pinksterdag are Sundays and
	 * are covered by the weekend rule.
	 *
	 * @dataProvider easterWeekdayHolidayProvider
	 *
	 * @param string $date The holiday date as 'Y-m-d'.
	 * @param string $weekday The expected three-letter weekday name.
	 *
	 * @return void
	 */
	public function testEasterDerivedWeekdayHolidaysAreNotWorkingDays(string $date, string $weekday): void {
		$day = new DateTimeImmutable($date);

		$this->assertSame($weekday, $day->format('D'), $date . ' should be a ' . $weekday);
		$this->assertFalse($this->calculator->isWorkingDay($day), $date . ' is a national holiday');
	}//end testEasterDerivedWeekdayHolidaysAreNotWorkingDays()

	/**
	 * Tweede Paasdag, Hemelvaartsdag and Tweede Pinksterdag, 2026 to 2030.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function easterWeekdayHolidayProvider(): array {
		return [
			'tweede paasdag 2026' => ['2026-04-06', 'Mon'],
			'tweede paasdag 2027' => ['2027-03-29', 'Mon'],
			'tweede paasdag 2028' => ['2028-04-17', 'Mon'],
			'tweede paasdag 2029' => ['2029-04-02', 'Mon'],
			'tweede paasdag 2030' => ['2030-04-22', 'Mon'],
			'hemelvaartsdag 2026' => ['2026-05-14', 'Thu'],
			'hemelvaartsdag 2027' => ['2027-05-06', 'Thu'],
			'hemelvaartsdag 2028' => ['2028-05-25', 'Thu'],
			'hemelvaartsdag 2029' => ['2029-05-10', 'Thu'],
			'hemelvaartsdag 2030' => ['2030-05-30', 'Thu'],
			'tweede pinksterdag 2026' => ['2026-05-25', 'Mon'],
			'tweede pinksterdag 2027' => ['2027-05-17', 'Mon'],
			'tweede pinksterdag 2028' => ['2028-06-05', 'Mon'],
			'tweede pinksterdag 2029' => ['2029-05-21', 'Mon'],
			'tweede pinksterdag 2030' => ['2030-06-10', 'Mon'],
		];
	}//end easterWeekdayHolidayProvider()

	/**
	 * The days immediately around Easter that ARE working days.
	 *
	 * Without these, an implementation that called every day in April a holiday
	 * would pass the tests above.
	 *
	 * @dataProvider workingDayNearEasterProvider
	 *
	 * @param string $date The date as 'Y-m-d'.
	 *
	 * @return void
	 */
	public function testDaysAdjacentToEasterAreWorkingDays(string $date): void {
		$this->assertTrue(
			$this->calculator->isWorkingDay(new DateTimeImmutable($date)),
			$date . ' is an ordinary working day'
		);
	}//end testDaysAdjacentToEasterAreWorkingDays()

	/**
	 * Working days that sit next to an Easter holiday.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function workingDayNearEasterProvider(): array {
		return [
			'thursday before goede vrijdag 2026' => ['2026-04-02'],
			'tuesday after tweede paasdag 2026' => ['2026-04-07'],
			'friday after hemelvaartsdag 2026' => ['2026-05-15'],
			'tuesday after tweede pinksterdag 2026' => ['2026-05-26'],
			'thursday before goede vrijdag 2028' => ['2028-04-13'],
			'tuesday after tweede paasdag 2028' => ['2028-04-18'],
		];
	}//end workingDayNearEasterProvider()

	/**
	 * Adding one working day across the Easter weekend lands on the Tuesday.
	 *
	 * This is the assertion that separates the four implementations that used
	 * to exist. Starting on Thursday 2 April 2026 and adding one working day:
	 *
	 *  - Goede Vrijdag 3 April, Saturday, Sunday and Tweede Paasdag 6 April are
	 *    all skipped, so the answer is Tuesday 7 April.
	 *  - An implementation that omits Goede Vrijdag answers Friday 3 April.
	 *  - An implementation with no holidays at all also answers Friday 3 April.
	 *
	 * @return void
	 */
	public function testAddingOneWorkingDayCrossesTheWholeEasterWeekend(): void {
		$thursday = new DateTimeImmutable('2026-04-02');

		$this->assertSame(
			'2026-04-07',
			$this->calculator->addWorkingDays($thursday, 1)->format('Y-m-d')
		);
	}//end testAddingOneWorkingDayCrossesTheWholeEasterWeekend()

	/**
	 * Adding working days across Hemelvaartsdag and Tweede Pinksterdag.
	 *
	 * @return void
	 */
	public function testAddingWorkingDaysSkipsAscensionAndWhitMonday(): void {
		// Wednesday 13 May 2026 + 1: Hemelvaartsdag 14 May is skipped.
		$this->assertSame(
			'2026-05-15',
			$this->calculator->addWorkingDays(new DateTimeImmutable('2026-05-13'), 1)->format('Y-m-d')
		);

		// Friday 22 May 2026 + 1: weekend and Tweede Pinksterdag 25 May skipped.
		$this->assertSame(
			'2026-05-26',
			$this->calculator->addWorkingDays(new DateTimeImmutable('2026-05-22'), 1)->format('Y-m-d')
		);
	}//end testAddingWorkingDaysSkipsAscensionAndWhitMonday()

	/**
	 * The five-working-day Awb acknowledgement deadline across Easter 2026.
	 *
	 * Wednesday 1 April 2026 plus five working days: 2 April counts, 3 April is
	 * Goede Vrijdag, 4 and 5 April are the weekend, 6 April is Tweede Paasdag,
	 * then 7, 8, 9 and 10 April count. The fifth is Friday 10 April.
	 *
	 * @return void
	 */
	public function testFiveWorkingDaysFromBeforeEasterLandsAfterIt(): void {
		$this->assertSame(
			'2026-04-10',
			$this->calculator->addWorkingDays(new DateTimeImmutable('2026-04-01'), 5)->format('Y-m-d')
		);
	}//end testFiveWorkingDaysFromBeforeEasterLandsAfterIt()

	/**
	 * The start date is never counted and the time of day survives.
	 *
	 * @return void
	 */
	public function testAddWorkingDaysPreservesTimeAndNeverCountsTheStart(): void {
		$friday = new DateTimeImmutable('2026-09-11 14:30:00');

		$result = $this->calculator->addWorkingDays($friday, 1);

		$this->assertSame('2026-09-14 14:30:00', $result->format('Y-m-d H:i:s'));
		$this->assertSame(
			'2026-09-11 14:30:00',
			$this->calculator->addWorkingDays($friday, 0)->format('Y-m-d H:i:s')
		);
	}//end testAddWorkingDaysPreservesTimeAndNeverCountsTheStart()

	/**
	 * April 2026 holds 19 working days.
	 *
	 * Thirty days, eight of them weekend days, leaving 22 weekdays; Goede
	 * Vrijdag (3rd), Tweede Paasdag (6th) and Koningsdag (27th) take three of
	 * those. Eerste Paasdag falls on the Sunday of the 5th and is already out.
	 *
	 * @return void
	 */
	public function testCountWorkingDaysInAprilTwentyTwentySix(): void {
		$this->assertSame(
			19,
			$this->calculator->countWorkingDays(
				new DateTimeImmutable('2026-04-01'),
				new DateTimeImmutable('2026-04-30')
			)
		);
	}//end testCountWorkingDaysInAprilTwentyTwentySix()

	/**
	 * A range that ends before it starts counts nothing.
	 *
	 * @return void
	 */
	public function testCountWorkingDaysReturnsZeroForAnInvertedRange(): void {
		$this->assertSame(
			0,
			$this->calculator->countWorkingDays(
				new DateTimeImmutable('2026-04-30'),
				new DateTimeImmutable('2026-04-01')
			)
		);
	}//end testCountWorkingDaysReturnsZeroForAnInvertedRange()

	/**
	 * The fixed national holidays, and Koningsdag falling on a weekday.
	 *
	 * @return void
	 */
	public function testFixedHolidays(): void {
		foreach (['2026-01-01', '2026-04-27', '2026-05-05', '2026-12-25', '2026-12-26'] as $date) {
			$this->assertTrue(
				$this->calculator->isHoliday(new DateTimeImmutable($date)),
				$date . ' is a fixed national holiday'
			);
		}

		// Koningsdag 2026 is a Monday, so it removes a working day.
		$this->assertSame('Mon', (new DateTimeImmutable('2026-04-27'))->format('D'));
		$this->assertFalse($this->calculator->isWorkingDay(new DateTimeImmutable('2026-04-27')));
	}//end testFixedHolidays()

	/**
	 * Easter Sunday is a holiday, and so is Eerste Pinksterdag.
	 *
	 * Both are Sundays, so they never change a working-day answer, but
	 * isHoliday() should still be truthful about them.
	 *
	 * @return void
	 */
	public function testTheSundayHolidaysAreStillHolidays(): void {
		$this->assertTrue($this->calculator->isHoliday(new DateTimeImmutable('2026-04-05')));
		$this->assertTrue($this->calculator->isHoliday(new DateTimeImmutable('2026-05-24')));
	}//end testTheSundayHolidaysAreStillHolidays()

	/**
	 * Easter Sunday is computed correctly, and the answer does not move with
	 * the ambient timezone.
	 *
	 * The timezone half matters: PHP's easter_date() returns a fixed
	 * CEST-midnight timestamp, so date('Y-m-d', easter_date($y)) reads one day
	 * early under date.timezone=UTC — which is what the container uses. Two of
	 * the implementations this class replaced did exactly that.
	 *
	 * @return void
	 */
	public function testEasterSundayIsCorrectAndTimezoneIndependent(): void {
		$expected = [
			2026 => '2026-04-05',
			2027 => '2027-03-28',
			2028 => '2028-04-16',
			2029 => '2029-04-01',
			2030 => '2030-04-21',
		];

		$original = date_default_timezone_get();

		try {
			foreach (['UTC', 'Europe/Amsterdam', 'Pacific/Kiritimati', 'Pacific/Niue'] as $zone) {
				date_default_timezone_set($zone);
				$calculator = new WorkingDayCalculator();

				foreach ($expected as $year => $date) {
					$this->assertSame(
						$date,
						$calculator->easterSunday($year)->format('Y-m-d'),
						'Easter ' . $year . ' under ' . $zone
					);
				}
			}
		} finally {
			date_default_timezone_set($original);
		}
	}//end testEasterSundayIsCorrectAndTimezoneIndependent()

	/**
	 * Nothing under lib/ may call an ext-calendar function.
	 *
	 * The extension is absent from every Nextcloud image this app runs on and
	 * composer.json deliberately does not require it, so a call to one of these
	 * is a fatal error in production. This test asserts the dependency rather
	 * than the behaviour on purpose: a behaviour test cannot catch a return to
	 * easter_date() on a machine that has the extension, and developer machines
	 * do have it.
	 *
	 * @return void
	 */
	public function testLibDoesNotDependOnExtCalendar(): void {
		$calendarFunctions = [
			'easter_date',
			'easter_days',
			'cal_days_in_month',
			'cal_from_jd',
			'cal_info',
			'cal_to_jd',
			'frenchtojd',
			'gregoriantojd',
			'jddayofweek',
			'jdmonthname',
			'jdtofrench',
			'jdtogregorian',
			'jdtojewish',
			'jdtojulian',
			'jdtounix',
			'jewishtojd',
			'juliantojd',
			'unixtojd',
		];

		$libDir = dirname(__DIR__, 3) . '/lib';
		$this->assertDirectoryExists($libDir);

		$files = new \RegexIterator(
			new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($libDir)),
			'/\.php$/'
		);

		$offenders = [];
		foreach ($files as $file) {
			$source = file_get_contents($file->getPathname());
			if ($source === false) {
				continue;
			}

			foreach ($this->functionCallsIn(source: $source) as $called) {
				if (in_array($called, $calendarFunctions, true) === true) {
					$offenders[] = $file->getPathname() . ' calls ' . $called . '()';
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"lib/ must not call ext-calendar functions; the extension is not installed.\n"
			. implode("\n", $offenders)
		);
	}//end testLibDoesNotDependOnExtCalendar()

	/**
	 * composer.json must not require ext-calendar.
	 *
	 * The pair of this test and the one above is the contract: lib/ does not
	 * use the extension, so we do not declare it. If a future change genuinely
	 * needs ext-calendar, both of these have to move together, and whoever
	 * moves them has to confirm the base image supplies it.
	 *
	 * @return void
	 */
	public function testComposerDoesNotRequireExtCalendar(): void {
		$path = dirname(__DIR__, 3) . '/composer.json';
		$composer = json_decode((string)file_get_contents($path), true);

		$this->assertIsArray($composer);
		$this->assertArrayNotHasKey('ext-calendar', ($composer['require'] ?? []));
	}//end testComposerDoesNotRequireExtCalendar()

	/**
	 * Extract the names of the functions called in a PHP source string.
	 *
	 * Uses the tokeniser rather than a regex so that the word appearing inside
	 * a comment or a string (this file's own docblocks, for one) is not a hit.
	 *
	 * @param string $source The PHP source.
	 *
	 * @return array<int, string> The lower-cased function names called.
	 */
	private function functionCallsIn(string $source): array {
		$tokens = token_get_all($source);
		$called = [];

		foreach ($tokens as $index => $token) {
			if (is_array($token) === false || $token[0] !== T_STRING) {
				continue;
			}

			// The next significant token must be an opening parenthesis.
			$next = null;
			for ($i = ($index + 1); $i < count($tokens); $i++) {
				if (is_array($tokens[$i]) === true && $tokens[$i][0] === T_WHITESPACE) {
					continue;
				}

				$next = $tokens[$i];
				break;
			}

			if ($next !== '(') {
				continue;
			}

			// Skip method and static calls: ->foo() and Foo::bar().
			$prev = null;
			for ($i = ($index - 1); $i >= 0; $i--) {
				if (is_array($tokens[$i]) === true && $tokens[$i][0] === T_WHITESPACE) {
					continue;
				}

				$prev = $tokens[$i];
				break;
			}

			if (is_array($prev) === true
				&& in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_NEW], true) === true
			) {
				continue;
			}

			$called[] = strtolower($token[1]);
		}

		return array_values(array_unique($called));
	}//end functionCallsIn()
}//end class
