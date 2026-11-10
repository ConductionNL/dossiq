// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `normalizeCalDavTask` (src/services/taskApi.js),
 * with the clock frozen: the work item shape, the days-left text, overdue
 * and finished tasks, and the iCalendar priority mapping.
 *
 * @spec openspec/specs/task-management/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { normalizeCalDavTask } from '../../src/services/taskApi.js'

describe('normalizeCalDavTask golden', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-03-10T09:00:00'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('shapes a full task', () => {
		expect(normalizeCalDavTask({
			uid: 'u-1',
			id: 'task.ics',
			calendarId: 'cal',
			summary: 'Bellen',
			objectUuid: 'obj-1',
			due: '2026-03-12',
			status: 'needs-action',
			priority: 1,
		})).toEqual({
			type: 'task',
			id: 'u-1',
			calendarId: 'cal',
			taskUri: 'task.ics',
			title: 'Bellen',
			reference: 'Object: obj-1',
			objectUuid: 'obj-1',
			deadline: '2026-03-12',
			daysText: '2 days',
			isOverdue: false,
			isCompleted: false,
			priority: 'urgent',
			status: 'needs-action',
		})
	})

	it('shapes a bare task', () => {
		expect(normalizeCalDavTask({})).toEqual({
			type: 'task',
			id: undefined,
			calendarId: null,
			taskUri: null,
			title: '—',
			reference: '',
			objectUuid: null,
			deadline: null,
			daysText: '—',
			isOverdue: false,
			isCompleted: false,
			priority: 'normal',
			status: 'needs-action',
		})
	})

	it.each([
		['2026-03-09', '1 day overdue', true],
		['2026-03-01', '9 days overdue', true],
		['2026-03-10', 'Due today', false],
		['2026-03-11', '1 days', false],
	])('reads the due date %s', (due, daysText, isOverdue) => {
		const item = normalizeCalDavTask({ id: 'x', due })
		expect(item.daysText).toBe(daysText)
		expect(item.isOverdue).toBe(isOverdue)
	})

	it.each(['completed', 'cancelled'])('says nothing about the days of a %s task', (status) => {
		const item = normalizeCalDavTask({ id: 'x', due: '2026-03-01', status })
		expect(item.daysText).toBe('—')
		expect(item.isOverdue).toBe(false)
		expect(item.isCompleted).toBe(status === 'completed')
	})

	it.each([
		[0, 'normal'], [1, 'urgent'], [3, 'urgent'], [4, 'high'], [5, 'normal'], [6, 'normal'],
		[7, 'low'], [9, 'low'], [10, 'normal'], ['2', 'urgent'], [undefined, 'normal'], [-1, 'normal'],
	])('maps iCalendar priority %s to %s', (priority, expected) => {
		expect(normalizeCalDavTask({ id: 'x', priority }).priority).toBe(expected)
	})
})
