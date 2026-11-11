/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for RequesterProjection's `run`
 * (src/components/initiator/RequesterProjection.vue): which reads it makes,
 * in which order, and when it writes the projection.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** The object store the component sees. Replaced per test. */
let storeStub = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: RequesterProjection } =
	await import('../../src/components/initiator/RequesterProjection.vue')

const PERSON_ROW = { id: 'uuid-1', citizenServiceNumber: '999990627', displayName: 'Stephan Janssen' }
const COMPANY_ROW = { id: 'uuid-1', tradeName: 'Visser Bouw B.V.', kvkNumber: '24157431' }

/**
 * Mount the component over a stub store and a route.
 *
 * @param {object} options Stub behaviour.
 * @param {Function} options.fetchObject What the store answers per type.
 * @param {Function} [options.saveObject] The write stub.
 * @param {object} [options.params] The route parameters.
 * @return {Promise<object>} The stubs, after the load settles.
 */
async function mountProjection({
	fetchObject,
	saveObject = vi.fn().mockResolvedValue({}),
	params = { id: 'case-1' },
}) {
	storeStub = { fetchObject, saveObject }
	mount(RequesterProjection, { global: { mocks: { $route: { params } } } })
	await flushPromises()
	await flushPromises()
	return { fetchObject, saveObject }
}

/**
 * A store read that answers the case and one row per type.
 *
 * @param {object} caseObject The case.
 * @param {object} rows The row per schema; a function there throws.
 * @return {Function} The stub.
 */
function reads(caseObject, rows) {
	return vi.fn().mockImplementation(async (type) => {
		if (type === 'case') {
			return caseObject
		}
		if (rows[type] instanceof Error) {
			throw rows[type]
		}
		return rows[type] ?? null
	})
}

describe('requester projection run', () => {
	it('reads nothing without a case on the route', async () => {
		const { fetchObject, saveObject } = await mountProjection({ fetchObject: vi.fn(), params: {} })
		expect(fetchObject).not.toHaveBeenCalled()
		expect(saveObject).not.toHaveBeenCalled()
	})

	it('stops when the case does not load', async () => {
		const fetchObject = vi.fn().mockRejectedValue(new Error('gone'))
		const { saveObject } = await mountProjection({ fetchObject })
		expect(fetchObject).toHaveBeenCalledTimes(1)
		expect(saveObject).not.toHaveBeenCalled()
	})

	it('treats a case read that answers nothing as a case without a requester', async () => {
		const fetchObject = vi.fn().mockResolvedValue(null)
		const { saveObject } = await mountProjection({ fetchObject })
		expect(fetchObject).toHaveBeenCalledTimes(1)
		expect(saveObject).not.toHaveBeenCalled()
	})

	it('asks the person register first and stops at the first hit', async () => {
		const fetchObject = reads({ id: 'case-1', requester: 'uuid-1' }, { brpPerson: PERSON_ROW, kvkCompany: COMPANY_ROW })
		const { saveObject } = await mountProjection({ fetchObject })
		expect(fetchObject.mock.calls.map((call) => call[0])).toEqual(['case', 'brpPerson'])
		expect(saveObject).toHaveBeenCalledTimes(1)
		expect(saveObject.mock.calls[0][1].initiatorType).toBe('person')
	})

	it('asks the company register when the person register does not hold it, or fails', async () => {
		for (const person of [null, new Error('down')]) {
			const fetchObject = reads({ id: 'case-1', requester: 'uuid-1' }, { brpPerson: person, kvkCompany: COMPANY_ROW })
			const { saveObject } = await mountProjection({ fetchObject })
			expect(fetchObject.mock.calls.map((call) => call[0])).toEqual(['case', 'brpPerson', 'kvkCompany'])
			expect(fetchObject.mock.calls[2][1]).toBe('uuid-1')
			expect(saveObject.mock.calls[0]).toEqual(['case', {
				id: 'case-1',
				requester: 'uuid-1',
				initiatorType: 'company',
				initiatorSourceId: '24157431',
				initiatorDisplayName: 'Visser Bouw B.V.',
			}])
		}
	})

	it('writes nothing when neither register holds the requester', async () => {
		const fetchObject = reads({ id: 'case-1', requester: 'uuid-1' }, {})
		const { saveObject } = await mountProjection({ fetchObject })
		expect(fetchObject).toHaveBeenCalledTimes(3)
		expect(saveObject).not.toHaveBeenCalled()
	})

	it('takes the case id from @self when the case carries none at the top', async () => {
		const fetchObject = reads({ '@self': { id: 'self-1' }, requester: 'uuid-1' }, { brpPerson: PERSON_ROW })
		const { saveObject } = await mountProjection({ fetchObject })
		expect(saveObject.mock.calls[0][1].id).toBe('self-1')
	})

	it('swallows a refused write', async () => {
		const fetchObject = reads({ id: 'case-1', requester: 'uuid-1' }, { brpPerson: PERSON_ROW })
		const saveObject = vi.fn().mockRejectedValue(new Error('refused'))
		await mountProjection({ fetchObject, saveObject })
		expect(saveObject).toHaveBeenCalledTimes(1)
	})

	it('stores an empty number for a row without one, and for a mask over another requester', async () => {
		const rows = [
			{ ...PERSON_ROW, citizenServiceNumber: undefined },
			{ ...PERSON_ROW, citizenServiceNumber: '*****0627' },
		]
		for (const row of rows) {
			const caseObject = { id: 'case-1', requester: 'uuid-1', initiatorType: 'person', initiatorSourceId: '1', initiatorDisplayName: 'Someone else' }
			const { saveObject } = await mountProjection({ fetchObject: reads(caseObject, { brpPerson: row }) })
			expect(saveObject.mock.calls[0][1]).toEqual({
				...caseObject,
				initiatorSourceId: '',
				initiatorDisplayName: 'Stephan Janssen',
			})
		}
	})

	it('keeps the stored number and writes the name when a masked requester is the same type and name', async () => {
		const caseObject = { id: 'case-1', requester: 'uuid-1', initiatorType: 'person', initiatorSourceId: '999990627', initiatorDisplayName: 'Stephan Janssen' }
		const { saveObject } = await mountProjection({
			fetchObject: reads(caseObject, { brpPerson: { ...PERSON_ROW, citizenServiceNumber: '' } }),
		})
		expect(saveObject).not.toHaveBeenCalled()
	})
})
