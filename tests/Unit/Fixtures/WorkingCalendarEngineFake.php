<?php

/**
 * WorkingCalendarEngineFake fixture.
 *
 * Stands in for the two OpenRegister classes the Algemene termijnenwet roll
 * is consumed from: `Service\Flow\Timer\WorkingCalendarService`, which
 * resolves the administered calendar, and `Service\Flow\Timer\SlaCalculator`,
 * whose `businessDays` walk IS the roll. Both mirror the REAL signatures,
 * parameter names included, because every dossiq call site uses named
 * arguments: a fake that agreed with the caller instead of with the engine
 * could not fail.
 *
 * The walk mirrors SlaCalculator::walkForward() for whole days: from the start
 * instant, skip to the next midnight while the calendar says the cursor is not
 * a working day, and return the cursor once it is. Adding zero business days
 * therefore returns the same date on a working day and the next ordinary day
 * on a Saturday, a Sunday or a holiday.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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
use DateTimeInterface;
use RuntimeException;

if (class_exists(WorkingCalendarFake::class, false) === false) {
	/**
	 * The administered calendar: which days are non-working, and nothing else.
	 */
	class WorkingCalendarFake {
		/**
		 * Constructor.
		 *
		 * @param string $slug The calendar name.
		 * @param array<int, string> $nonWorkingDates Closures as `Y-m-d`.
		 * @param array<int, int> $workingWeekdays ISO weekdays that are working days.
		 */
		public function __construct(
			private readonly string $slug = 'nl-national',
			private readonly array $nonWorkingDates = [],
			private readonly array $workingWeekdays = [1, 2, 3, 4, 5],
		) {
		}//end __construct()

		/**
		 * The calendar's name.
		 *
		 * @return string The slug.
		 */
		public function getSlug(): string {
			return $this->slug;
		}//end getSlug()

		/**
		 * Whether a day is worked.
		 *
		 * @param DateTimeInterface $moment Any instant on the day.
		 *
		 * @return bool True on a working weekday that is not a closure.
		 */
		public function isWorkingDay(DateTimeInterface $moment): bool {
			if (in_array((int)$moment->format('N'), $this->workingWeekdays, true) === false) {
				return false;
			}

			return (in_array($moment->format('Y-m-d'), $this->nonWorkingDates, true) === false);
		}//end isWorkingDay()
	}//end class
}

if (class_exists(WorkingCalendarServiceFake::class, false) === false) {
	/**
	 * Mirrors WorkingCalendarService::resolve().
	 */
	class WorkingCalendarServiceFake {
		/**
		 * Recorded resolve() calls.
		 *
		 * @var array<int, array<string, string|null>>
		 */
		public array $calls = [];

		/**
		 * When true, resolve() throws the way an unknown calendar does.
		 *
		 * @var bool
		 */
		public bool $refuse = false;

		/**
		 * Constructor.
		 *
		 * @param WorkingCalendarFake $calendar The calendar this fake resolves to.
		 */
		public function __construct(private readonly WorkingCalendarFake $calendar) {
		}//end __construct()

		/**
		 * Resolve the calendar for a term.
		 *
		 * @param string|null $calendarSlug The calendar named on the term.
		 * @param string|null $organisation The subject's organisation.
		 *
		 * @return WorkingCalendarFake The calendar.
		 */
		public function resolve(?string $calendarSlug, ?string $organisation): WorkingCalendarFake {
			$this->calls[] = ['calendarSlug' => $calendarSlug, 'organisation' => $organisation];
			if ($this->refuse === true) {
				throw new RuntimeException('Working calendar does not exist');
			}

			return $this->calendar;
		}//end resolve()
	}//end class
}

if (class_exists(SlaCalculatorFake::class, false) === false) {
	/**
	 * Mirrors SlaCalculator::add(), businessDays branch, whole days.
	 */
	class SlaCalculatorFake {
		/**
		 * Recorded add() calls.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		public array $calls = [];

		/**
		 * Add business time to an instant.
		 *
		 * @param DateTimeInterface $from The start instant.
		 * @param float $value The amount.
		 * @param string $unit The unit.
		 * @param WorkingCalendarFake $calendar The resolved calendar.
		 *
		 * @return DateTimeImmutable The landing instant.
		 */
		public function add(DateTimeInterface $from, float $value, string $unit, WorkingCalendarFake $calendar): DateTimeImmutable {
			$this->calls[] = ['from' => $from, 'value' => $value, 'unit' => $unit, 'calendar' => $calendar->getSlug()];
			if ($unit !== 'businessDays') {
				throw new RuntimeException("Unit '" . $unit . "' is refused by this fake");
			}

			$cursor = DateTimeImmutable::createFromInterface($from);
			$remaining = $value;
			for ($walked = 0; $walked <= 366; $walked++) {
				if ($calendar->isWorkingDay($cursor) === true) {
					if ($remaining < 1.0) {
						return $cursor;
					}

					$remaining -= 1.0;
				}

				$cursor = $cursor->setTime(0, 0, 0)->modify('+1 day');
			}

			throw new RuntimeException('Business-day walk exceeded a year');
		}//end add()
	}//end class
}
