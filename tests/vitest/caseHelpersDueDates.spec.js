// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The per-case deadline questions a list asks: overdue, due today, due
 * tomorrow, the overdue text, and days elapsed or remaining.
 *
 * @spec openspec/changes/one-term-engine/specs/my-work/spec.md#requirement-one-front-end-helper-decides-days-left-and-overdue-req-ote-07
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	formatDateShort,
	getCaseOverdueText,
	getDaysElapsed,
	getDaysRemaining,
	isCaseDueToday,
	isCaseDueTomorrow,
	isCaseOverdue,
} from '../../src/utils/caseHelpers.js'

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date(2026, 9, 7, 15, 30))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('isCaseOverdue / isCaseDueToday / isCaseDueTomorrow', () => {
	it.each([
		['2026-10-06', true, false, false],
		['2026-10-07', false, true, false],
		['2026-10-08', false, false, true],
		['2026-10-20', false, false, false],
	])('reads deadline %s as overdue %s, today %s, tomorrow %s', (deadline, overdue, today, tomorrow) => {
		expect(isCaseOverdue({ deadline })).toBe(overdue)
		expect(isCaseDueToday({ deadline })).toBe(today)
		expect(isCaseDueTomorrow({ deadline })).toBe(tomorrow)
	})

	it('answers no for a case without a deadline or at a final status', () => {
		for (const check of [isCaseOverdue, isCaseDueToday, isCaseDueTomorrow]) {
			expect(check({})).toBe(false)
			expect(check({ deadline: '2026-10-06' }, true)).toBe(false)
			expect(check({ deadline: '2026-10-07' }, true)).toBe(false)
			expect(check({ deadline: '2026-10-08' }, true)).toBe(false)
		}
	})
})

describe('getCaseOverdueText', () => {
	it('says one day or several days overdue', () => {
		expect(getCaseOverdueText({ deadline: '2026-10-06' })).toBe('1 day overdue')
		expect(getCaseOverdueText({ deadline: '2026-10-02' })).toBe('5 days overdue')
	})

	it('is null when the case is not overdue or is final', () => {
		expect(getCaseOverdueText({ deadline: '2026-10-07' })).toBeNull()
		expect(getCaseOverdueText({ deadline: '2026-10-02' }, true)).toBeNull()
	})
})

describe('getDaysElapsed', () => {
	it('counts whole days since the start date, from midnight', () => {
		expect(getDaysElapsed('2026-10-01T23:00:00')).toBe(6)
		expect(getDaysElapsed('2026-10-07T08:00:00')).toBe(0)
	})

	it('is zero for a missing or future start date', () => {
		expect(getDaysElapsed(null)).toBe(0)
		expect(getDaysElapsed('')).toBe(0)
		expect(getDaysElapsed('2026-12-01T00:00:00')).toBe(0)
	})
})

describe('getDaysRemaining', () => {
	it('counts days to the deadline, negative when overdue, zero when unreadable', () => {
		expect(getDaysRemaining('2026-10-17')).toBe(10)
		expect(getDaysRemaining('2026-10-04')).toBe(-3)
		expect(getDaysRemaining(null)).toBe(0)
	})
})

describe('formatDateShort', () => {
	it('draws a dash for a missing date', () => {
		expect(formatDateShort('')).toBe('—')
		expect(formatDateShort(null)).toBe('—')
	})

	it('draws a date without its year', () => {
		const text = formatDateShort('2026-10-07T12:00:00')
		expect(text).toContain('7')
		expect(text).not.toContain('2026')
	})
})
