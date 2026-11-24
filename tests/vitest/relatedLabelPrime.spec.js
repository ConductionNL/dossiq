/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The case list's Case type and Status labels are looked up beside the list
 * request, when the Cases route is entered, not after the rows arrive.
 *
 * @spec openspec/specs/case-management/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = {
	collections: {},
	objectTypeRegistry: { caseType: {}, statusType: {} },
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([{ id: 's-1', name: 'Received' }])),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters, primeRelatedLabels, resetRelatedLabels } = await import('../../src/services/formatters.js')
const { installRelatedLabelPrime, isLabelListRoute, primeForRoute } = await import('../../src/services/relatedLabelPrime.js')

describe('primeRelatedLabels', () => {
	beforeEach(() => {
		resetRelatedLabels()
		store.fetchCollectionForOptions.mockClear()
		store.objectTypeRegistry = { caseType: {}, statusType: {} }
	})

	it('starts the case type and status lookups before any cell asks', () => {
		expect(primeRelatedLabels()).toEqual(['caseType', 'statusType'])
		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('caseType', { _limit: 500 })
		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('statusType', { _limit: 500 })
	})

	it('lets the cells read the primed labels without a second lookup', async () => {
		primeRelatedLabels(['statusType'])
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(formatters.statusTypeName('s-1')).toBe('Received')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
	})

	it('starts nothing twice', () => {
		primeRelatedLabels()
		expect(primeRelatedLabels()).toEqual([])
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(2)
	})

	it('skips a type that is not registered yet', () => {
		store.objectTypeRegistry = { statusType: {} }
		expect(primeRelatedLabels()).toEqual(['statusType'])
	})
})

describe('relatedLabelPrime', () => {
	it('knows the case list route', () => {
		expect(isLabelListRoute({ name: 'Cases' })).toBe(true)
		expect(isLabelListRoute({ name: 'CaseDetail' })).toBe(false)
		expect(isLabelListRoute(null)).toBe(false)
	})

	it('primes only on the case list', () => {
		const prime = vi.fn(() => ['caseType'])
		expect(primeForRoute({ name: 'Dashboard' }, prime)).toEqual([])
		expect(prime).not.toHaveBeenCalled()
		expect(primeForRoute({ name: 'Cases' }, prime)).toEqual(['caseType'])
	})

	it('primes on every navigation to the case list', async () => {
		let hook = null
		const router = { afterEach: (fn) => { hook = fn }, currentRoute: { value: { name: 'Dashboard' } } }
		const prime = vi.fn(() => [])
		await installRelatedLabelPrime(router, Promise.resolve(), prime)
		hook({ name: 'CaseDetail' })
		hook({ name: 'Cases' })
		expect(prime).toHaveBeenCalledTimes(1)
	})

	it('primes a first load on the case list once the object types are registered', async () => {
		let ready = null
		const router = { afterEach: () => {}, currentRoute: { value: { name: 'Cases' } } }
		const prime = vi.fn(() => ['caseType', 'statusType'])
		const done = installRelatedLabelPrime(router, new Promise((resolve) => { ready = resolve }), prime)
		expect(prime).not.toHaveBeenCalled()
		ready()
		await expect(done).resolves.toEqual(['caseType', 'statusType'])
	})
})
