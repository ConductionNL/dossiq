<?php

/**
 * Dossiq working-day calculator.
 *
 * The one place in this app that knows which days are working days. It answers
 * three questions — is this date a weekend, is it a Dutch national holiday, and
 * is it therefore a working day — and builds the two arithmetic operations
 * every caller needs on top of them: add N working days to a date, and count
 * the working days in a range.
 *
 * Easter is computed here with the anonymous Gregorian algorithm rather than
 * PHP's easter_date(). That is not a style preference: easter_date() lives in
 * ext-calendar, the extension is absent from the Nextcloud images this app runs
 * on, and a call to it throws "Call to undefined function" on any date that
 * gets past the weekend and fixed-holiday checks — an ordinary Tuesday. Keeping
 * the computus here means nothing in lib/ depends on ext-calendar, which is why
 * composer.json does not require it. WorkingDayCalculatorTest holds that line.
 *
 * NOT IMPLEMENTED HERE, deliberately: the Algemene termijnenwet end-date roll.
 * Article 1 Awt extends a term that ends on a Saturday, Sunday or generally
 * recognised holiday to the next following ordinary day. This class computes
 * working days; it does not roll a statutory end date, and no caller does
 * either. That is a real gap, it needs the recognised-holiday list confirmed by
 * someone qualified to read Awt art. 3 against our case types, and it is a
 * separate change.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Weekend, holiday and working-day arithmetic for the Dutch calendar.
 *
 * Stateless apart from a per-year holiday memo, so it is safe to share as a
 * singleton and fully unit-testable without a container.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */
class WorkingDayCalculator {
	/**
	 * Dutch national holidays that fall on a fixed calendar date, as 'MM-DD'.
	 *
	 * Nieuwjaarsdag, Koningsdag, Bevrijdingsdag, Eerste en Tweede Kerstdag.
	 *
	 * Koningsdag moves to 26 April when 27 April is a Sunday, which is not
	 * modelled: both days are then a weekend, so the working-day answer is the
	 * same either way.
	 *
	 * @var array<int, string>
	 */
	private const FIXED_HOLIDAYS = [
		'01-01',
		'04-27',
		'05-05',
		'12-25',
		'12-26',
	];

	/**
	 * Day offsets from Easter Sunday for the movable Dutch national holidays.
	 *
	 * -2 Goede Vrijdag, 0 Eerste Paasdag, 1 Tweede Paasdag,
	 * 39 Hemelvaartsdag, 49 Eerste Pinksterdag, 50 Tweede Pinksterdag.
	 *
	 * Four of these (-2, 1, 39, 50) fall on a weekday and are the only ones
	 * that change a working-day answer; 0 and 49 are Sundays and are listed so
	 * that isHoliday() is truthful about them.
	 *
	 * @var array<int, int>
	 */
	private const EASTER_OFFSETS = [-2, 0, 1, 39, 49, 50];

	/**
	 * Memoised holiday sets, keyed by year, each a list of 'Y-m-d' strings.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $holidayCache = [];

	/**
	 * Determine whether a date falls on a Saturday or Sunday.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a Saturday or a Sunday.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isWeekend(DateTimeInterface $date): bool {
		return ((int)$date->format('N') >= 6);
	}//end isWeekend()

	/**
	 * Determine whether a date is a Dutch national holiday.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a recognised national holiday.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isHoliday(DateTimeInterface $date): bool {
		$year = (int)$date->format('Y');
		return in_array($date->format('Y-m-d'), $this->holidays(year: $year), true);
	}//end isHoliday()

	/**
	 * Determine whether a date is a working day.
	 *
	 * A working day is a date that is neither a weekend day nor a Dutch
	 * national holiday.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a working day.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isWorkingDay(DateTimeInterface $date): bool {
		if ($this->isWeekend(date: $date) === true) {
			return false;
		}

		return ($this->isHoliday(date: $date) === false);
	}//end isWorkingDay()

	/**
	 * Add a number of working days to a date.
	 *
	 * The start date itself is never counted, so adding one working day to a
	 * Friday yields the following Monday. The time-of-day component of the
	 * start is preserved.
	 *
	 * @param DateTimeImmutable $start The starting date-time.
	 * @param int $days The number of working days to add; values below one
	 *                  return the start unchanged.
	 *
	 * @return DateTimeImmutable The resulting date-time.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function addWorkingDays(DateTimeImmutable $start, int $days): DateTimeImmutable {
		$result = $start;
		$remaining = max(0, $days);

		while ($remaining > 0) {
			$result = $result->modify('+1 day');
			if ($this->isWorkingDay(date: $result) === true) {
				$remaining--;
			}
		}

		return $result;
	}//end addWorkingDays()

	/**
	 * Count the working days in an inclusive date range.
	 *
	 * @param DateTimeImmutable $start The range start, counted itself.
	 * @param DateTimeImmutable $end The range end, counted itself.
	 *
	 * @return int The number of working days in the range, or zero when the
	 *             end falls before the start.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function countWorkingDays(DateTimeImmutable $start, DateTimeImmutable $end): int {
		$cursor = $start->setTime(0, 0);
		$last = $end->setTime(0, 0);

		if ($last < $cursor) {
			return 0;
		}

		$count = 0;
		while ($cursor <= $last) {
			if ($this->isWorkingDay(date: $cursor) === true) {
				$count++;
			}

			$cursor = $cursor->modify('+1 day');
		}

		return $count;
	}//end countWorkingDays()

	/**
	 * List the Dutch national holidays for a calendar year.
	 *
	 * @param int $year The calendar year.
	 *
	 * @return array<int, string> The holiday dates as 'Y-m-d' strings.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function holidays(int $year): array {
		if (isset($this->holidayCache[$year]) === true) {
			return $this->holidayCache[$year];
		}

		$dates = [];
		foreach (self::FIXED_HOLIDAYS as $monthDay) {
			$dates[] = $year . '-' . $monthDay;
		}

		$easter = $this->easterSunday(year: $year);
		foreach (self::EASTER_OFFSETS as $offset) {
			$dates[] = $easter->modify($offset . ' days')->format('Y-m-d');
		}

		$this->holidayCache[$year] = $dates;
		return $dates;
	}//end holidays()

	/**
	 * Compute Western Easter Sunday for a Gregorian year.
	 *
	 * Uses the anonymous Gregorian algorithm (Meeus/Jones/Butcher). The
	 * single-letter locals are the canonical names from the published
	 * algorithm and are kept verbatim so the code can be checked against it.
	 *
	 * @param int $year The calendar year.
	 *
	 * @return DateTimeImmutable Easter Sunday, at midnight in the default zone.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)
	 */
	public function easterSunday(int $year): DateTimeImmutable {
		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv((($b - $f) + 1), 3);
		$h = ((((19 * $a) + $b - $d - $g) + 15) % 30);
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = (((32 + (2 * $e) + (2 * $i)) - $h - $k) % 7);
		$m = intdiv(($a + (11 * $h) + (22 * $l)), 451);

		$month = intdiv((($h + $l) - (7 * $m) + 114), 31);
		$day = (((($h + $l) - (7 * $m) + 114) % 31) + 1);

		return (new DateTimeImmutable())->setDate($year, $month, $day)->setTime(0, 0);
	}//end easterSunday()
}//end class
