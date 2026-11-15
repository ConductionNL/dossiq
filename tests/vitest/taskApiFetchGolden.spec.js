// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `fetchTasksForCases` (src/services/taskApi.js):
 * the unregistered case type, the 20-case cap, and which per-case results
 * end up in the list.
 *
 * @spec openspec/specs/task-management/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const registry = { value: {} }

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ objectTypeRegistry: registry.value }),
}))

const { fetchTasksForCases, normalizeCalDavTask } = await import('../../src/services/taskApi.js')

describe('fetchTasksForCases golden', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
		registry.value = { case: { register: 'r1', schema: 's1' } }
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	it('returns nothing and warns when the case type is not registered', async () => {
		registry.value = {}
		globalThis.fetch = vi.fn()
		expect(await fetchTasksForCases([{ id: 'c1' }])).toEqual([])
		expect(console.warn).toHaveBeenCalledWith('Case object type not registered')
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('fetches at most 20 cases, in order, and keeps the fulfilled task lists', async () => {
		const cases = Array.from({ length: 25 }, (_, i) => ({ id: `c${i}` }))
		globalThis.fetch = vi.fn(async (url) => {
			if (url.endsWith('/c1/tasks')) {
				return { ok: false, status: 500 }
			}
			if (url.endsWith('/c2/tasks')) {
				throw new Error('offline')
			}
			if (url.endsWith('/c3/tasks')) {
				return { ok: true, json: async () => ({ results: [{ uid: 'b' }, { uid: 'c' }] }) }
			}
			return { ok: true, json: async () => (url.endsWith('/c0/tasks') ? [{ uid: 'a' }] : { results: [] }) }
		})

		const tasks = await fetchTasksForCases(cases)

		expect(globalThis.fetch).toHaveBeenCalledTimes(20)
		expect(globalThis.fetch.mock.calls[0][0]).toBe('/apps/openregister/api/objects/r1/s1/c0/tasks')
		expect(globalThis.fetch.mock.calls[19][0]).toBe('/apps/openregister/api/objects/r1/s1/c19/tasks')
		expect(tasks.map((task) => task.id)).toEqual(['a', 'b', 'c'])
	})
})

describe('normalizeCalDavTask fallbacks', () => {
	it('falls back from uid to id and keeps falsy fields as null', () => {
		const item = normalizeCalDavTask({ uid: '', id: 'task.ics', calendarId: '', objectUuid: '', summary: '' })
		expect(item.id).toBe('task.ics')
		expect(item.calendarId).toBe(null)
		expect(item.taskUri).toBe('task.ics')
		expect(item.objectUuid).toBe(null)
		expect(item.title).toBe('—')
		expect(item.reference).toBe('')
	})

	it('treats an empty due date as no deadline', () => {
		const item = normalizeCalDavTask({ id: 'x', due: '' })
		expect(item.deadline).toBe(null)
		expect(item.daysText).toBe('—')
		expect(item.isOverdue).toBe(false)
	})
})
