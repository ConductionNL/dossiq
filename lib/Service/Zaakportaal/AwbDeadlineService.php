<?php

/**
 * Procest Zaakportaal Awb Deadline Service
 *
 * Computes statutory deadlines under the Algemene wet bestuursrecht (Awb) for
 * the citizen portal: the bezwaarschrift termijn (6 weeks from the day after a
 * decision is announced, Awb art. 6:7/6:8) and klacht handling deadlines. When
 * a deadline falls on a weekend or a recognised Dutch public holiday it is
 * extended to the next working day (Algemene termijnenwet art. 1).
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-12
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateInterval;
use DateTimeImmutable;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Statutory deadline arithmetic for objections and complaints.
 *
 * @psalm-suppress UnusedClass
 */
class AwbDeadlineService
{
    /**
     * The bezwaarschrift termijn in weeks (Awb art. 6:7).
     *
     * @var int
     */
    public const BEZWAAR_TERMIJN_WEEKS = 6;

    /**
     * Compute the bezwaar deadline from a decision date.
     *
     * The termijn starts the day after the decision is announced and runs for
     * six weeks; if the end date is a non-working day it is extended to the
     * next working day.
     *
     * @param string $decisionDate The decision date (Y-m-d or ISO).
     *
     * @return string The deadline as Y-m-d.
     *
     * @throws OCSBadRequestException When the date cannot be parsed.
     */
    public function bezwaarDeadline(string $decisionDate): string
    {
        $base = $this->parse(value: $decisionDate);
        // Termijn starts the day after announcement (Awb art. 6:8).
        $end = $base->add(new DateInterval('P'.self::BEZWAAR_TERMIJN_WEEKS.'W'));

        return $this->nextWorkingDay(date: $end)->format('Y-m-d');
    }//end bezwaarDeadline()

    /**
     * Determine whether a submission instant falls within the bezwaar termijn.
     *
     * @param string $decisionDate   The decision date.
     * @param string $submissionDate The submission date (defaults to now).
     *
     * @return bool True when the submission is timely.
     *
     * @throws OCSBadRequestException When a date cannot be parsed.
     */
    public function isWithinBezwaarTermijn(string $decisionDate, string $submissionDate=''): bool
    {
        $deadline   = $this->parse(value: $this->bezwaarDeadline(decisionDate: $decisionDate));
        $submission = new DateTimeImmutable('today');
        if ($submissionDate !== '') {
            $submission = $this->parse(value: $submissionDate);
        }

        // The whole deadline day counts as timely.
        return $submission <= $deadline;
    }//end isWithinBezwaarTermijn()

    /**
     * Whole calendar days remaining until a deadline (0 when passed).
     *
     * @param string $deadline The deadline (Y-m-d).
     * @param string $from     The reference date (defaults to today).
     *
     * @return int Days remaining (>= 0).
     *
     * @throws OCSBadRequestException When a date cannot be parsed.
     */
    public function daysRemaining(string $deadline, string $from=''): int
    {
        $end  = $this->parse(value: $deadline);
        $base = new DateTimeImmutable('today');
        if ($from !== '') {
            $base = $this->parse(value: $from);
        }

        if ($base >= $end) {
            return 0;
        }

        return (int) $base->diff($end)->days;
    }//end daysRemaining()

    /**
     * Advance a date to the next working day if it is a weekend or holiday.
     *
     * @param DateTimeImmutable $date The candidate date.
     *
     * @return DateTimeImmutable The next working day (or the date itself).
     */
    public function nextWorkingDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $cursor = $date;
        while ($this->isWorkingDay(date: $cursor) === false) {
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $cursor;
    }//end nextWorkingDay()

    /**
     * Whether a date is a working day (not a weekend, not a Dutch holiday).
     *
     * @param DateTimeImmutable $date The date to test.
     *
     * @return bool True on a working day.
     */
    public function isWorkingDay(DateTimeImmutable $date): bool
    {
        $weekday = (int) $date->format('N');
        if ($weekday >= 6) {
            return false;
        }

        return in_array($date->format('Y-m-d'), $this->dutchHolidays(year: (int) $date->format('Y')), true) === false;
    }//end isWorkingDay()

    /**
     * Recognised Dutch national public holidays for a given year, including
     * the Easter-derived movable feasts.
     *
     * @param int $year The calendar year.
     *
     * @return array<int, string> Holiday dates as Y-m-d.
     */
    public function dutchHolidays(int $year): array
    {
        // The calendar extension's easter_date() may be unavailable; compute
        // Easter manually so the service is portable and unit-testable.
        $easter = $this->easterDate(year: $year);

        // Fixed feasts: Nieuwjaarsdag, Koningsdag (simplified), and the two
        // Christmas days.
        $fixed = [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-04-27', $year),
            sprintf('%04d-12-25', $year),
            sprintf('%04d-12-26', $year),
        ];

        // Easter-derived movable feasts: Tweede paasdag, Hemelvaartsdag and
        // Tweede pinksterdag.
        $movable = [
            $easter->add(new DateInterval('P1D'))->format('Y-m-d'),
            $easter->add(new DateInterval('P39D'))->format('Y-m-d'),
            $easter->add(new DateInterval('P50D'))->format('Y-m-d'),
        ];

        return array_values(array_merge($fixed, $movable));
    }//end dutchHolidays()

    /**
     * Compute Easter Sunday for a year using the anonymous Gregorian algorithm.
     *
     * @param int $year The calendar year.
     *
     * @return DateTimeImmutable Easter Sunday.
     *
     * @SuppressWarnings(PHPMD.ShortVariable) — single-letter names are the
     * canonical notation of the anonymous Gregorian (Meeus/Jones/Butcher)
     * algorithm; renaming them would obscure the well-known formula.
     */
    private function easterDate(int $year): DateTimeImmutable
    {
        $a     = ($year % 19);
        $b     = intdiv($year, 100);
        $c     = ($year % 100);
        $d     = intdiv($b, 4);
        $e     = ($b % 4);
        $f     = intdiv(($b + 8), 25);
        $g     = intdiv((($b - $f) + 1), 3);
        $h     = ((((19 * $a) + $b - $d - $g) + 15) % 30);
        $i     = intdiv($c, 4);
        $k     = ($c % 4);
        $l     = (((32 + (2 * $e) + (2 * $i)) - $h - $k) % 7);
        $m     = intdiv(($a + (11 * $h) + (22 * $l)), 451);
        $month = intdiv(($h + $l - (7 * $m) + 114), 31);
        $day   = ((($h + $l - (7 * $m) + 114) % 31) + 1);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }//end easterDate()

    /**
     * Parse a date string into a DateTimeImmutable (date precision).
     *
     * @param string $value The date string.
     *
     * @return DateTimeImmutable The parsed date.
     *
     * @throws OCSBadRequestException When the value cannot be parsed.
     */
    private function parse(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((new DateTimeImmutable($value))->format('Y-m-d'));
        } catch (\Throwable $e) {
            throw new OCSBadRequestException('Invalid date: '.$value);
        }
    }//end parse()
}//end class
