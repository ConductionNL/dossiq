// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for PublicStatusPage's `loadStatus`
 * (src/views/public/PublicStatusPage.vue): the reads it makes, the survivor
 * of a merged case, and the status fields it fills.
 *
 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.1
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import PublicStatusPage from '../../src/views/public/PublicStatusPage.vue'

const { loadStatus } = PublicStatusPage.methods

/**
 * A stand-in for the page instance, carrying every method.
 *
 * @return {object} The instance.
 */
function instance() {
	return { ...PublicStatusPage.methods, token: 'to ken', loading: null, error: '', statusData: null, timeline: 'old', mergedFrom: '' }
}

/**
 * A fetch answer.
 *
 * @param {boolean} ok Whether it succeeded.
 * @param {object} body The JSON body.
 * @return {object} The response.
 */
function answer(ok, body = {}) {
	return { ok, json: async () => body }
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('public status load golden', () => {
	it('says the status is unavailable when the token does not resolve', async () => {
		const fetchStub = vi.fn().mockResolvedValue(answer(false))
		vi.stubGlobal('fetch', fetchStub)
		const vm = instance()
		await loadStatus.call(vm)
		expect(fetchStub).toHaveBeenCalledWith('/apps/openregister/api/public/case-tokens/to%20ken')
		expect(vm.error).toBe('Status unavailable')
		expect(vm.loading).toBe(false)
		expect(vm.statusData).toBeNull()
		expect(vm.timeline).toBe('old')
	})

	it('says the status could not load when the read throws', async () => {
		vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('net')))
		const vm = instance()
		await loadStatus.call(vm)
		expect(vm.error).toBe('Could not load status')
		expect(vm.loading).toBe(false)
	})

	it('fills the status fields from the case', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(answer(true, {
			label: 'Label',
			timeline: [{ id: 1 }],
			object: {
				title: 'Title',
				identifier: 'Z-1',
				statusPublicLabel: 'In behandeling',
				statusPublicDescription: 'We kijken ernaar.',
				plannedEndDate: '2026-12-01',
				startDate: '2026-11-01',
			},
		})))
		const vm = instance()
		await loadStatus.call(vm)
		expect(vm.timeline).toEqual([{ id: 1 }])
		expect(vm.statusData).toEqual({
			title: 'Title',
			identifier: 'Z-1',
			currentStatus: 'In behandeling',
			currentStatusDescription: 'We kijken ernaar.',
			plannedEndDate: '2026-12-01',
			startDate: '2026-11-01',
		})
		expect(vm.mergedFrom).toBe('')
		expect(vm.error).toBe('')
	})

	it.each([
		[{ label: 'Label', timeline: 'x' }, 'Label'],
		[{ timeline: null, object: {} }, ''],
	])('falls back for a case without fields (%j)', async (body, title) => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(answer(true, body)))
		const vm = instance()
		await loadStatus.call(vm)
		expect(vm.timeline).toEqual([])
		expect(vm.statusData).toEqual({
			title,
			identifier: '',
			currentStatus: '',
			currentStatusDescription: '',
			plannedEndDate: null,
			startDate: null,
		})
	})

	it('reads the survivor of a merged case and names the merged one', async () => {
		const fetchStub = vi.fn()
			.mockResolvedValueOnce(answer(true, { object: { mergedInto: 'u2', identifier: 'Z-1', title: 'Old' }, timeline: [1] }))
			.mockResolvedValueOnce(answer(true, { object: { identifier: 'Z-2', title: 'New' } }))
		vi.stubGlobal('fetch', fetchStub)
		const vm = instance()
		await loadStatus.call(vm)
		expect(fetchStub.mock.calls[1][0]).toBe('/apps/dossiq/api/public/case-tokens/to%20ken/survivor')
		expect(vm.mergedFrom).toBe('Z-1')
		expect(vm.statusData.title).toBe('New')
		expect(vm.statusData.identifier).toBe('Z-2')
		expect(vm.timeline).toEqual([1])
	})

	it('keeps the merged case when the survivor answers no case; a case without identifier gets an empty name', async () => {
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce(answer(true, { object: { mergedInto: 'u2', title: 'Old' } }))
			.mockResolvedValueOnce(answer(true, {})))
		const vm = instance()
		await loadStatus.call(vm)
		expect(vm.mergedFrom).toBe('')
		expect(vm.statusData.title).toBe('Old')
	})

	it('keeps the merged case when the survivor does not resolve', async () => {
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce(answer(true, { object: { mergedInto: 'u2', identifier: 'Z-1', title: 'Old' } }))
			.mockResolvedValueOnce(answer(false)))
		const vm = instance()
		await loadStatus.call(vm)
		expect(vm.mergedFrom).toBe('')
		expect(vm.statusData.title).toBe('Old')
		expect(vm.error).toBe('')
	})
})
