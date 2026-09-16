/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What an administrator reads beside each seeded object, and when they are
 * warned before taking a newer version.
 *
 * 🔑 THE THREE ANSWERS ARE EASY TO FLATTEN INTO TWO. "Shipped and changed here"
 * reading as "yours" hides that an upgrade is waiting; reading as "shipped"
 * hides that somebody edited it. Both flattenings look fine on screen, which is
 * why they are asserted separately here.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import { listShipped } from '../../src/services/starterApi.js'
import {
	adoptionLosesLocalChange,
	hasUpdate,
	shippedLabel,
} from '../../src/utils/starterStates.js'

describe('the shipped configuration screen', () => {
	it('names an untouched shipped object as shipped and unchanged', () => {
		expect(shippedLabel({ state: 'shipped' })).toBe('Shipped, unchanged')
	})

	it('names an edited shipped object as changed here, not as ours', () => {
		expect(shippedLabel({ state: 'changed' })).toBe('Shipped, changed here')
		expect(shippedLabel({ state: 'changed' })).not.toBe(shippedLabel({ state: 'local' }))
	})

	it('names a locally authored object as ours', () => {
		expect(shippedLabel({ state: 'local' })).toBe('Yours')
		expect(shippedLabel(undefined)).toBe('Yours')
	})

	it('names a seeded row somebody deleted here rather than dropping it', () => {
		expect(shippedLabel({ state: 'removed' })).toBe('Shipped, removed here')
	})

	it('offers a newer version only when one is actually waiting', () => {
		expect(hasUpdate({ state: 'shipped', updateAvailable: true })).toBe(true)
		expect(hasUpdate({ state: 'shipped', updateAvailable: false })).toBe(false)
		expect(hasUpdate(null)).toBe(false)
	})

	it('does not offer an update on a row that is no longer there', () => {
		expect(hasUpdate({ state: 'removed', updateAvailable: true })).toBe(false)
	})

	it('warns before an adoption overwrites something somebody typed', () => {
		expect(adoptionLosesLocalChange({ state: 'changed' })).toBe(true)
		expect(adoptionLosesLocalChange({ state: 'shipped' })).toBe(false)
		expect(adoptionLosesLocalChange({ state: 'local' })).toBe(false)
	})
})

describe('starterApi.listShipped', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads the schema it was asked for', async () => {
		axios.get.mockResolvedValue({ data: { items: [], total: 0 } })

		const result = await listShipped('caseType')

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/starter/shipped/caseType')
		expect(result).toEqual({ items: [], total: 0 })
	})

	it('lets a failure reach the caller instead of answering an empty list', async () => {
		axios.get.mockRejectedValue(new Error('503'))

		await expect(listShipped('caseType')).rejects.toThrow('503')
	})
})
