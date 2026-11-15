// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Characterisation tests for the advice store's writes
 * (src/store/modules/advice.js): what `createRequest` and `markExpired`
 * save, which task they create, what they leave in the store, and how they
 * fail. The clock is frozen.
 *
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const saveObject = vi.fn()
const createTask = vi.fn()

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => {
		calls.push('objectStore')
		return { saveObject }
	},
}))
vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create: createTask }),
}))

const { useAdviceStore } = await import('../../src/store/modules/advice.js')

beforeEach(() => {
	setActivePinia(createPinia())
	calls.length = 0
	saveObject.mockReset()
	createTask.mockReset()
	saveObject.mockImplementation(async (_schema, data) => {
		calls.push('save')
		return { ...data, saved: true }
	})
	createTask.mockImplementation(async () => {
		calls.push('task')
		return {}
	})
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-03-10T09:00:00Z'))
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('createRequest golden', () => {
	it('saves an internal request and creates the advisor task after it', async () => {
		const store = useAdviceStore()
		const data = { case: 'c1', type: 'intern', advisor: 'ann', subject: 'Brandveiligheid', questions: 'Mag dit?', deadline: '2026-04-01' }

		const saved = await store.createRequest(data)

		expect(saveObject).toHaveBeenCalledWith('adviesAanvraag', { ...data, status: 'requested', requestedAt: '2026-03-10T09:00:00.000Z' })
		expect(createTask).toHaveBeenCalledWith({
			case: 'c1',
			title: 'Advies uitbrengen: Brandveiligheid',
			description: 'Mag dit?',
			assignee: 'ann',
			status: 'available',
			dueDate: '2026-04-01',
		})
		expect(calls).toEqual(['objectStore', 'save', 'task'])
		expect(saved.saved).toBe(true)
		expect(store.requests).toEqual([saved])
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('falls back on a default subject and an empty description', async () => {
		await useAdviceStore().createRequest({ case: 'c1', type: 'intern', advisor: 'ann' })
		expect(createTask.mock.calls[0][0].title).toBe('Advies uitbrengen: Adviesaanvraag')
		expect(createTask.mock.calls[0][0].description).toBe('')
	})

	it.each([
		[{ type: 'extern', advisor: 'ann' }],
		[{ type: 'intern' }],
		[{ type: 'intern', advisor: '' }],
	])('creates no task for %j', async (data) => {
		await useAdviceStore().createRequest(data)
		expect(createTask).not.toHaveBeenCalled()
		expect(saveObject).toHaveBeenCalledTimes(1)
	})

	it('keeps the failure, logs it and answers null', async () => {
		const failure = new Error('save failed')
		saveObject.mockRejectedValue(failure)
		const store = useAdviceStore()

		expect(await store.createRequest({ type: 'intern', advisor: 'ann' })).toBeNull()
		expect(store.error).toBe('save failed')
		expect(console.error).toHaveBeenCalledWith('Error creating advice request:', failure)
		expect(store.requests).toEqual([])
		expect(store.loading).toBe(false)
	})

	it('keeps a saved request when the task creation fails', async () => {
		createTask.mockRejectedValue(new Error('task failed'))
		const store = useAdviceStore()

		expect(await store.createRequest({ type: 'intern', advisor: 'ann' })).toBeNull()
		expect(store.requests).toHaveLength(1)
		expect(store.error).toBe('task failed')
	})
})

describe('markExpired golden', () => {
	it('saves the expiry, creates the follow-up task and replaces the request', async () => {
		const store = useAdviceStore()
		store.requests = [{ id: 'r0' }, { id: 'r1', case: 'c1', advisor: 'ann', subject: 'Geluid', status: 'requested' }]

		const updated = await store.markExpired('r1')

		expect(saveObject).toHaveBeenCalledWith('adviesAanvraag', { id: 'r1', case: 'c1', advisor: 'ann', subject: 'Geluid', status: 'expired' })
		expect(createTask).toHaveBeenCalledWith({
			case: 'c1',
			title: 'Advies verlopen: Geluid',
			description: 'Advies van ann is verlopen. Beoordeel of procedure kan doorgaan zonder dit advies.',
			status: 'available',
		})
		expect(calls).toEqual(['objectStore', 'save', 'task'])
		expect(store.requests[1]).toEqual(updated)
		expect(updated.saved).toBe(true)
		expect(store.requests[0]).toEqual({ id: 'r0' })
		expect(store.loading).toBe(false)
	})

	it('names the advisor when the request has no subject', async () => {
		const store = useAdviceStore()
		store.requests = [{ id: 'r1', advisor: 'ann' }]
		await store.markExpired('r1')
		expect(createTask.mock.calls[0][0].title).toBe('Advies verlopen: ann')
	})

	it('answers null for an unknown request without saving', async () => {
		const store = useAdviceStore()
		store.requests = [{ id: 'r1' }]
		expect(await store.markExpired('nope')).toBeNull()
		expect(saveObject).not.toHaveBeenCalled()
		expect(store.loading).toBe(false)
	})

	it('answers the update even when the request left the list meanwhile', async () => {
		const store = useAdviceStore()
		store.requests = [{ id: 'r1', advisor: 'ann' }]
		saveObject.mockImplementation(async (_schema, data) => {
			store.requests = []
			return { ...data, saved: true }
		})
		const updated = await store.markExpired('r1')
		expect(updated.saved).toBe(true)
		expect(store.requests).toEqual([])
	})

	it('keeps the failure and answers null', async () => {
		createTask.mockRejectedValue(new Error('task failed'))
		const store = useAdviceStore()
		store.requests = [{ id: 'r1', advisor: 'ann' }]

		expect(await store.markExpired('r1')).toBeNull()
		expect(store.error).toBe('task failed')
		expect(store.requests).toEqual([{ id: 'r1', advisor: 'ann' }])
		expect(console.error).not.toHaveBeenCalled()
		expect(store.loading).toBe(false)
	})
})
