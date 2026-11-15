// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Characterisation tests for the read side of the engine task store
 * (src/store/modules/engineTask.js): what `list` and `fetch` leave in the
 * store on success and on failure, and the guards of `asTaskRow` and
 * `signedDaysUntilDue`.
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get, post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const { useEngineTaskStore, asTaskRow, signedDaysUntilDue } = await import(
	'../../src/store/modules/engineTask.js'
)

beforeEach(() => {
	setActivePinia(createPinia())
	get.mockReset()
})

describe('list golden', () => {
	it.each([
		[{ results: [{ uuid: 'a' }], total: 7 }, 7],
		[{ results: [{ uuid: 'a' }, { uuid: 'b' }] }, 2],
		[{ results: [{ uuid: 'a' }], total: 'many' }, 0],
		[{ results: [{ uuid: 'a' }], total: null }, 1],
	])('records the total of %j as %s', async (data, total) => {
		get.mockResolvedValue({ data })
		const store = useEngineTaskStore()

		const rows = await store.list({ scope: 'all' })

		expect(get).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks', { params: { scope: 'all' } })
		expect(rows).toBe(store.tasks)
		expect(store.total).toBe(total)
		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('reads a response without data as an empty list', async () => {
		get.mockResolvedValue({})
		const store = useEngineTaskStore()

		expect(await store.list()).toEqual([])
		expect(get).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks', { params: {} })
		expect(store.total).toBe(0)
	})

	it('clears an earlier list on failure and keeps a message-less error as text', async () => {
		get.mockResolvedValueOnce({ data: { results: [{ uuid: 'a' }], total: 1 } })
		const store = useEngineTaskStore()
		await store.list()

		get.mockRejectedValueOnce('plain refusal')
		const rows = await store.list()

		expect(rows).toEqual([])
		expect(store.tasks).toEqual([])
		expect(store.total).toBe(0)
		expect(store.error).toBe('plain refusal')
		expect(store.loading).toBe(false)
	})

	it('is loading while the request runs', async () => {
		let release
		get.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const store = useEngineTaskStore()

		const pending = store.list()
		expect(store.loading).toBe(true)
		release({ data: { results: [] } })
		await pending
		expect(store.loading).toBe(false)
	})
})

describe('fetch golden', () => {
	it('trims and encodes the id, and unwraps a results envelope', async () => {
		get.mockResolvedValue({ data: { results: { uuid: 'a b', state: 'active' } } })
		const store = useEngineTaskStore()

		const task = await store.fetch('  a b ')

		expect(get).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/a%20b')
		expect(task).toBe(store.task)
		expect(task.uuid).toBe('a b')
		expect(store.loading).toBe(false)
	})

	it('answers null for a response without data', async () => {
		get.mockResolvedValue({})
		const store = useEngineTaskStore()

		expect(await store.fetch('t1')).toBeNull()
		expect(store.task).toBeNull()
		expect(store.error).toBeNull()
	})

	it('clears the task on failure and surfaces the message', async () => {
		get.mockResolvedValueOnce({ data: { uuid: 't1' } })
		const store = useEngineTaskStore()
		await store.fetch('t1')

		get.mockRejectedValueOnce(new Error('gone'))
		expect(await store.fetch('t1')).toBeNull()
		expect(store.task).toBeNull()
		expect(store.error).toBe('gone')
		expect(store.loading).toBe(false)
	})

	it('treats a null id as blank', async () => {
		expect(await useEngineTaskStore().fetch(null)).toBeNull()
		expect(get).not.toHaveBeenCalled()
	})
})

describe('row guards golden', () => {
	it.each([null, undefined, 'row', 3])('passes %j through asTaskRow untouched', (row) => {
		expect(asTaskRow(row)).toBe(row)
	})

	it.each([null, undefined, 'task', 3])('answers null days for %j', (task) => {
		expect(signedDaysUntilDue(task)).toBeNull()
	})

	it('reads an overdue count before a countdown', () => {
		expect(signedDaysUntilDue({ daysOverdue: 2, daysUntilDue: 5 })).toBe(-2)
		expect(signedDaysUntilDue({ daysOverdue: '2', daysUntilDue: 5 })).toBe(5)
		expect(signedDaysUntilDue({ daysUntilDue: '5' })).toBeNull()
	})
})
