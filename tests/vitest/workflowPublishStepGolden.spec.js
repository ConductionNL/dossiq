// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for three workflow store actions
 * (src/store/modules/workflow.js): `publishVersion` (validation, the publish
 * request, and what it keeps on every answer), `dispatchCreateTaskAction`'s
 * refusal text, and `addStep`'s new step.
 *
 * @spec openspec/specs/visual-workflow-editor/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const engine = { create: vi.fn(), error: null }

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ saveObject: vi.fn(), fetchObject: vi.fn(), fetchCollection: vi.fn() }),
}))
vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => engine,
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

let store

beforeEach(() => {
	setActivePinia(createPinia())
	store = useWorkflowStore()
	globalThis.OC = { requestToken: 'tok' }
	engine.create.mockReset()
	engine.error = null
})

afterEach(() => {
	delete globalThis.fetch
	vi.restoreAllMocks()
})

/**
 * Answer the publish request.
 *
 * @param {object} response The response.
 */
function answer(response) {
	globalThis.fetch = vi.fn(async () => {
		expect(store.loading).toBe(true)
		return response
	})
}

describe('publishVersion golden', () => {
	it('refuses an invalid workflow before any request', async () => {
		const errors = [{ message: 'no final status' }]
		store.validateWorkflow = vi.fn(() => errors)
		globalThis.fetch = vi.fn()

		expect(await store.publishVersion('w1', [{ id: 's1' }])).toBeNull()

		expect(store.validateWorkflow).toHaveBeenCalledWith([{ id: 's1' }])
		expect(store.validationErrors).toEqual(errors)
		expect(store.error).toBe('Workflow validation failed')
		expect(store.loading).toBe(false)
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('posts the publish and keeps the published definition', async () => {
		store.validateWorkflow = vi.fn(() => [])
		store.validationErrors = [{ message: 'old' }]
		store.error = 'old'
		answer({ ok: true, json: async () => ({ success: true, definition: { id: 'w1', version: 2 } }) })

		const definition = await store.publishVersion('w1')

		expect(store.validateWorkflow).toHaveBeenCalledWith([])
		expect(globalThis.fetch).toHaveBeenCalledWith('/apps/dossiq/api/workflow-definitions/w1/publish', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(definition).toEqual({ id: 'w1', version: 2 })
		expect(store.currentTemplate).toEqual({ id: 'w1', version: 2 })
		expect(store.validationErrors).toEqual([])
		expect(store.error).toBeNull()
		expect(store.loading).toBe(false)
	})

	it.each([
		['a refusal with its own text', { ok: false, json: async () => ({ error: 'Dangling transition' }) }, 'Dangling transition'],
		['a refusal without text', { ok: false, json: async () => ({}) }, 'Could not publish workflow definition'],
		['an ok answer that did not succeed', { ok: true, json: async () => ({ success: false, error: 'Locked' }) }, 'Locked'],
		['an unreadable body', { ok: true, json: async () => { throw new Error('not json') } }, 'Could not publish workflow definition'],
		['an empty error text', { ok: false, json: async () => ({ error: '' }) }, 'Could not publish workflow definition'],
	])('keeps the error of %s', async (_name, response, error) => {
		store.validateWorkflow = vi.fn(() => [])
		store.currentTemplate = { id: 'w0' }
		store.validationErrors = [{ message: 'old' }]
		answer(response)

		expect(await store.publishVersion('w1')).toBeNull()

		expect(store.error).toBe(error)
		expect(store.currentTemplate).toEqual({ id: 'w0' })
		expect(store.validationErrors).toEqual([{ message: 'old' }])
		expect(store.loading).toBe(false)
	})

	it('keeps the message of a failed request', async () => {
		store.validateWorkflow = vi.fn(() => [])
		globalThis.fetch = vi.fn(async () => { throw new Error('offline') })

		expect(await store.publishVersion('w1')).toBeNull()
		expect(store.error).toBe('offline')
		expect(store.loading).toBe(false)
	})
})

describe('dispatchCreateTaskAction refusal golden', () => {
	it('throws the engine error when it has one, else a fixed text', async () => {
		engine.create.mockResolvedValue(null)
		engine.error = 'Not allowed'
		await expect(store.dispatchCreateTaskAction({}, { id: 'c1' })).rejects.toThrow('Not allowed')

		engine.error = null
		await expect(store.dispatchCreateTaskAction({}, { id: 'c1' })).rejects.toThrow('The task engine refused the task')
	})

	it('answers nothing when the engine creates the task', async () => {
		engine.create.mockResolvedValue({ uuid: 't1' })
		expect(await store.dispatchCreateTaskAction({ title: 'T' }, { id: 'c1' })).toBeUndefined()
	})
})

describe('addStep golden', () => {
	it('adds a step with defaults, ordered after its status siblings', () => {
		store.currentTemplate = {
			steps: JSON.stringify([
				{ id: 'a', status: 's1', order: 1 },
				{ id: 'b', status: 's2', order: 1 },
				{ id: 'c', status: 's1', order: 2 },
			]),
		}

		const step = store.addStep('s1')

		expect(step).toEqual({
			id: expect.stringMatching(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/),
			title: 'New step',
			description: '',
			status: 's1',
			order: 3,
			assigneeRole: null,
			isRequired: false,
			checklist: [],
			automaticActions: [],
		})
		expect(JSON.parse(store.currentTemplate.steps)).toEqual([
			{ id: 'a', status: 's1', order: 1 },
			{ id: 'b', status: 's2', order: 1 },
			{ id: 'c', status: 's1', order: 2 },
			step,
		])
	})

	it('takes the given properties and keeps falsy ones as their defaults', () => {
		store.currentTemplate = { steps: '[]' }
		const step = store.addStep('s1', {
			title: 'Toets',
			description: 'Lees',
			assigneeRole: 'r1',
			isRequired: true,
			checklist: ['x'],
			automaticActions: [{ type: 'notify' }],
		})
		expect(step).toMatchObject({ title: 'Toets', description: 'Lees', assigneeRole: 'r1', isRequired: true, checklist: ['x'], automaticActions: [{ type: 'notify' }], order: 1 })

		const blank = store.addStep('s1', { title: '', assigneeRole: '', isRequired: 0, checklist: null })
		expect(blank).toMatchObject({ title: 'New step', assigneeRole: null, isRequired: false, checklist: [], order: 2 })
	})

	it('answers the step without a template to write it to', () => {
		store.currentTemplate = null
		const step = store.addStep('s1', { title: 'Los' })
		expect(step.title).toBe('Los')
		expect(step.order).toBe(1)
		expect(store.currentTemplate).toBeNull()
	})
})
