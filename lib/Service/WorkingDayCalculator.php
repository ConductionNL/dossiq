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
 * 🔴 THE LIST BELOW IS THE FALLBACK, AND SINCE 2026-09-19 THAT IS TRUE OF
 * EVERY CALLER RATHER THAN OF ONE. The authority is the calendar the
 * organisation administers in OpenRegister: which weekdays it works, which
 * days it is closed, and the hours it is open. This class asks that calendar
 * through {@see WorkingDayRoll} and answers from its own list only when no
 * calendar is answering, which it says once in the log.
 *
 * Until then the header already claimed as much and it was only true of the
 * statutory roll. Thirteen other callers, a complaint deadline and a KCC
 * callback among them, went on asking this list: an administered closure day
 * counted as an ordinary working day for all of them, and every one of them
 * produced a date nobody could tell from a correct one.
 *
 * The Algemene termijnenwet art. 1 end-date roll lives here as
 * nextWorkingDay(), and a statutory term reaches the engine's own businessDays
 * walk through {@see TermijnTimerService::rollTermEnd()} instead. See
 * docs/research/date-arithmetic-audit-2026-09-14.md for which files compute a
 * statutory term and which do not.
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
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use Psr\Log\LoggerInterface;

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
	 * Whether the degradation has already been said once.
	 *
	 * Once, not per call. A chase schedule asks this class a hundred times in
	 * one sweep, and a warning per question buries the one line that matters
	 * under a hundred copies of itself.
	 *
	 * @var boolean
	 */
	private bool $degradationLogged = false;

	/**
	 * Constructor.
	 *
	 * Both arguments default to null so the arithmetic stays constructible
	 * without a container, which a dozen unit tests rely on and which is also
	 * what an instance without OpenRegister gets.
	 *
	 * @param WorkingDayRoll|null  $administered The reader of the calendar the organisation administers.
	 * @param LoggerInterface|null $logger       Where the fall back to the built-in list is said out loud.
	 */
	public function __construct(
		private readonly ?WorkingDayRoll $administered = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Determine whether a date falls on a day outside the working week.
	 *
	 * The working week is CONFIGURATION when a calendar answers: an
	 * organisation that works Tuesday to Saturday says so on the calendar, and
	 * this returns true for its Monday. Saturday and Sunday are the fallback,
	 * not the definition.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date falls outside the working week.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isWeekend(DateTimeInterface $date): bool {
		$weekdays = $this->administered?->workingWeekdays();
		if ($weekdays !== null) {
			return (in_array((int)$date->format('N'), $weekdays, true) === false);
		}

		return ((int)$date->format('N') >= 6);
	}//end isWeekend()

	/**
	 * Determine whether a date is a day the organisation is closed for a
	 * holiday.
	 *
	 * 🔴 THE LIST IS THE ADMINISTRATOR'S WHEN THERE IS ONE. Which days an
	 * organisation does not work is one fact, and it is administered on the
	 * working calendar in OpenRegister. A holiday is then a day inside the
	 * working week that the calendar nevertheless refuses, which is exactly
	 * what a closure is. The list below answers only when no calendar does.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a closure day.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isHoliday(DateTimeInterface $date): bool {
		$works = $this->administered?->worksOn(moment: $date);
		if ($works !== null) {
			if ($this->isWeekend(date: $date) === true) {
				// A Sunday is not a holiday, and saying it is would make a
				// surface report Koningsdag and an ordinary Sunday as the
				// same kind of closed.
				return false;
			}

			return ($works === false);
		}

		$this->sayItDegraded();
		$year = (int)$date->format('Y');
		return in_array($date->format('Y-m-d'), $this->holidays(year: $year), true);
	}//end isHoliday()

	/**
	 * Determine whether a date is a working day.
	 *
	 * A working day is a date that is neither outside the working week nor a
	 * closure day, and both of those are the administered calendar's answers
	 * when one is answering.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a working day.
	 *
	 * @spec openspec/specs/milestone-tracking/spec.md
	 */
	public function isWorkingDay(DateTimeInterface $date): bool {
		$works = $this->administered?->worksOn(moment: $date);
		if ($works !== null) {
			return $works;
		}

		$this->sayItDegraded();
		if ($this->isWeekend(date: $date) === true) {
			return false;
		}

		return (in_array($date->format('Y-m-d'), $this->holidays(year: (int)$date->format('Y')), true) === false);
	}//end isWorkingDay()

	/**
	 * Which calendar this instance is actually answering from.
	 *
	 * Asked so a surface can SAY which it is. A date computed from the
	 * administered calendar and one computed from the built-in list are both
	 * plausible dates, and nothing on the page distinguishes them.
	 *
	 * @return bool True when the organisation's administered calendar is
	 *              answering, false when the built-in Dutch list is.
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function readsAdministeredCalendar(): bool {
		return ($this->administered?->isAvailable() === true);
	}//end readsAdministeredCalendar()

	/**
	 * Note, once, that the built-in list answered instead of the calendar.
	 *
	 * @return void
	 */
	private function sayItDegraded(): void {
		if ($this->degradationLogged === true) {
			return;
		}

		$this->degradationLogged = true;
		$this->logger?->info(
			'Dossiq working days: no administered calendar is answering, so the built-in Dutch national list decided. '
			. 'Which days this organisation does not work is configuration, and this instance has none.'
		);
	}//end sayItDegraded()

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
	 * The first working day on or after a date.
	 *
	 * This is the Algemene termijnenwet art. 1 roll computed on dossiq's own
	 * holiday list, and it is a FALLBACK. The engine calendar is the authority
	 * whenever OpenRegister is installed; {@see TermijnTimerService::rollTermEnd()}
	 * reaches this method only when the engine cannot answer, and logs that it
	 * did. A date that already falls on a working day is returned unchanged, so
	 * the roll never lengthens a term that does not need it.
	 *
	 * @param DateTimeImmutable $date The computed end date.
	 *
	 * @return DateTimeImmutable The first working day on or after the date.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function nextWorkingDay(DateTimeImmutable $date): DateTimeImmutable {
		$cursor = $date;
		while ($this->isWorkingDay(date: $cursor) === false) {
			$cursor = $cursor->modify('+1 day');
		}

		return $cursor;
	}//end nextWorkingDay()

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
