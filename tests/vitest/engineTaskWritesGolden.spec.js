/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the engine task store's writes and leaves
 * (src/store/modules/engineTask.js): the create payload, the loading and
 * error state around create / invoke / checkItem, and which refusal text each
 * call keeps.
 *
 * @spec openspec/specs/task-management/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()
const patch = vi.fn()
const del = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get, post, patch, delete: del },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const { useEngineTaskStore } = await import('../../src/store/modules/engineTask.js')

const REFUSALS = [
	[{ response: { data: { message: 'Engine says no', error: 'slug' } }, message: 'm' }, 'Engine says no', 'slug'],
	[{ response: { data: { message: 'Engine says no' } } }, 'Engine says no', 'Engine says no'],
	[new Error('network'), 'network', 'network'],
	['plain', 'plain', 'plain'],
]

beforeEach(() => {
	setActivePinia(createPinia())
	for (const fn of [get, post, patch, del]) {
		fn.mockReset()
	}
})

describe('create payload', () => {
	it.each([
		['completed', 'completed'],
		['terminated', 'terminated'],
		['disabled', 'disabled'],
		[' active ', 'active'],
		['open', 'available'],
		[undefined, 'available'],
	])('sends the state for status %j as %s', async (status, state) => {
		post.mockResolvedValue({ data: {} })
		await useEngineTaskStore().create({ title: 'T', status })
		expect(post.mock.calls[0][1].state).toBe(state)
	})

	it('maps and trims the dossiq fields and leaves out empty ones', async () => {
		post.mockResolvedValue({ data: { results: { id: 't' } } })
		await useEngineTaskStore().create({
			title: 7,
			case: ' case-1 ',
			objectUuid: 'ignored',
			description: ' Bel terug ',
			assignee: '',
			dueDate: '2026-03-01',
			priority: '  ',
			kind: 'reminder',
		})
		expect(post).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks', {
			title: '7',
			appId: 'dossiq',
			state: 'available',
			objectUuid: 'case-1',
			description: 'Bel terug',
			dueAt: '2026-03-01',
			kind: 'reminder',
		})
		expect(Object.keys(post.mock.calls[0][1])).toEqual(['title', 'appId', 'state', 'objectUuid', 'description', 'dueAt', 'kind'])
	})

	it('falls back to objectUuid and sends no object without either', async () => {
		post.mockResolvedValue({ data: {} })
		await useEngineTaskStore().create({ objectUuid: 'obj-1' })
		expect(post.mock.calls[0][1]).toEqual({ title: '', appId: 'dossiq', state: 'available', objectUuid: 'obj-1' })
		await useEngineTaskStore().create()
		expect(post.mock.calls[1][1]).toEqual({ title: '', appId: 'dossiq', state: 'available' })
	})
})

describe('create / invoke / checkItem state', () => {
	const CALLS = [
		['create', (store) => store.create({ title: 'T' }), post],
		['invoke', (store) => store.invoke('u-1', 'claim'), post],
		['checkItem', (store) => store.checkItem('u-1', 'i-1', true), patch],
	]

	it.each(CALLS)('%s keeps the answered task, unwrapped or bare', async (_name, call, verb) => {
		const store = useEngineTaskStore()
		store.error = 'old'
		verb.mockResolvedValue({ data: { results: { id: 'wrapped' } } })
		expect(await call(store)).toEqual({ id: 'wrapped' })
		expect(store.task).toEqual({ id: 'wrapped' })
		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)

		verb.mockResolvedValue({ data: { id: 'bare' } })
		expect(await call(store)).toEqual({ id: 'bare' })

		verb.mockResolvedValue({})
		expect(await call(store)).toBeNull()
		expect(store.task).toBeNull()
	})

	it.each(CALLS)('%s keeps loading while the request runs', async (_name, call, verb) => {
		const store = useEngineTaskStore()
		let seen = null
		verb.mockImplementation(() => {
			seen = { loading: store.loading, error: store.error }
			return Promise.resolve({ data: {} })
		})
		store.error = 'old'
		await call(store)
		expect(seen).toEqual({ loading: true, error: null })
	})

	it.each(CALLS.flatMap(([name, call, verb]) => REFUSALS.map(([thrown, message]) => [name, call, verb, thrown, message])))(
		'%s keeps the refusal text',
		async (_name, call, verb, thrown, message) => {
			const store = useEngineTaskStore()
			store.task = { id: 'kept' }
			verb.mockRejectedValue(thrown)
			expect(await call(store)).toBeNull()
			expect(store.error).toBe(message)
			expect(store.task).toEqual({ id: 'kept' })
			expect(store.loading).toBe(false)
		},
	)

	it('sends complete to dossiq and other verbs to the engine, with the body', async () => {
		post.mockResolvedValue({ data: {} })
		const store = useEngineTaskStore()
		await store.invoke(' u 1 ', ' complete ', { answers: 1 })
		await store.invoke('u-1', 'un/claim')
		await store.invoke('u-1', 'resolve')
		expect(post.mock.calls).toEqual([
			['/apps/dossiq/api/case-tasks/u%201/complete', { answers: 1 }],
			['/apps/openregister/api/flow-tasks/u-1/un%2Fclaim', {}],
			['/apps/openregister/api/flow-tasks/u-1/resolve', {}],
		])
	})

	it.each([[true, 'true'], [false, 'false'], ['true', 'false'], [1, 'false']])('sends checked %j as %s', async (checked, flag) => {
		patch.mockResolvedValue({ data: {} })
		await useEngineTaskStore().checkItem('u-1', 'i/1', checked)
		expect(patch).toHaveBeenCalledWith('/apps/openregister/api/flow-tasks/u-1/checklist/i%2F1', null, { params: { checked: flag } })
	})

	it.each([
		[(store) => store.invoke('', 'claim')],
		[(store) => store.invoke('u', ' ')],
		[(store) => store.checkItem(' ', 'i')],
		[(store) => store.checkItem('u', null)],
	])('answers null without a call for a blank id', async (call) => {
		const store = useEngineTaskStore()
		expect(await call(store)).toBeNull()
		expect(store.loading).toBe(false)
		expect(post).not.toHaveBeenCalled()
		expect(patch).not.toHaveBeenCalled()
	})
})

describe('leaf refusals', () => {
	it.each(REFUSALS)('reads, writes and removes with the leaf refusal text', async (thrown, _message, leafMessage) => {
		const store = useEngineTaskStore()
		get.mockRejectedValue(thrown)
		post.mockRejectedValue(thrown)
		del.mockRejectedValue(thrown)
		expect(await store.readLeaf('u', 'notes')).toEqual({ results: [], error: leafMessage })
		expect(await store.writeNote('u', 'x')).toEqual({ note: null, error: leafMessage })
		expect(await store.removeNote('u', 3)).toEqual({ removed: false, error: leafMessage })
		expect(store.error).toBeNull()
	})

	it('answers the rows of a leaf, unwrapped, bare or not a list', async () => {
		const store = useEngineTaskStore()
		get.mockResolvedValueOnce({ data: { results: [1] } })
		get.mockResolvedValueOnce({ data: [2] })
		get.mockResolvedValueOnce({ data: { results: 'x' } })
		get.mockResolvedValueOnce({})
		expect(await store.readLeaf('u 1', 'au/dit')).toEqual({ results: [1], error: null })
		expect(await store.readLeaf('u', 'notes')).toEqual({ results: [2], error: null })
		expect(await store.readLeaf('u', 'notes')).toEqual({ results: [], error: null })
		expect(await store.readLeaf('u', 'notes')).toEqual({ results: [], error: null })
		expect(get.mock.calls[0][0]).toBe('/apps/openregister/api/flow-tasks/u%201/au%2Fdit')
	})
})
