/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the related-name lookup behind the
 * `caseTypeName`, `statusTypeName` and `caseTitle` formatters
 * (src/services/formatters.js): the shared collection, the module's own
 * lookup, when that lookup starts, and when it may start again.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

let throwOnStore = false
const store = {
	collections: {},
	objectTypeRegistry: {},
	fetchCollectionForOptions: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => {
		if (throwOnStore) {
			throw new Error('no pinia')
		}
		return store
	},
}))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters, resetRelatedLabels } = await import('../../src/services/formatters.js')

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('related name lookup', () => {
	beforeEach(() => {
		resetRelatedLabels()
		throwOnStore = false
		store.collections = {}
		store.objectTypeRegistry = { caseType: {}, statusType: {} }
		store.fetchCollectionForOptions = vi.fn(() => Promise.resolve([]))
	})

	it.each([undefined, null, ''])('answers a dash for %j', (value) => {
		expect(formatters.caseTypeName(value)).toBe('-')
	})

	it('answers the uuid when the store cannot be reached', () => {
		throwOnStore = true
		expect(formatters.caseTypeName('ct-1')).toBe('ct-1')
	})

	it('reads the shared collection by id or by @self id, title before name', () => {
		store.collections.caseType = [
			{ id: 'ct-1', title: 'Kap', name: 'kap' },
			{ '@self': { id: 'ct-2' }, name: 'Bouw' },
			{ id: 'ct-3' },
		]
		expect(formatters.caseTypeName('ct-1')).toBe('Kap')
		expect(formatters.caseTypeName('ct-2')).toBe('Bouw')
		expect(formatters.caseTypeName('ct-3')).toBe('ct-3')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
	})

	it('looks the labels up once per type and reads them afterwards', async () => {
		store.fetchCollectionForOptions = vi.fn(() => Promise.resolve([
			{ id: 'ct-9', title: 'Negen' },
			{ '@self': { id: 'ct-8' }, name: 'Acht' },
			{ id: 'ct-7' },
			null,
		]))
		expect(formatters.caseTypeName('ct-9')).toBe('ct-9')
		expect(formatters.caseTypeName('ct-8')).toBe('ct-8')
		await settle()
		expect(formatters.caseTypeName('ct-9')).toBe('Negen')
		expect(formatters.caseTypeName('ct-8')).toBe('Acht')
		expect(formatters.caseTypeName('ct-7')).toBe('ct-7')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('caseType', { _limit: 500 })
		expect(formatters.statusTypeName('st-1')).toBe('st-1')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(2)
	})

	it.each([
		['an empty answer', () => Promise.resolve([])],
		['a non-list answer', () => Promise.resolve('nothing')],
		['a refused lookup', () => Promise.reject(new Error('down'))],
	])('looks again after %s', async (_label, answer) => {
		store.fetchCollectionForOptions = vi.fn(answer)
		formatters.caseTypeName('ct-1')
		await settle()
		formatters.caseTypeName('ct-1')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(2)
	})

	it('does not look up a type the store has not registered, or without the lookup', () => {
		store.objectTypeRegistry = {}
		expect(formatters.caseTitle('c-1')).toBe('c-1')
		expect(store.fetchCollectionForOptions).not.toHaveBeenCalled()
		store.objectTypeRegistry = { caseType: {} }
		delete store.fetchCollectionForOptions
		expect(formatters.caseTypeName('ct-1')).toBe('ct-1')
	})

	it('does not look again while a lookup is under way', () => {
		store.fetchCollectionForOptions = vi.fn(() => new Promise(() => {}))
		formatters.caseTypeName('ct-1')
		formatters.caseTypeName('ct-2')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
	})
})
