// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { describe, it, expect } from 'vitest'
import { rangeOptions, rangeStart, isInRange } from '../../src/utils/dateRange.js'

// Fixed reference: Wednesday 2026-06-17 (ISO week Mon 2026-06-15, Q2).
const NOW = new Date('2026-06-17T12:00:00')

// Format a Date as local YYYY-MM-DD (rangeStart works in local time, so
// toISOString() would shift across the UTC boundary and give a false mismatch).
const localYmd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

describe('dateRange', () => {
	it('exposes the five presets in order', () => {
		expect(rangeOptions().map(o => o.id)).toEqual(['week', 'month', 'quarter', 'year', 'all'])
	})

	it('resolves calendar-aligned starts', () => {
		expect(localYmd(rangeStart('week', NOW))).toBe('2026-06-15')
		expect(localYmd(rangeStart('month', NOW))).toBe('2026-06-01')
		expect(localYmd(rangeStart('quarter', NOW))).toBe('2026-04-01')
		expect(localYmd(rangeStart('year', NOW))).toBe('2026-01-01')
		expect(rangeStart('all', NOW)).toBeNull()
	})

	it('matches everything for the all range, including empty dates', () => {
		expect(isInRange(null, 'all', NOW)).toBe(true)
		expect(isInRange('2020-01-01', 'all', NOW)).toBe(true)
	})

	it('never matches an absent date for a bounded range', () => {
		expect(isInRange(null, 'month', NOW)).toBe(false)
		expect(isInRange('', 'week', NOW)).toBe(false)
	})

	it('includes the start boundary and excludes earlier dates', () => {
		expect(isInRange('2026-06-01', 'month', NOW)).toBe(true)
		expect(isInRange('2026-06-17', 'month', NOW)).toBe(true)
		expect(isInRange('2026-05-31', 'month', NOW)).toBe(false)
		expect(isInRange('2026-06-14', 'week', NOW)).toBe(false)
		expect(isInRange('2026-03-31', 'quarter', NOW)).toBe(false)
		expect(isInRange('2025-12-31', 'year', NOW)).toBe(false)
	})
})
