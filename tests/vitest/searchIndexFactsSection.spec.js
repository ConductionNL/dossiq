// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The search index section reads OpenRegister's status, draws the rows its
 * variant asks for, and queues a maintenance run with a toast either way.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.fn()
const mockPost = vi.fn()
const toasts = vi.hoisted(() => ({ success: [], error: [] }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (m) => toasts.success.push(m),
	showError: (m) => toasts.error.push(m),
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return { NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }
})

const { default: SearchIndexFacts } = await import('../../src/views/settings/components/SearchIndexFacts.vue')

const status = { tableCount: 2, indexCount: 2, lastRun: null, rowsInTables: 12, rowsInIndex: 7, indexed: null }

describe('SearchIndexFacts', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		toasts.success = []
		toasts.error = []
	})

	it('draws the run facts inline with an update button', async () => {
		mockGet.mockResolvedValue({ data: status })
		const wrapper = shallowMount(SearchIndexFacts)
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/settings/search-index')
		const list = wrapper.findComponent({ name: 'SettingsFactsList' })
		expect(list.props('layout')).toBe('inline')
		expect(list.props('rows').map((r) => r.label)).toEqual(['Latest run', 'Duration', 'Indexed'])
		expect(list.props('rows')[1]).toEqual({ label: 'Duration', value: 'no run yet', muted: true })
		expect(wrapper.find('[data-testid="search-index-facts-run"]').exists()).toBe(true)
	})

	it('draws the row facts as boxes without a button', async () => {
		mockGet.mockResolvedValue({ data: status })
		const wrapper = shallowMount(SearchIndexFacts, { props: { variant: 'rows' } })
		await flushPromises()

		const list = wrapper.findComponent({ name: 'SettingsFactsList' })
		expect(list.props('layout')).toBe('boxes')
		expect(list.props('rows').map((r) => r.label)).toEqual(['Rows in the tables', 'Rows in the index', 'Latest run', 'Rebuild with'])
		expect(list.props('rows')[3].value).toBe('occ openregister:tables:search-index rebuild')
		expect(wrapper.find('[data-testid="search-index-facts-run"]').exists()).toBe(false)
	})

	it('shows the refusal instead of the rows', async () => {
		mockGet.mockResolvedValue({ data: { error: 'Not an admin' } })
		const wrapper = shallowMount(SearchIndexFacts)
		await flushPromises()

		expect(wrapper.vm.error).toBe('Not an admin')
		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.find('[data-testid="search-index-facts-error"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'SettingsFactsList' }).exists()).toBe(false)
	})

	it('queues a run and confirms it', async () => {
		mockGet.mockResolvedValue({ data: status })
		mockPost.mockResolvedValue({ data: {} })
		const wrapper = shallowMount(SearchIndexFacts)
		await flushPromises()

		await wrapper.vm.queueRun()

		expect(mockPost).toHaveBeenCalledWith('/apps/openregister/api/settings/search-index/run')
		expect(toasts.success).toEqual(['The search index is updated in the background.'])
		expect(toasts.error).toEqual([])
		expect(wrapper.vm.queueing).toBe(false)
	})

	it('reports a run that could not be queued', async () => {
		mockGet.mockResolvedValue({ data: status })
		mockPost.mockRejectedValue(new Error('busy'))
		const wrapper = shallowMount(SearchIndexFacts)
		await flushPromises()

		await wrapper.vm.queueRun()

		expect(toasts.error).toEqual(['The search index could not be updated: busy'])
		expect(toasts.success).toEqual([])
		expect(wrapper.vm.queueing).toBe(false)
	})
})
