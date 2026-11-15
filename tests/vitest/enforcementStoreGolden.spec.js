// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Characterisation tests for the enforcement store
 * (src/store/modules/enforcement.js): how `loadLhsMatrix` reads the
 * configured matrix and when it keeps the default, and what
 * `createAction` saves and keeps.
 *
 * @spec openspec/specs/vth-module/spec.md
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const saveObject = vi.fn()

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ saveObject }),
}))
vi.mock('../../src/store/modules/engineTask.js', () => ({
	useEngineTaskStore: () => ({ create: vi.fn() }),
}))

const { useEnforcementStore } = await import('../../src/store/modules/enforcement.js')

const MATRIX = { licht: { goed: 'Gesprek' } }

beforeEach(() => {
	setActivePinia(createPinia())
	saveObject.mockReset()
	saveObject.mockImplementation(async (_schema, data) => ({ ...data, id: 'a1' }))
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

/**
 * Answer the settings read with a response.
 *
 * @param {object} response The response.
 */
function answer(response) {
	globalThis.fetch = vi.fn(async () => response)
}

describe('loadLhsMatrix golden', () => {
	it('reads the configured matrix with the settings headers', async () => {
		answer({ ok: true, json: async () => ({ config: { lhsMatrix: MATRIX } }) })
		const store = useEnforcementStore()

		await store.loadLhsMatrix()

		expect(globalThis.fetch).toHaveBeenCalledWith('/apps/dossiq/api/settings', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(store.lhsMatrix).toEqual(MATRIX)
		expect(store.matrixLoaded).toBe(true)
	})

	it.each([
		[JSON.stringify(MATRIX), MATRIX],
		['null', null],
	])('parses a matrix stored as text %s', async (stored, matrix) => {
		answer({ ok: true, json: async () => ({ config: { lhsMatrix: stored } }) })
		const store = useEnforcementStore()
		await store.loadLhsMatrix()
		expect(store.lhsMatrix).toEqual(matrix)
	})

	it.each([
		['a failed response', { ok: false }],
		['no config', { ok: true, json: async () => ({}) }],
		['an empty matrix', { ok: true, json: async () => ({ config: { lhsMatrix: '' } }) }],
		['unreadable text', { ok: true, json: async () => ({ config: { lhsMatrix: '{oops' } }) }],
		['a null body', { ok: true, json: async () => null }],
	])('keeps the default matrix for %s', async (_name, response) => {
		answer(response)
		const store = useEnforcementStore()
		const before = JSON.parse(JSON.stringify(store.lhsMatrix))

		await store.loadLhsMatrix()

		expect(store.lhsMatrix).toEqual(before)
		expect(store.matrixLoaded).toBe(true)
	})

	it('logs a failed read', async () => {
		const failure = new Error('offline')
		globalThis.fetch = vi.fn(async () => { throw failure })
		const store = useEnforcementStore()
		await store.loadLhsMatrix()
		expect(console.error).toHaveBeenCalledWith('Error loading LHS matrix:', failure)
		expect(store.matrixLoaded).toBe(true)
	})

	it('reads only once', async () => {
		answer({ ok: true, json: async () => ({ config: { lhsMatrix: MATRIX } }) })
		const store = useEnforcementStore()
		await store.loadLhsMatrix()
		await store.loadLhsMatrix()
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
	})
})

describe('createAction golden', () => {
	it('looks the intervention up and saves the action as imposed', async () => {
		const store = useEnforcementStore()
		const data = { severity: 'gering', behaviour: 'calculerend', case: 'c1' }

		const saved = await store.createAction(data)

		expect(data.intervention).toBe('Last onder dwangsom')
		expect(saveObject).toHaveBeenCalledWith('handhavingsactie', {
			severity: 'gering',
			behaviour: 'calculerend',
			case: 'c1',
			intervention: 'Last onder dwangsom',
			status: 'opgelegd',
		})
		expect(saved.id).toBe('a1')
		expect(store.actions).toEqual([saved])
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it.each([
		[{ intervention: 'Eigen', severity: 'gering', behaviour: 'calculerend' }, 'Eigen'],
		[{ severity: 'gering' }, undefined],
		[{ behaviour: 'calculerend' }, undefined],
		[{ severity: 'onbekend', behaviour: 'calculerend' }, null],
	])('decides the intervention of %j as %s', async (data, intervention) => {
		await useEnforcementStore().createAction(data)
		expect(saveObject.mock.calls[0][1].intervention).toBe(intervention)
	})

	it('keeps a given status', async () => {
		await useEnforcementStore().createAction({ status: 'verbeurd' })
		expect(saveObject.mock.calls[0][1].status).toBe('verbeurd')
	})

	it('keeps the failure, logs it and answers null', async () => {
		const failure = new Error('save failed')
		saveObject.mockRejectedValue(failure)
		const store = useEnforcementStore()

		expect(await store.createAction({})).toBeNull()
		expect(store.error).toBe('save failed')
		expect(console.error).toHaveBeenCalledWith('Error creating enforcement action:', failure)
		expect(store.actions).toEqual([])
		expect(store.loading).toBe(false)
	})
})
