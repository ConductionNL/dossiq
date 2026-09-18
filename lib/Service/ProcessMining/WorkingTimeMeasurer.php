<?php

/**
 * Dossiq WorkingTimeMeasurer.
 *
 * How much of an interval the organisation actually worked, and how much wall
 * clock it spanned. One interval, two numbers, one calendar: the engine's.
 *
 * 🔑 WHY THE REPORT CANNOT KEEP COUNTING WALL CLOCK. A phase entered Friday at
 * 16:00 and left Monday at 09:00 spans 65 hours and the organisation worked a
 * fraction of them. Reported as 65, it says the team sat on the case for most
 * of three days. A manager comparing two teams on that number is comparing who
 * got the Friday afternoon cases, and the team that answers fastest on a Monday
 * morning looks the slowest.
 *
 * 🔑 WHY NO CALENDAR IS BUILT HERE. Company ADR-022: one calendar, the
 * engine's. dossiq already arms every termijn on
 * `OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService`
 * ({@see \OCA\Dossiq\Service\TermijnTimerService}), and a second calendar in
 * this app would answer a different question to the same person on the same
 * page: a deadline computed on the engine's feestdagen beside a dwell figure
 * computed on dossiq's own. So this class RESOLVES the engine's calendar and
 * ASKS it; it holds no weekday set, no holiday list and no working hours of its
 * own.
 *
 * HOW WORKING HOURS ARE DERIVED, AND WHAT THAT COSTS. The engine measures in
 * `businessDays`: a day the calendar calls non-working contributes nothing, and
 * a working day contributes the fraction of the calendar day the interval
 * covers. `SlaCalculator::convert()` turns that into hours at the calendar's
 * own `hoursPerWorkingDay`. What the engine's calendar does NOT carry is a
 * daily window: it knows Friday is a working day and that a working day is
 * eight hours, and not that the office closes at 17:00. So an interval running
 * from Friday 16:00 to Saturday counts eight wall hours of a working day and
 * therefore a third of a working day, rather than the one hour a 09:00-17:00
 * window would give. That is the engine's model, reported as the engine's
 * model. Correcting it belongs in openregister's `working-calendar-admin` (gap
 * row 8.12), and inventing a window here would be the second calendar ADR-022
 * exists to prevent.
 *
 * DEGRADATION. Without the engine the measurement falls back to
 * {@see \OCA\Dossiq\Service\WorkingDayCalculator} at eight hours per working
 * day, and SAYS SO through {@see self::CLOCK_FALLBACK}, which the page renders
 * in the column header. A number computed on a different clock under the same
 * label is how two teams end up compared on two measurements.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use Psr\Log\LoggerInterface;

/**
 * Measures one interval on the engine's organisation calendar, and on the wall
 * clock, and says which of the two it managed.
 *
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */
class WorkingTimeMeasurer {
	/**
	 * The engine's working-calendar resolver, resolved lazily.
	 *
	 * Spelled the same way {@see \OCA\Dossiq\Service\TermijnTimerService}
	 * spells it, on purpose: two literals for one engine class is how one of
	 * them survives a rename alone.
	 *
	 * @var string
	 */
	public const CALENDAR_SERVICE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService';

	/**
	 * The engine's business-time calculator, resolved lazily.
	 *
	 * @var string
	 */
	public const SLA_CALCULATOR_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\SlaCalculator';

	/**
	 * The unit whose walk skips non-working days.
	 *
	 * @var string
	 */
	public const UNIT_BUSINESS_DAYS = 'businessDays';

	/**
	 * The unit the report publishes.
	 *
	 * @var string
	 */
	public const UNIT_HOURS = 'hours';

	/**
	 * The clock marker for a measurement taken on the engine's calendar.
	 *
	 * @var string
	 */
	public const CLOCK_ENGINE = 'engine-calendar';

	/**
	 * The clock marker for the fallback: working days times eight.
	 *
	 * @var string
	 */
	public const CLOCK_FALLBACK = 'working-days-x-8';

	/**
	 * Hours one working day is worth when the engine is absent.
	 *
	 * @var float
	 */
	public const FALLBACK_HOURS_PER_DAY = 8.0;

	/**
	 * Whether the engine answered the last measurement, so a caller can label
	 * a whole table once rather than per row.
	 *
	 * @var bool
	 */
	private bool $engineAnswered = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsService       $settingsService  Lazy OpenRegister access.
	 * @param WorkingDayCalculator  $fallbackCalendar The documented fallback for an absent engine.
	 * @param LoggerInterface|null  $logger           Logger; optional so a test builds this by hand.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WorkingDayCalculator $fallbackCalendar,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Working hours and wall-clock hours for one interval.
	 *
	 * @param DateTimeImmutable $from           The interval start.
	 * @param DateTimeImmutable $to             The interval end; before the start yields zeros.
	 * @param string|null       $calendarSlug   The calendar to measure on, or null for the default.
	 * @param string|null       $organisation   The organisation whose calendar applies, or null.
	 *
	 * @return array{workingHours: float, wallHours: float, clock: string}
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function measure(
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?string $calendarSlug = null,
		?string $organisation = null,
	): array {
		$wallHours = (($to->getTimestamp() - $from->getTimestamp()) / 3600.0);
		if ($wallHours <= 0.0) {
			// An interval that does not move forward has no time in it on
			// either clock. Returning the engine clock here would claim a
			// measurement nobody took.
			$this->engineAnswered = true;

			return [
				'workingHours' => 0.0,
				'wallHours' => 0.0,
				'clock' => self::CLOCK_ENGINE,
			];
		}

		$engineHours = $this->engineHours(
			from: $from,
			to: $to,
			calendarSlug: $calendarSlug,
			organisation: $organisation
		);

		if ($engineHours !== null) {
			$this->engineAnswered = true;

			return [
				'workingHours' => round($engineHours, 2),
				'wallHours' => round($wallHours, 2),
				'clock' => self::CLOCK_ENGINE,
			];
		}

		$this->engineAnswered = false;

		return [
			'workingHours' => round($this->fallbackHours(from: $from, to: $to), 2),
			'wallHours' => round($wallHours, 2),
			'clock' => self::CLOCK_FALLBACK,
		];
	}//end measure()

	/**
	 * Working days and wall-clock days for one interval.
	 *
	 * The Processing time page counts a case's life in days, not hours, so it
	 * asks in days rather than dividing hours by a number this app would have
	 * to hold. Same calendar, same degradation, same marker.
	 *
	 * @param DateTimeImmutable $from         The interval start.
	 * @param DateTimeImmutable $to           The interval end.
	 * @param string|null       $calendarSlug The calendar to measure on, or null for the default.
	 * @param string|null       $organisation The organisation whose calendar applies, or null.
	 *
	 * @return array{workingDays: float, wallDays: float, clock: string}
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function measureDays(
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?string $calendarSlug = null,
		?string $organisation = null,
	): array {
		$wallDays = (($to->getTimestamp() - $from->getTimestamp()) / 86400.0);
		if ($wallDays <= 0.0) {
			$this->engineAnswered = true;

			return [
				'workingDays' => 0.0,
				'wallDays' => 0.0,
				'clock' => self::CLOCK_ENGINE,
			];
		}

		$calendars = $this->settingsService->getOpenRegisterClass(self::CALENDAR_SERVICE_CLASS);
		$calculator = $this->settingsService->getOpenRegisterClass(self::SLA_CALCULATOR_CLASS);
		if ($calendars !== null && $calculator !== null) {
			try {
				$calendar = $calendars->resolve(calendarSlug: $calendarSlug, organisation: $organisation);
				$businessDays = (float)$calculator->measure(
					from: $from,
					to: $to,
					unit: self::UNIT_BUSINESS_DAYS,
					calendar: $calendar
				);

				$this->engineAnswered = true;

				return [
					'workingDays' => round($businessDays, 2),
					'wallDays' => round($wallDays, 2),
					'clock' => self::CLOCK_ENGINE,
				];
			} catch (\Throwable $e) {
				$this->logger?->info(
					'Dossiq: the engine calendar could not measure a throughput interval, so the report falls back to counted working days: '
					. $e->getMessage()
				);
			}//end try
		}

		$this->engineAnswered = false;

		return [
			'workingDays' => (float)$this->fallbackCalendar->countWorkingDays(start: $from, end: $to),
			'wallDays' => round($wallDays, 2),
			'clock' => self::CLOCK_FALLBACK,
		];
	}//end measureDays()

	/**
	 * The clock the last measurement was taken on.
	 *
	 * A table labels its column once, and every row in it was measured the same
	 * way, so the caller reads this after measuring rather than carrying the
	 * marker through every row of its own aggregation.
	 *
	 * @return string One of {@see self::CLOCK_ENGINE}, {@see self::CLOCK_FALLBACK}.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function lastClock(): string {
		if ($this->engineAnswered === true) {
			return self::CLOCK_ENGINE;
		}

		return self::CLOCK_FALLBACK;
	}//end lastClock()

	/**
	 * Working hours from the engine, or null when the engine cannot answer.
	 *
	 * @param DateTimeImmutable $from         The interval start.
	 * @param DateTimeImmutable $to           The interval end.
	 * @param string|null       $calendarSlug The calendar to measure on.
	 * @param string|null       $organisation The organisation whose calendar applies.
	 *
	 * @return float|null The working hours, or null.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function engineHours(
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?string $calendarSlug,
		?string $organisation,
	): ?float {
		$calendars = $this->settingsService->getOpenRegisterClass(self::CALENDAR_SERVICE_CLASS);
		$calculator = $this->settingsService->getOpenRegisterClass(self::SLA_CALCULATOR_CLASS);
		if ($calendars === null || $calculator === null) {
			return null;
		}

		try {
			$calendar = $calendars->resolve(calendarSlug: $calendarSlug, organisation: $organisation);

			$businessDays = $calculator->measure(
				from: $from,
				to: $to,
				unit: self::UNIT_BUSINESS_DAYS,
				calendar: $calendar
			);

			// Converted at the CALENDAR's own hours per working day, not at a
			// number this app holds: an organisation on a 7.2-hour day is
			// measured on 7.2.
			return (float)$calculator->convert(
				value: (float)$businessDays,
				fromUnit: self::UNIT_BUSINESS_DAYS,
				toUnit: self::UNIT_HOURS,
				calendar: $calendar
			);
		} catch (\Throwable $e) {
			$this->logger?->info(
				'Dossiq: the engine calendar could not measure an interval, so the report falls back to working days x 8: '
				. $e->getMessage()
			);

			return null;
		}//end try
	}//end engineHours()

	/**
	 * Working hours without the engine: whole working days times eight.
	 *
	 * Deliberately coarse, and labelled so on the page. It is the D-7 posture
	 * termijnbewaking already takes for an absent engine: answer something
	 * defensible and say which clock it is on, rather than answer nothing or
	 * answer the wall clock under a working-hours heading.
	 *
	 * @param DateTimeImmutable $from The interval start.
	 * @param DateTimeImmutable $to   The interval end.
	 *
	 * @return float The working hours.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function fallbackHours(DateTimeImmutable $from, DateTimeImmutable $to): float {
		$days = $this->fallbackCalendar->countWorkingDays(start: $from, end: $to);

		return ((float)$days * self::FALLBACK_HOURS_PER_DAY);
	}//end fallbackHours()

}//end class
