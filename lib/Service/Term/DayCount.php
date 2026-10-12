<?php

/**
 * Dossiq DayCount.
 *
 * Counts the calendar days between two moments, in one place.
 *
 * WHY ONE PLACE. Thirty-one call sites each wrote their own
 * `$a->diff($b)->days`, and the two spellings PHP offers do not mean the same
 * thing: `->days` is never negative, and `->format('%r%a')` carries the sign.
 * A site that wanted "days left" and wrote the first got a positive number
 * for a deadline that had passed, and then had to remember to compare the two
 * dates again to put the sign back. Some did and some compared the other way
 * round. Naming the two questions here, {@see between()} and {@see signed()},
 * makes the choice visible at the call site, and
 * `tests/Unit/Architecture/DayCountIsTheOnlyDiffTest.php` refuses a new
 * `->diff(` anywhere else under `lib/`.
 *
 * WHAT IT COUNTS. Whole days between the two instants as PHP's own
 * `DateTimeInterface::diff()` counts them: 23 hours is zero days, and each
 * instant is read in its own zone. It deliberately normalises nothing. A
 * caller that means "calendar dates apart" sets both sides to midnight in one
 * zone first, as the timers do, and a caller that means working days asks
 * {@see \OCA\Dossiq\Service\Termijn\WorkingDayRoll::daysBetween()}, which
 * answers from the administered calendar and uses this class for its calendar
 * mode.
 *
 * WHY IT HOLDS NOTHING. No clock, no zone, no calendar: both moments are
 * handed in, so the count is the same in a test as in production and the
 * class can be a default collaborator (`new DayCount()`) without widening
 * anybody's constructor contract.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use DateTimeInterface;

/**
 * The calendar days between two moments, with or without a sign.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DayCount {
	/**
	 * How many whole days lie between two moments, never negative.
	 *
	 * The answer for a span, an age or a length, where the order of the two
	 * moments carries no meaning. For "days left" ask {@see signed()}: this
	 * method answers 3 for a deadline three days ahead and for one three days
	 * gone.
	 *
	 * @param DateTimeInterface $from One moment.
	 * @param DateTimeInterface $to   The other.
	 *
	 * @return int Whole days, zero or more.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function between(DateTimeInterface $from, DateTimeInterface $to): int {
		return (int)$from->diff($to)->days;
	}//end between()

	/**
	 * How many whole days the second moment lies after the first.
	 *
	 * Negative when it lies before. The answer for days left until a deadline
	 * (`signed(from: $today, to: $deadline)`, negative once it has passed) and
	 * for days overdue (`signed(from: $deadline, to: $today)`).
	 *
	 * @param DateTimeInterface $from The moment counted from.
	 * @param DateTimeInterface $to   The moment counted to.
	 *
	 * @return int Whole days, negative when `$to` precedes `$from`.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public function signed(DateTimeInterface $from, DateTimeInterface $to): int {
		return (int)$from->diff($to)->format('%r%a');
	}//end signed()
}//end class
