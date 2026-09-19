<?php

/**
 * Dossiq WorkingClock.
 *
 * The one place a dossiq report decides which clock a duration is on.
 *
 * WHY THIS EXISTS. Every doorlooptijd number in this app was seconds divided
 * by 3600. A phase entered on Friday at 16:00 and left on Monday at 09:00 read
 * 65 hours; the organisation worked one of them. The page did not say which
 * clock it used, so a manager comparing two teams was comparing who drew the
 * Friday afternoon cases.
 *
 * WHY IT IS A SEAM AND NOT A CALCULATION. The working calendar is the
 * engine's, per ADR-022, and so is the arithmetic over it:
 * `SlaCalculator::elapsedBusinessHours()` walks each working day's window
 * (openregister#3868). A second implementation here would disagree with the
 * deadline badge the engine already decided, the first time one of them was
 * wrong. So this class resolves the engine BY NAME, exactly as
 * {@see \OCA\Dossiq\Service\Transitions\SideEffectDispatcher} resolves the
 * node catalogue: dossiq declares no hard dependency on OpenRegister and an
 * instance without it must still draw its reports.
 *
 * 🔴 THE DEGRADED CLOCK IS NAMED, NOT HIDDEN. Without the engine the working
 * hours are working days times eight, which is a different measurement and
 * not a slightly worse one: it counts a case that sat one hour on Friday and
 * one on Monday as sixteen. {@see self::clock()} answers which of the two the
 * numbers are on, and the report prints it in the column header. A degraded
 * number that looks like the real one is exactly the failure this change
 * exists to end, so it is never served silently.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\WorkingDayCalculator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Elapsed working time, on the engine's calendar when there is one.
 *
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */
class WorkingClock {
	/**
	 * The engine's calculator, resolved by name.
	 *
	 * @var string
	 */
	public const ENGINE_CALCULATOR = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\SlaCalculator';

	/**
	 * The engine's calendar resolver, resolved by name.
	 *
	 * @var string
	 */
	public const ENGINE_CALENDARS = 'OCA\\OpenRegister\\Service\\Flow\\Timer\\WorkingCalendarService';

	/**
	 * The clock a number is on when the engine answered.
	 *
	 * @var string
	 */
	public const CLOCK_CALENDAR = 'working-calendar';

	/**
	 * The clock a number is on when it did not.
	 *
	 * @var string
	 */
	public const CLOCK_DAYS_TIMES_EIGHT = 'working-days-times-eight';

	/**
	 * The clock a number is on when nothing measured working time at all.
	 *
	 * An analyzer built without a clock reports the wall clock in both
	 * columns, which is what this app did before this change. It says so
	 * here rather than labelling the wall clock as working hours: a number
	 * that is honest about being unconverted is usable, and one that claims
	 * a conversion it did not make is not.
	 *
	 * @var string
	 */
	public const CLOCK_WALL = 'wall-clock';

	/**
	 * Hours in one working day, for the degraded clock only.
	 *
	 * Deliberately not read from anywhere: the degraded path exists because
	 * there is no calendar to read, and a constant that pretends otherwise
	 * would be the second implementation this class exists to avoid.
	 *
	 * @var float
	 */
	private const DEGRADED_HOURS_PER_DAY = 8.0;

	/**
	 * Whether the engine has been looked for yet.
	 *
	 * @var boolean
	 */
	private bool $resolved = false;

	/**
	 * The engine's calculator, or null when absent.
	 *
	 * @var object|null
	 */
	private ?object $calculator = null;

	/**
	 * The organisation's calendar, or null when absent.
	 *
	 * @var object|null
	 */
	private ?object $calendar = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container The app container, for the by-name lookup.
	 * @param WorkingDayCalculator $days      The degraded clock's day counter.
	 * @param LoggerInterface|null $logger    Where an unavailable engine is noted.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly WorkingDayCalculator $days,
		private readonly ?LoggerInterface $logger = null,
	) {

	}//end __construct()

	/**
	 * Which clock this instance measures on.
	 *
	 * Answered before any measurement so a page can label its columns without
	 * first computing a number it might then have to disclaim.
	 *
	 * @return string One of the CLOCK_* constants.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function clock(): string {
		$this->resolve();

		if ($this->calculator === null || $this->calendar === null) {
			return self::CLOCK_DAYS_TIMES_EIGHT;
		}

		return self::CLOCK_CALENDAR;
	}//end clock()

	/**
	 * The two numbers one interval is worth.
	 *
	 * BOTH, ALWAYS, AND IN THAT ORDER. The headline is working hours because
	 * that is what the organisation spent; wall-clock hours stay beside it
	 * because that is what the applicant waited, and a report that dropped
	 * the second would stop being able to answer "how long did this take"
	 * for anybody outside the office.
	 *
	 * @param DateTimeInterface $from The interval's start.
	 * @param DateTimeInterface $to   Its end. Before the start reads as zero:
	 *                                a negative dwell is a record out of
	 *                                order, not a case that went backwards.
	 *
	 * @return array{workingHours: float, wallHours: float} The two numbers.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function hoursBetween(DateTimeInterface $from, DateTimeInterface $to): array {
		$wall = (($to->getTimestamp() - $from->getTimestamp()) / 3600.0);
		if ($wall < 0.0) {
			return ['workingHours' => 0.0, 'wallHours' => 0.0];
		}

		return [
			'workingHours' => $this->workingHoursBetween(from: $from, to: $to),
			'wallHours' => $wall,
		];
	}//end hoursBetween()

	/**
	 * Elapsed working hours, from the engine or from the degraded clock.
	 *
	 * @param DateTimeInterface $from The start.
	 * @param DateTimeInterface $to   The end, at or after the start.
	 *
	 * @return float The working hours.
	 */
	private function workingHoursBetween(DateTimeInterface $from, DateTimeInterface $to): float {
		$this->resolve();

		if ($this->calculator !== null && $this->calendar !== null) {
			try {
				return (float)$this->calculator->elapsedBusinessHours(
					from: $from,
					to: $to,
					calendar: $this->calendar
				);
			} catch (Throwable $e) {
				// A calculator that refused this one interval has not stopped
				// being the calculator, so the clock is not downgraded for
				// the whole report on the strength of one bad pair of dates.
				$this->logger?->warning(
					'WorkingClock: the engine refused an interval; counting it on working days',
					['error' => $e->getMessage(), 'from' => $from->format('c'), 'to' => $to->format('c')],
				);
			}
		}

		return ($this->degradedWorkingDays(from: $from, to: $to) * self::DEGRADED_HOURS_PER_DAY);
	}//end workingHoursBetween()

	/**
	 * Working days between two instants, for the degraded clock.
	 *
	 * @param DateTimeInterface $from The start.
	 * @param DateTimeInterface $to   The end.
	 *
	 * @return float The days.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `DateTimeImmutable::createFromInterface()`
	 *  is php's own conversion between two shapes of the same value, not a
	 *  collaborator. There is nothing here to inject or replace: the alternative
	 *  is reformatting the instant through a string and reparsing it, which is
	 *  how a timezone gets lost. The calendar this method actually depends on is
	 *  `$this->days`, which is injected.
	 */
	private function degradedWorkingDays(DateTimeInterface $from, DateTimeInterface $to): float {
		return (float)$this->days->countWorkingDays(
			start: DateTimeImmutable::createFromInterface($from),
			end: DateTimeImmutable::createFromInterface($to)
		);
	}//end degradedWorkingDays()

	/**
	 * Look for the engine, once.
	 *
	 * @return void
	 */
	private function resolve(): void {
		if ($this->resolved === true) {
			return;
		}

		$this->resolved = true;

		if (class_exists(self::ENGINE_CALCULATOR) === false || class_exists(self::ENGINE_CALENDARS) === false) {
			return;
		}

		try {
			$this->calculator = $this->container->get(self::ENGINE_CALCULATOR);
			$calendars = $this->container->get(self::ENGINE_CALENDARS);
			// No slug and no organisation: the instance's own default, which
			// is what an organisation-wide report is about. A per-case-type
			// calendar is a later question and a different column.
			$this->calendar = $calendars->resolve(calendarSlug: null, organisation: null);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'WorkingClock: the engine calendar is unavailable; reporting on working days times eight',
				['error' => $e->getMessage()],
			);
			$this->calculator = null;
			$this->calendar = null;
		}
	}//end resolve()
}//end class
