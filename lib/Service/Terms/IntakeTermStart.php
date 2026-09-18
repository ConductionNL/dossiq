<?php

/**
 * When a request arrived, and when its clock actually starts.
 *
 * 🔑 SOMEBODY FILES ON SUNDAY EVENING AND COUNTS EIGHT WEEKS FROM THE MOMENT
 * THEY PRESSED SEND. The municipality counts from Monday. Nothing today tells
 * them, so the first either party hears of the difference is a complaint about
 * a deadline one of them has already missed by their own reckoning.
 *
 * 🔑 BOTH MOMENTS ARE STORED, AND THE FLAG WITH THEM (D-1, D-2). The flag is
 * derivable, and deriving it on read is exactly what makes it useless later: a
 * handler answering a complaint in March has to be able to say what the
 * citizen was TOLD in January, and the calendar has moved since. Frappe's
 * measured behaviour is the same shape, a stamp at create.
 *
 * 🔴 ONE CALENDAR ANSWERS THE CONFIRMATION AND THE TERM (D-3). This class
 * takes the same `WorkingDayCalculator` the term is counted on, rather than
 * asking a second source what a working day is. Two sources eventually
 * disagree, and the disagreement surfaces as a citizen quoting a date the
 * system does not recognise.
 *
 * 🔴 THE WINDOW IS ADMINISTERED, NOT ASSUMED. A gemeente whose desk opens at
 * 08:30 and one that opens at 09:00 must not need a code change, and a
 * misconfigured window must not push every request to the next day in silence:
 * a start at or after the end reads as a day with no window at all and is
 * refused back to the default rather than applied.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Terms
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
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Terms;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCP\IAppConfig;

/**
 * Stamps the two moments and the flag on a case at intake.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStart {
	/**
	 * The case field holding when the submission arrived.
	 *
	 * @var string
	 */
	public const RECEIVED_AT = 'receivedAt';

	/**
	 * The case field holding the first working moment the term counts from.
	 *
	 * @var string
	 */
	public const TERM_STARTS_AT = 'termStartsAt';

	/**
	 * The case field saying the two differ.
	 *
	 * @var string
	 */
	public const OUTSIDE_HOURS = 'receivedOutsideWorkingHours';

	/**
	 * When the desk opens, as `H:i`, unless an administrator says otherwise.
	 *
	 * @var string
	 */
	public const DEFAULT_OPENS = '09:00';

	/**
	 * When it closes.
	 *
	 * @var string
	 */
	public const DEFAULT_CLOSES = '17:00';

	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $calendar The calendar the TERM is counted on.
	 * @param IAppConfig $appConfig Holds the administered working window.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $calendar,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The three facts, for one arrival.
	 *
	 * @param DateTimeImmutable $receivedAt When the submission arrived.
	 *
	 * @return array{receivedAt: string, termStartsAt: string, receivedOutsideWorkingHours: bool}
	 *         The stamp, as ISO 8601 moments.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function stampFor(DateTimeImmutable $receivedAt): array {
		$startsAt = $this->firstWorkingMomentFrom(moment: $receivedAt);

		return [
			self::RECEIVED_AT => $receivedAt->format('c'),
			self::TERM_STARTS_AT => $startsAt->format('c'),
			// Compared as moments rather than as strings: two formats of one
			// instant would read as a difference and put the extra sentence on
			// a confirmation that does not need it.
			self::OUTSIDE_HOURS => ($startsAt->getTimestamp() !== $receivedAt->getTimestamp()),
		];
	}//end stampFor()

	/**
	 * The first working moment at or after a given one.
	 *
	 * @param DateTimeImmutable $moment The arrival.
	 *
	 * @return DateTimeImmutable The moment the term counts from.
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function firstWorkingMomentFrom(DateTimeImmutable $moment): DateTimeImmutable {
		[$opens, $closes] = $this->window();

		if ($this->calendar->isWorkingDay($moment) === true) {
			$opening = $this->at(day: $moment, time: $opens);
			if ($moment < $opening) {
				// Filed before the desk opened: the clock starts when it does,
				// on the same day.
				return $opening;
			}

			$closing = $this->at(day: $moment, time: $closes);
			if ($moment < $closing) {
				return $moment;
			}

			// Past closing on a working day. `nextWorkingDay()` is INCLUSIVE:
			// asked about a working day it answers that same day, which would
			// start the clock at an opening time that has already gone. The
			// day is advanced first, and the calendar then skips whatever
			// weekend or holiday follows.
			return $this->at(day: $this->calendar->nextWorkingDay($moment->modify('+1 day')), time: $opens);
		}

		// A day that is not a working day at all: the inclusive answer is the
		// right one, because the search starts on a day the calendar refuses.
		return $this->at(day: $this->calendar->nextWorkingDay($moment), time: $opens);
	}//end firstWorkingMomentFrom()

	/**
	 * The administered working window, as `H:i` pairs.
	 *
	 * A window whose start is at or after its end is a day with no working
	 * moment in it, which would push every request to the next day for ever
	 * and look like a calendar problem. It falls back to the default rather
	 * than being applied.
	 *
	 * @return array{0: string, 1: string} The opening and closing times.
	 */
	private function window(): array {
		$opens = trim($this->appConfig->getValueString(Application::APP_ID, 'working_hours_start', self::DEFAULT_OPENS));
		$closes = trim($this->appConfig->getValueString(Application::APP_ID, 'working_hours_end', self::DEFAULT_CLOSES));

		if (preg_match('/^\d{1,2}:\d{2}$/', $opens) !== 1 || preg_match('/^\d{1,2}:\d{2}$/', $closes) !== 1) {
			return [self::DEFAULT_OPENS, self::DEFAULT_CLOSES];
		}

		if (strtotime($opens) >= strtotime($closes)) {
			return [self::DEFAULT_OPENS, self::DEFAULT_CLOSES];
		}

		return [$opens, $closes];
	}//end window()

	/**
	 * One day at one clock time, in that day's own time zone.
	 *
	 * @param DateTimeImmutable $day The day.
	 * @param string $time The time as `H:i`.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function at(DateTimeImmutable $day, string $time): DateTimeImmutable {
		[$hour, $minute] = array_map('intval', explode(':', $time));

		return $day->setTime($hour, $minute, 0);
	}//end at()
}//end class
