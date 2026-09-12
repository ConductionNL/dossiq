/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Deadline column's countdown.
 *
 * The arithmetic is worth pinning down because two of its cases are ones a
 * reader notices only when they are wrong. A case due TODAY must read "0
 * days left" all day, not flip to overdue at some hour of the afternoon —
 * which is what a timestamp comparison does. And a case with NO deadline
 * must render nothing, not "0 days left": zero is a claim about a deadline,
 * and a case that has none has made no such claim.
 *
 * @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	daysUntilDeadline,
	deadlineCountdown,
} from '../../src/utils/deadlineCountdown.js'

/** A fixed "now" so the suite does not drift with the wall clock. */
const NOW = new Date(2026, 8, 8, 14, 30)

/**
 * A date-only string N days from the fixed now.
 *
 * @param {number} offset Days ahead (negative for the past).
 * @return {string} `YYYY-MM-DD`.
 */
function day(offset) {
	const d = new Date(NOW.getFullYear(), NOW.getMonth(), NOW.getDate() + offset)
	const month = String(d.getMonth() + 1).padStart(2, '0')
	const date = String(d.getDate()).padStart(2, '0')
	return `${d.getFullYear()}-${month}-${date}`
}

describe('deadlineCountdown', () => {
	it('reads 3 days left for a case due in 3 days', () => {
		expect(deadlineCountdown(day(3), NOW)).toEqual({
			days: 3,
			overdue: false,
			text: '3 days left',
		})
	})

	it('reads 2 days overdue for a deadline 2 days ago, and says so', () => {
		expect(deadlineCountdown(day(-2), NOW)).toEqual({
			days: 2,
			overdue: true,
			text: '2 days overdue',
		})
	})

	it('reads 0 days left on the day itself, whatever the hour', () => {
		const morning = new Date(2026, 8, 8, 7, 0)
		const evening = new Date(2026, 8, 8, 23, 45)

		expect(deadlineCountdown(day(0), morning).text).toBe('0 days left')
		expect(deadlineCountdown(day(0), evening).text).toBe('0 days left')
		expect(deadlineCountdown(day(0), evening).overdue).toBe(false)
	})

	it('says day, not days, at one', () => {
		expect(deadlineCountdown(day(1), NOW).text).toBe('1 day left')
		expect(deadlineCountdown(day(-1), NOW).text).toBe('1 day overdue')
	})

	it('renders nothing at all for a case without a deadline', () => {
		expect(deadlineCountdown(null, NOW)).toBeNull()
		expect(deadlineCountdown(undefined, NOW)).toBeNull()
		expect(deadlineCountdown('', NOW)).toBeNull()
	})

	it('renders nothing for a value that is not a date', () => {
		expect(deadlineCountdown('soon', NOW)).toBeNull()
	})

	it('reads a full ISO instant as the calendar day it falls on', () => {
		expect(deadlineCountdown('2026-09-11T22:00:00+02:00', NOW).text).toBe(
			'3 days left',
		)
	})
})

describe('daysUntilDeadline', () => {
	it('counts a date-only deadline as a LOCAL day', () => {
		// `new Date('2026-09-11')` is UTC midnight, which is 2026-09-10 for
		// every reader west of Greenwich — the deadline would silently read
		// one day nearer than it is.
		expect(daysUntilDeadline('2026-09-11', NOW)).toBe(3)
	})

	it('is negative once the deadline has passed', () => {
		expect(daysUntilDeadline(day(-5), NOW)).toBe(-5)
	})

	it('is null when there is nothing to count', () => {
		expect(daysUntilDeadline(null, NOW)).toBeNull()
	})
})
