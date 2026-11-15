// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The case helpers that read a deadline: parsing the stored arrays, adding a
 * duration to a start date and the countdown a case list shows.
 *
 * @spec openspec/specs/case-management/spec.md
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	calculateDeadline,
	formatDate,
	formatDeadlineCountdown,
	parseJsonArray,
} from '../../src/utils/caseHelpers.js'

describe('parseJsonArray', () => {
	it('passes an array through as the same array', () => {
		const value = [{ id: 1 }]
		expect(parseJsonArray(value)).toBe(value)
	})

	it.each([
		['[1,2]', [1, 2]],
		['{"a":1}', []],
		['not json', []],
		['', []],
		[null, []],
		[undefined, []],
		[42, []],
	])('reads %s as %j', (value, expected) => {
		expect(parseJsonArray(value)).toEqual(expected)
	})
})

describe('calculateDeadline', () => {
	it('adds every part of the duration to the start date', () => {
		const date = calculateDeadline(new Date(2026, 0, 31), 'P1Y1M1W2D')
		expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2027, 2, 12])
	})

	it('adds days alone', () => {
		const date = calculateDeadline(new Date(2026, 9, 1), 'P56D')
		expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 10, 26])
	})

	it('does not change the start date it was given', () => {
		const start = new Date(2026, 9, 1)
		calculateDeadline(start, 'P1D')
		expect(start.getDate()).toBe(1)
	})

	it.each([
		[null, 'P1D'],
		['', 'P1D'],
		['2026-10-01', ''],
		['2026-10-01', null],
		['2026-10-01', 'not a duration'],
		['not a date', 'P1D'],
	])('gives null for start %s and duration %s', (start, duration) => {
		expect(calculateDeadline(start, duration)).toBeNull()
	})
})

describe('formatDeadlineCountdown', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 9, 7, 15, 30))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('draws a dash for a case without a deadline', () => {
		expect(formatDeadlineCountdown({ deadline: null })).toEqual({ text: '—', style: '' })
		expect(formatDeadlineCountdown({}, true)).toEqual({ text: '—', style: '' })
	})

	it('draws the date of a case at a final status', () => {
		expect(formatDeadlineCountdown({ deadline: '2026-10-01' }, true)).toEqual({
			text: formatDate('2026-10-01'),
			style: 'deadline--final',
		})
	})

	it.each([
		['2026-10-05', undefined, '2 days overdue', 'deadline--overdue'],
		['2026-10-06', false, '1 day overdue', 'deadline--overdue'],
		['2026-10-07', undefined, 'Due today', 'deadline--today'],
		['2026-10-08', 0, 'Due tomorrow', 'deadline--tomorrow'],
		['2026-10-17', null, '10 days remaining', 'deadline--ok'],
	])('draws deadline %s (final %s) as "%s"', (deadline, isFinal, text, style) => {
		expect(formatDeadlineCountdown({ deadline }, isFinal)).toEqual({ text, style })
	})
})
