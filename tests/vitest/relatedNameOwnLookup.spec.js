/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A status pill on a case card reads the status type's name, not its UUID,
 * also when the store's shared statusType collection holds another list's
 * rows (r33 DqAanMijToegewezen: every status pill read 3c0f5a00-...-b012).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = {
	collections: { statusType: [{ id: 'other', title: 'Elders' }] },
	objectTypeRegistry: { statusType: {} },
	fetchCollection: vi.fn(() => Promise.resolve()),
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([{ id: 's-b012', name: 'In behandeling' }])),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters, resetRelatedLabels } = await import('../../src/services/formatters.js')

describe('statusTypeName', () => {
	beforeEach(() => {
		resetRelatedLabels()
		store.fetchCollectionForOptions.mockClear()
	})

	it('reads the name from its own lookup when the shared collection lacks the row', async () => {
		expect(formatters.statusTypeName('s-b012')).toBe('s-b012')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(formatters.statusTypeName('s-b012')).toBe('In behandeling')
		expect(store.fetchCollectionForOptions).toHaveBeenCalledTimes(1)
		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('statusType', { _limit: 500 })
	})

	it('reads a row the shared collection holds', () => {
		expect(formatters.statusTypeName('other')).toBe('Elders')
	})
})
