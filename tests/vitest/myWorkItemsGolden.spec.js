/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for the My Work lists (src/utils/dashboardHelpers.js):
 * `getMyWorkItems` and `getGroupedMyWorkItems`, with the clock frozen. The
 * snapshot was recorded on the code before the shared steps were extracted.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getGroupedMyWorkItems, getMyWorkItems } from '../../src/utils/dashboardHelpers.js'

const CASES = [
	{ id: 'c1', title: 'Bouw', identifier: 'Z-1', deadline: '2026-03-01', priority: 'high', status: 's1', caseType: 'ct' },
	{ id: 'c2', title: '', deadline: '2026-03-05', priority: 'low' },
	{ id: 'c3', title: 'Geen termijn' },
	{ id: 'c4', title: 'Later', deadline: '2026-04-20', priority: 'urgent' },
	{ id: 'c5', title: 'Deze week', deadline: '2026-03-12', priority: 'normal' },
]

const TASKS = [
	{ id: 't1', title: 'Bellen', dueDate: '2026-03-09', status: 'open', priority: 'high', case: 'Z-1' },
	{ id: 't2', title: 'Gisteren', dueDate: '2026-03-08', status: 'open' },
	{ id: 't3', title: 'Vandaag', dueDate: '2026-03-10', status: 'open', priority: 'low' },
	{ id: 't4', title: 'Overmorgen', dueDate: '2026-03-12', status: 'completed' },
	{ id: 't5', title: '', status: 'open' },
	{ id: 't6', title: 'Lang geleden', dueDate: '2026-02-01', status: 'open', priority: 'urgent' },
]

describe('My Work golden', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-03-10T09:00:00'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('lists cases and tasks by priority, then deadline', () => {
		expect(getMyWorkItems(CASES, TASKS)).toMatchSnapshot()
		expect(getMyWorkItems(CASES, TASKS, 20)).toMatchSnapshot()
		expect(getMyWorkItems([], [])).toEqual([])
	})

	it('groups cases and normalised tasks by when they are due', () => {
		const normalized = [
			{ type: 'task', id: 'n1', title: 'Te laat', deadline: '2026-03-01', isOverdue: true, priority: 'high' },
			{ type: 'task', id: 'n2', title: 'Vrijdag', deadline: '2026-03-13', isOverdue: false, priority: 'low' },
			{ type: 'task', id: 'n3', title: 'Volgende maand', deadline: '2026-04-30', isOverdue: false },
			{ type: 'task', id: 'n4', title: 'Zonder', deadline: null, isOverdue: false },
		]
		expect(getGroupedMyWorkItems(CASES, normalized)).toMatchSnapshot()
		expect(getGroupedMyWorkItems([], [])).toEqual({ overdue: [], dueThisWeek: [], upcoming: [], noDeadline: [], totalCount: 0 })
	})
})
