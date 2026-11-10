/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the workflow store's template reads and writes
 * (src/store/modules/workflow.js): the object store calls each makes, what
 * it keeps and answers, and the loading and error state on success and on
 * failure.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const objectStore = {
	fetchCollection: vi.fn(),
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => objectStore,
}))

vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create: vi.fn(), error: null }),
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text) => text,
	n: (app, text) => text,
}))

const { useWorkflowStore } = await import('../../src/store/modules/workflow.js')

/**
 * Run an action and record the loading flag it ran under.
 *
 * @param {object} store The store.
 * @param {Function} run The action call.
 * @return {Promise<{answer: unknown, during: Array<boolean>}>} Its answer and the loading flags seen by the object store.
 */
async function observed(store, run) {
	const during = []
	for (const fn of Object.values(objectStore)) {
		const inner = fn.getMockImplementation()
		fn.mockImplementation(async (...args) => {
			during.push(store.loading)
			return inner ? inner(...args) : undefined
		})
	}
	const answer = await run()
	return { answer, during }
}

describe('workflow template reads and writes golden', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useWorkflowStore()
		Object.values(objectStore).forEach((fn) => fn.mockReset())
		store.error = 'old'
	})

	it('lists the versions of a case type, newest first', async () => {
		objectStore.fetchCollection.mockImplementation(async () => [{ id: 'v2' }])
		const { answer, during } = await observed(store, () => store.listVersions('ct-1'))
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('workflowTemplate', { caseType: 'ct-1', _limit: 100, _order: { version: 'desc' } })
		expect(answer).toEqual([{ id: 'v2' }])
		expect(store.versions).toEqual([{ id: 'v2' }])
		expect(during).toEqual([true])
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()

		objectStore.fetchCollection.mockImplementation(async () => null)
		expect(await store.listVersions('ct-1')).toEqual([])
	})

	it('answers each fallback and keeps the message when a call fails', async () => {
		const failure = async () => {
			throw new Error('down')
		}
		const cases = [
			['fetchCollection', () => store.listVersions('ct'), []],
			['fetchObject', () => store.getTemplate('t'), null],
			['fetchCollection', () => store.getActiveVersion('ct'), null],
			['saveObject', () => store.createTemplate('ct', 'T'), null],
			['saveObject', () => store.saveTemplate({ steps: [] }), null],
			['deleteObject', () => store.deleteTemplate('t'), false],
			['fetchObject', () => store.createDraftFromVersion('t'), null],
		]
		for (const [method, run, fallback] of cases) {
			objectStore[method].mockImplementation(failure)
			store.error = 'old'
			store.currentTemplate = { id: 'kept' }
			expect(await run()).toEqual(fallback)
			expect(store.error).toBe('down')
			expect(store.loading).toBe(false)
			expect(store.currentTemplate).toEqual({ id: 'kept' })
			objectStore[method].mockReset()
		}
	})

	it('reads one template and keeps it current', async () => {
		objectStore.fetchObject.mockImplementation(async () => ({ id: 't-1' }))
		const { answer, during } = await observed(store, () => store.getTemplate('t-1'))
		expect(objectStore.fetchObject).toHaveBeenCalledWith('workflowTemplate', 't-1')
		expect(answer).toEqual({ id: 't-1' })
		expect(store.currentTemplate).toEqual({ id: 't-1' })
		expect(during).toEqual([true])
	})

	it('reads the active version, or none', async () => {
		objectStore.fetchCollection.mockImplementation(async () => [{ id: 'a' }, { id: 'b' }])
		expect(await store.getActiveVersion('ct-1')).toEqual({ id: 'a' })
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('workflowTemplate', { caseType: 'ct-1', isActive: true, isDraft: false, _limit: 1 })
		objectStore.fetchCollection.mockImplementation(async () => [])
		expect(await store.getActiveVersion('ct-1')).toBeNull()
		objectStore.fetchCollection.mockImplementation(async () => null)
		expect(await store.getActiveVersion('ct-1')).toBeNull()
		expect(store.currentTemplate).toBeNull()
	})

	it('creates an empty draft template', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => ({ id: 'new', ...data }))
		const answer = await store.createTemplate('ct-1', 'Vergunning')
		expect(objectStore.saveObject).toHaveBeenCalledWith('workflowTemplate', {
			title: 'Vergunning',
			caseType: 'ct-1',
			version: 1,
			isActive: false,
			isDraft: true,
			steps: '[]',
			transitions: '[]',
			nodePositions: '{}',
		})
		expect(store.currentTemplate).toEqual(answer)
	})

	it('saves a template with its lists as text', async () => {
		objectStore.saveObject.mockImplementation(async (type, data) => ({ saved: data }))
		await store.saveTemplate({ id: 't', steps: [1], transitions: '[]', nodePositions: { a: 1 } })
		expect(objectStore.saveObject).toHaveBeenCalledWith('workflowTemplate', { id: 't', steps: '[1]', transitions: '[]', nodePositions: '{"a":1}' })
		expect(store.currentTemplate).toEqual({ saved: { id: 't', steps: '[1]', transitions: '[]', nodePositions: '{"a":1}' } })
	})

	it('deletes a template and forgets it when it was current', async () => {
		store.currentTemplate = { id: 't' }
		expect(await store.deleteTemplate('other')).toBe(true)
		expect(store.currentTemplate).toEqual({ id: 't' })
		expect(await store.deleteTemplate('t')).toBe(true)
		expect(store.currentTemplate).toBeNull()
		expect(objectStore.deleteObject).toHaveBeenCalledWith('workflowTemplate', 't')
	})

	it('copies a version into the next draft', async () => {
		store.versions = [{ version: 2 }, { version: 5 }, {}]
		objectStore.fetchObject.mockImplementation(async () => ({
			title: 'T', description: 'D', caseType: 'ct', steps: 's', transitions: 'tr', nodePositions: 'np', version: 5,
		}))
		objectStore.saveObject.mockImplementation(async (type, data) => ({ id: 'draft', ...data }))
		const { answer, during } = await observed(store, () => store.createDraftFromVersion('src'))
		expect(objectStore.saveObject).toHaveBeenCalledWith('workflowTemplate', {
			title: 'T',
			description: 'D',
			caseType: 'ct',
			version: 6,
			isActive: false,
			isDraft: true,
			steps: 's',
			transitions: 'tr',
			nodePositions: 'np',
			parentWorkflow: null,
		})
		expect(answer.id).toBe('draft')
		expect(store.currentTemplate).toEqual(answer)
		expect(during).toEqual([true, true])
	})

	it('says a missing source version is not found', async () => {
		objectStore.fetchObject.mockImplementation(async () => null)
		store.currentTemplate = { id: 'kept' }
		expect(await store.createDraftFromVersion('gone')).toBeNull()
		expect(store.error).toBe('Source workflow template not found')
		expect(store.loading).toBe(false)
		expect(objectStore.saveObject).not.toHaveBeenCalled()
		expect(store.currentTemplate).toEqual({ id: 'kept' })
	})
})
