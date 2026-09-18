<?php

/**
 * Dossiq WorkingDayRoll.
 *
 * Moves a term's end date off a day the organisation does not work, when the
 * definition says to.
 *
 * WHY THIS IS STATUTORY AND NOT COSMETIC. The Algemene termijnenwet, art. 1,
 * says a term ending on a Saturday, Sunday or recognised holiday ends on the
 * next ordinary day instead. `TermijnService` computed `+N days` and stopped,
 * so roughly a third of the terms dossiq stores landed on a day the law says
 * must move. Nobody sees that on screen: the date is a plausible date, the
 * badge counts down to it, and the applicant is told a deadline that is not
 * the one they have.
 *
 * 🔴 THE DAYS IT MOVES OVER ARE THE ENGINE'S, NOT A LIST HERE. Which days are
 * not worked is the working calendar's answer, per ADR-022, and this class
 * asks `SlaCalculator::add(0 business days)`: on a working day that returns
 * the same instant, and on a closed day it walks to the start of the next
 * working one, which is exactly the roll. A holiday list in dossiq would be a
 * second calendar to keep in step with the first, which is what
 * `NoLocalCalendarTest` now refuses.
 *
 * 🔴 IT ROLLS FORWARD ONLY, AND NEVER SHORTENS A TERM. The Awt moves a
 * deadline later, never earlier: a term that already ends on an ordinary day
 * is returned untouched, and a rolled one is always at or after the date it
 * came from. A roll that could move a date backwards would take days off a
 * citizen's right of reply.
 *
 * WHAT IT DOES NOT DO. It does not decide WHETHER to roll. That is
 * `deadlineDefinition.rollToWorkingDay`, off by default, because the default
 * is a legal question: Awt art. 3 names the recognised holidays and somebody
 * qualified has to confirm the list against these case types before every
 * Algemene termijnenwet term starts moving.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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

namespace OCA\Dossiq\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rolls a date to the next ordinary day, on the engine's calendar.
 *
 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */
class WorkingDayRoll {
	/*
	 * 🔴 THIS CLASS DOES NOT READ `rollToWorkingDay`, AND THAT IS THE FIX.
	 *
	 * It briefly did, alongside `TermijnTimerService::rollEnabled()`, and the
	 * two disagreed: an absent flag read here as OFF and there as ON, because
	 * Awt art. 1 applies by law and not by configuration. One case could then
	 * get two different end dates depending on which path reached it, and
	 * both dates looked perfectly ordinary on the page.
	 *
	 * So the decision lives in ONE place, `rollEnabled()`, and the end-date
	 * roll in one call, `rollTermEndFor()`. What is left here is the calendar
	 * accessor the intake start uses: it answers whether a calendar is
	 * answering at all, and what the first working moment at or after an
	 * instant is. Neither of those is a policy.
	 */

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
	 * The unit whose zero-length walk IS the roll.
	 *
	 * Adding nothing in business days returns the same instant on a working
	 * day and the start of the next working day otherwise. Naming the trick
	 * here rather than inlining the string is the difference between a reader
	 * seeing a rule and a reader seeing a magic zero.
	 *
	 * @var string
	 */
	public const UNIT_BUSINESS_DAYS = 'businessDays';

	/**
	 * The engine's word for an ordinary day count.
	 */
	public const UNIT_CALENDAR_DAYS = 'calendarDays';

	/**
	 * The two modes a term can count in. The default is calendar days because
	 * an Awb beslistermijn counts them, and shipping the property must move no
	 * date anybody is already counting on.
	 */
	public const MODE_CALENDAR_DAYS = 'calendarDays';

	/**
	 * A service norm, an internal handling term or a KCC callback counts the
	 * days the organisation actually works.
	 */
	public const MODE_WORKING_DAYS = 'workingDays';

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
	 * @param SettingsService      $settings The by-name lookup into OpenRegister.
	 * @param LoggerInterface|null $logger   Where an unavailable calendar is noted.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ?LoggerInterface $logger = null,
	) {

	}//end __construct()

	/**
	 * Whether the organisation calendar is answering.
	 *
	 * Asked before the roll rather than inferred from its result, because a
	 * date that did not move and a date that could not be checked look
	 * identical.
	 *
	 * @return boolean True when the engine calendar resolved.
	 *
	 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function isAvailable(): bool {
		$this->resolve();

		return ($this->calculator !== null && $this->calendar !== null);
	}//end isAvailable()

	/**
	 * A date this many days after the one given, counted in one mode.
	 *
	 * The counting mode is the term's own (`counting-mode-per-term`, row
	 * Q8.16): an Awb beslistermijn counts calendar days, and a service norm or
	 * a KCC callback counts working days. Both answers come from here rather
	 * than from two places, because the SLA the engine arms and the
	 * `endDateCalculated` the case stores have to agree at day granularity or
	 * the badge and the timer count down to different dates.
	 *
	 * Working days are the ENGINE'S, per ADR-022 and for the same reason the
	 * roll below is. When the calendar does not answer, the caller is told so
	 * rather than handed a calendar-day date that looks like a working-day
	 * one: null is the answer, and `TermijnService` degrades and logs.
	 *
	 * @param DateTimeImmutable $start The day the term starts.
	 * @param int               $days  How many days it runs.
	 * @param string            $mode  `calendarDays` or `workingDays`.
	 *
	 * @return DateTimeImmutable|null The end date, or null when working days
	 *                                were asked for and the calendar is absent.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function endAfter(DateTimeImmutable $start, int $days, string $mode): ?DateTimeImmutable {
		if ($mode !== self::MODE_WORKING_DAYS) {
			return $start->modify('+' . $days . ' days');
		}

		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return $this->calculator->add(
				from: $start,
				value: (float)$days,
				unit: self::UNIT_BUSINESS_DAYS,
				calendar: $this->calendar
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Dossiq termijn: the organisation calendar refused a working-day end date',
				['error' => $e->getMessage()],
			);

			return null;
		}
	}//end endAfter()

	/**
	 * How many days lie between two dates, counted in one mode.
	 *
	 * The counterpart of {@see endAfter()}, and the reason the timer's SLA
	 * VALUE moves with its unit: a ten working day term spans fourteen
	 * calendar days, so arming `value: 14, unit: businessDays` would give the
	 * case two weeks it is not entitled to.
	 *
	 * @param DateTimeImmutable $from The start.
	 * @param DateTimeImmutable $to   The end.
	 * @param string            $mode `calendarDays` or `workingDays`.
	 *
	 * @return int|null The span, or null when working days were asked for and
	 *                  the calendar is absent.
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to, string $mode): ?int {
		if ($mode !== self::MODE_WORKING_DAYS) {
			return (int)$from->diff($to)->days;
		}

		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			return (int)round(
				$this->calculator->measure(
					from: $from,
					to: $to,
					unit: self::UNIT_BUSINESS_DAYS,
					calendar: $this->calendar
				)
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Dossiq termijn: the organisation calendar refused a working-day span',
				['error' => $e->getMessage()],
			);

			return null;
		}
	}//end daysBetween()

	/**
	 * The first working moment at or after this one, or null.
	 *
	 * The same `add(0 business days)` trick roll() uses, and deliberately NOT
	 * a second implementation of it: on a working day the engine answers the
	 * instant given, and on a closed day it walks to the start of the next
	 * working one. That is exactly "when does the clock start" for a request
	 * that arrived on a Sunday evening.
	 *
	 * 🔴 NULL IS AN ANSWER AND IT IS NOT "AT ONCE". A calendar that cannot be
	 * reached does not know whether the moment was inside the working week, so
	 * returning the moment unchanged would stamp `receivedOutsideWorkingHours:
	 * false` on a Sunday filing and tell the applicant a start date nobody
	 * computed. The caller leaves the stamp OFF instead, which is visibly
	 * absent rather than confidently wrong.
	 *
	 * ⚠️ IT ANSWERS AT DAY GRANULARITY, because that is all the engine
	 * calendar holds today: `WorkingCalendar` carries working weekdays,
	 * non-working dates and hours per day, and no intra-day window. So a
	 * request filed at 23:00 on a Tuesday reads as inside the working week.
	 * The window is openregister `working-calendar-admin`, still to be
	 * specified; naming it here is cheaper than a reader re-deriving the gap
	 * from a surprising test.
	 *
	 * @param DateTimeImmutable $moment The moment the request arrived.
	 *
	 * @return DateTimeImmutable|null The first working moment, or null when the calendar did not answer.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function firstWorkingMomentAtOrAfter(DateTimeImmutable $moment): ?DateTimeImmutable {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			$answered = $this->calculator->add(
				from: $moment,
				value: 0.0,
				unit: self::UNIT_BUSINESS_DAYS,
				calendar: $this->calendar
			);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Dossiq termijn: the organisation calendar refused to name the first working moment',
				['moment' => $moment->format('c'), 'error' => $e->getMessage()],
			);

			return null;
		}

		// FORWARD ONLY. A calendar answering
		// earlier than the moment asked about would start a citizen's term
		// before their request arrived.
		if ($answered < $moment) {
			$this->logger?->warning(
				'Dossiq termijn: the organisation calendar named a working moment before the one asked about',
				['moment' => $moment->format('c'), 'answered' => $answered->format('c')],
			);

			return null;
		}

		return $answered;
	}//end firstWorkingMomentAtOrAfter()

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
			$this->calculator = $this->settings->getOpenRegisterClass(self::ENGINE_CALCULATOR);
			$calendars = $this->settings->getOpenRegisterClass(self::ENGINE_CALENDARS);
			if ($this->calculator === null || $calendars === null) {
				$this->calculator = null;
				return;
			}

			$this->calendar = $calendars->resolve(null, null);
		} catch (Throwable $e) {
			$this->logger?->warning(
				'Dossiq termijn: the organisation calendar is unavailable',
				['error' => $e->getMessage()],
			);
			$this->calculator = null;
			$this->calendar = null;
		}
	}//end resolve()
}//end class
