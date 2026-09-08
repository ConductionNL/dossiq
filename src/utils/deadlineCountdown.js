// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The deadline column's countdown, as a pure function.
//
// A case list that prints a raw ISO date makes every reader do the same
// subtraction in their head, once per row, and get it wrong on the rows that
// matter — the ones already past. The countdown does the subtraction and says
// which side of the line the case is on.
//
// The arithmetic is DATE-ONLY on purpose. A deadline is a day, not an
// instant: comparing timestamps would call a case due today "0 days left" at
// 09:00 and "-1 days" at 17:00, and the row would flip to overdue during the
// working day it is still due on. Both sides are floored to their calendar
// day in LOCAL time (the same clock the reader's own "today" runs on) before
// the difference is taken.

import { translate as t } from '@nextcloud/l10n'

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
 * The deadline column's cell content: how many days are left, or how many
 * days the case is past due.
 *
 * A case with no deadline returns null — the cell then renders EMPTY, not
 * "0 days left". Zero is a claim about a deadline; a case that has none has
 * made no such claim, and printing zero would sort it visually beside the
 * cases due today.
 *
 * @param {string|number|Date|null|undefined} deadline The case deadline.
 * @param {Date} [now] The moment to count from (defaults to the current one).
 * @return {{days: number, overdue: boolean, text: string}|null} The cell
 *   content, or null when there is no readable deadline.
 *
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 */
export function deadlineCountdown(deadline, now = new Date()) {
	const days = daysUntilDeadline(deadline, now)
	if (days === null) {
		return null
	}

	if (days < 0) {
		const overdueBy = Math.abs(days)
		return {
			days: overdueBy,
			overdue: true,
			text:
				overdueBy === 1
					? t('dossiq', '1 day overdue')
					: t('dossiq', '{days} days overdue', { days: overdueBy }),
		}
	}

	return {
		days,
		overdue: false,
		text:
			days === 1
				? t('dossiq', '1 day left')
				: t('dossiq', '{days} days left', { days }),
	}
}
