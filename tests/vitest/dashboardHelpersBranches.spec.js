/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of the dashboard's activity feed, relative time, cases-by-type
 * chart and task work items, with the clock frozen.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	aggregateByType,
	formatRelativeTime,
	getMyWorkItems,
	getRecentActivity,
} from '../../src/utils/dashboardHelpers.js'

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-03-10T09:00:00'))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('getRecentActivity', () => {
	const cases = [
		{ identifier: 'Z-1', activity: [{ date: '2026-03-01', type: 'a', description: 'one', user: 'ann' }] },
		{ activity: 'not a list' },
		{ identifier: '', activity: [{ date: '2026-03-05', type: 'b', description: 'two' }, { date: '2026-02-01', type: 'c', description: 'three', user: '' }] },
		{},
	]

	it('flattens, defaults the user and case, and sorts newest first', () => {
		expect(getRecentActivity(cases)).toEqual([
			{ date: '2026-03-05', type: 'b', description: 'two', user: '—', caseIdentifier: '—' },
			{ date: '2026-03-01', type: 'a', description: 'one', user: 'ann', caseIdentifier: 'Z-1' },
			{ date: '2026-02-01', type: 'c', description: 'three', user: '—', caseIdentifier: '—' },
		])
	})

	it('cuts the list at the limit', () => {
		expect(getRecentActivity(cases, 1).map((e) => e.description)).toEqual(['two'])
		expect(getRecentActivity([], 3)).toEqual([])
	})
})

describe('formatRelativeTime', () => {
	it.each([
		[null, '—'],
		['', '—'],
		['2026-03-10T08:59:30', 'just now'],
		['2026-03-10T09:00:30', 'just now'],
		['2026-03-10T08:15:00', '45 min ago'],
		['2026-03-10T08:00:00', '1 hours ago'],
		['2026-03-09T10:00:00', '23 hours ago'],
		['2026-03-09T08:00:00', 'yesterday'],
		['2026-03-07T08:00:00', '3 days ago'],
		['2026-03-03T10:00:00', '6 days ago'],
	])('words %s as %s', (value, words) => {
		expect(formatRelativeTime(value)).toBe(words)
	})

	it('falls back to the short date a week out, and for an unreadable date', () => {
		expect(formatRelativeTime('2026-03-01T08:00:00'))
			.toBe(new Date('2026-03-01T08:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))
		expect(formatRelativeTime('nonsense'))
			.toBe(new Date('nonsense').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))
	})
})

describe('aggregateByType', () => {
	it('counts by case type id, labels by title, then name, then Unknown', () => {
		const types = [
			{ id: 'a', title: 'Alpha' },
			{ id: 'b', name: 'Beta' },
			{ id: 'c' },
		]
		const cases = [{ caseType: 'b' }, { caseType: 'a' }, { caseType: 'b' }, {}, { caseType: 'c' }, { caseType: 'z' }]
		expect(aggregateByType(cases, types)).toEqual([
			{ type: 'b', label: 'Beta', count: 2 },
			{ type: 'a', label: 'Alpha', count: 1 },
			{ type: '', label: 'Unknown', count: 1 },
			{ type: 'c', label: 'Unknown', count: 1 },
			{ type: 'z', label: 'Unknown', count: 1 },
		])
		expect(aggregateByType([], types)).toEqual([])
	})
})

describe('task work items', () => {
	it('defaults the title, reference, deadline, days and priority', () => {
		const [item] = getMyWorkItems([], [{ id: 't', status: 'open' }])
		expect(item).toEqual({
			type: 'task',
			id: 't',
			title: '—',
			reference: '',
			deadline: null,
			daysText: expect.any(String),
			isOverdue: undefined,
			priority: 'normal',
		})
	})

	it('names the case and reads the due date', () => {
		const [item] = getMyWorkItems([], [{ id: 't', title: 'Bel', case: 'Z-9', dueDate: '2026-03-08', status: 'open', priority: 'high' }])
		expect(item).toMatchObject({ title: 'Bel', reference: 'Case: Z-9', deadline: '2026-03-08', isOverdue: true, priority: 'high' })
	})
})
