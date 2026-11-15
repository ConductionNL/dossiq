/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `computeKpis` (src/utils/dashboardHelpers.js),
 * with the clock frozen: the counts, today's starts and tasks, and the
 * average lead time of completed cases.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computeKpis } from '../../src/utils/dashboardHelpers.js'

describe('computeKpis', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-03-10T12:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('counts the open, new, overdue, completed and task figures', () => {
		const open = [
			{ startDate: '2026-03-10T08:00:00Z', deadline: '2026-03-01' },
			{ startDate: '2026-03-09', deadline: '2026-04-01' },
			{ deadline: '2026-03-02' },
			{ startDate: '' },
		]
		const completed = [
			{ startDate: '2026-03-01', endDate: '2026-03-04' },
			{ startDate: '2026-03-01', endDate: '2026-03-12' },
			{ startDate: '2026-03-05', endDate: '2026-03-01' },
			{ startDate: '2026-03-01' },
		]
		const tasks = [{ dueDate: '2026-03-10' }, { dueDate: '2026-03-11T00:00:00Z' }, {}]
		expect(computeKpis(open, completed, tasks)).toEqual({
			openCount: 4,
			newToday: 1,
			overdueCount: 2,
			completedCount: 4,
			avgDays: 4,
			taskCount: 3,
			tasksDueToday: 1,
		})
	})

	it('has no average without completed cases', () => {
		expect(computeKpis([], [], [])).toEqual({
			openCount: 0,
			newToday: 0,
			overdueCount: 0,
			completedCount: 0,
			avgDays: null,
			taskCount: 0,
			tasksDueToday: 0,
		})
	})

	it('rounds the average lead time', () => {
		const completed = [
			{ startDate: '2026-03-01', endDate: '2026-03-02' },
			{ startDate: '2026-03-01', endDate: '2026-03-03' },
		]
		expect(computeKpis([], completed, []).avgDays).toBe(2)
	})
})
