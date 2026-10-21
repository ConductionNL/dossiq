// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Days until a deadline and whether it has passed, as pure functions.
//
// The arithmetic is DATE-ONLY on purpose. A deadline is a day, not an
// instant: comparing timestamps would call a case due today "0 days left" at
// 09:00 and "-1 days" at 17:00, and the row would flip to overdue during the
// working day it is still due on. Both sides are floored to their calendar
// day in LOCAL time (the same clock the reader's own "today" runs on) before
// the difference is taken.

/**
 * The local calendar day of a value, as a timestamp at midnight — or null
 * when the value is absent or does not parse as a date.
 *
 * Accepts both a date-only `YYYY-MM-DD` and a full ISO instant, because
 * OpenRegister stores `deadline` as `format: date` but a calculated or
 * imported row can carry a time. A date-only string is read as a LOCAL day
 * rather than through `new Date('2026-09-11')`, which the spec defines as
 * UTC midnight and which therefore lands on the previous day for any reader
 * west of Greenwich.
 *
 * @param {string|number|Date|null|undefined} value The candidate date.
 * @return {number|null} Milliseconds at local midnight, or null.
 */
function localMidnight(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}

	if (typeof value === 'string') {
		const dateOnly = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
		if (dateOnly) {
			const [, year, month, day] = dateOnly
			return new Date(Number(year), Number(month) - 1, Number(day)).getTime()
		}
	}

	const parsed = value instanceof Date ? value : new Date(value)
	if (Number.isNaN(parsed.getTime())) {
		return null
	}
	return new Date(
		parsed.getFullYear(),
		parsed.getMonth(),
		parsed.getDate(),
	).getTime()
}

/**
 * Whole days from `now`'s calendar day to `deadline`'s calendar day.
 *
 * @param {string|number|Date|null|undefined} deadline The case deadline.
 * @param {Date} [now] The moment to count from (defaults to the current one).
 * @return {number|null} Positive when the deadline is ahead, negative when it
 *   has passed, 0 on the day itself; null when there is no readable deadline.
 *
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 */
export function daysUntilDeadline(deadline, now = new Date()) {
	const due = localMidnight(deadline)
	const today = localMidnight(now)
	if (due === null || today === null) {
		return null
	}
	// Whole days, not a rounded fraction: both operands are already floored to
	// midnight, so the quotient is exact except across a DST boundary, where
	// the hour lost or gained would otherwise round a day away.
	return Math.round((due - today) / 86400000)
}

/**
 * Whether a deadline has passed: from the day AFTER it, never on it.
 *
 * THE ONE RULE FOR EVERY SURFACE (REQ-OTE-07). A term is not late on its last
 * day (REQ-TERM-DAY-001), and five components used to decide that each in
 * their own way: two compared instants and called a case late from midnight
 * UTC of its last day. They all ask here now.
 *
 * @param {string|number|Date|null|undefined} deadline The deadline.
 * @param {Date} [now] The moment to judge against (defaults to the current one).
 * @return {boolean} True only when the deadline's day is before today; false
 *   when there is no readable deadline.
 *
 * @spec openspec/changes/one-term-engine/specs/my-work/spec.md#requirement-one-front-end-helper-decides-days-left-and-overdue-req-ote-07
 */
export function isOverdue(deadline, now = new Date()) {
	const days = daysUntilDeadline(deadline, now)
	return days !== null && days < 0
}
