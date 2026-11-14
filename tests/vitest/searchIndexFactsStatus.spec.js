// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The search index section of the admin settings: it reads OpenRegister's
 * index status once mounted, says so when OpenRegister does not answer, and
 * queues a maintenance run from the Integrations variant.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockShowSuccess = vi.fn()
const mockShowError = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockShowSuccess(...a),
	showError: (...a) => mockShowError(...a),
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render: () => null })
	return {
		NcButton: stub('NcButton'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard'),
	}
})

const { default: SearchIndexFacts } = await import('../../src/views/settings/components/SearchIndexFacts.vue')

const STATUS = {
	concurrentRebuildSupported: true,
	tables: { case: 'oc_openregister_table_5_12' },
	tableCount: 1,
	indexCount: 1,
	lastRun: { startedAt: '2026-11-12T03:00:00Z', finishedAt: '2026-11-12T03:01:30Z' },
	rowsInTables: 1200,
	rowsInIndex: 1180,
	indexed: { case: 1180 },
}

/**
 * Mount the section after the status read settles.
 *
 * @param {string} variant run or rows.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(variant = 'run') {
	const wrapper = shallowMount(SearchIndexFacts, { propsData: { variant } })
	await flushPromises()
	return wrapper
}

describe('the search index section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('reads the status from OpenRegister once mounted', async () => {
		axios.get.mockResolvedValue({ data: STATUS })

		const wrapper = await mounted()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get.mock.calls[0][0]).toContain('/apps/openregister/api/settings/search-index')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.status.rowsInIndex).toBe(1180)
		expect(wrapper.vm.status.lastRunSeconds).toBe(90)
	})

	it('draws the run facts with an update button in the Integrations variant', async () => {
		axios.get.mockResolvedValue({ data: STATUS })

		const wrapper = await mounted('run')

		expect(wrapper.vm.rows.map((row) => row.label)).toEqual(['Latest run', 'Duration', 'Indexed'])
		expect(wrapper.find('[data-testid="search-index-facts-run"]').exists()).toBe(true)
	})

	it('draws the row counts without a button in the Advanced variant', async () => {
		axios.get.mockResolvedValue({ data: STATUS })

		const wrapper = await mounted('rows')

		expect(wrapper.vm.rows.slice(0, 2).map((row) => row.label)).toEqual(['Rows in the tables', 'Rows in the index'])
		expect(wrapper.find('[data-testid="search-index-facts-run"]').exists()).toBe(false)
	})

	it('draws nothing before a status has been read', () => {
		axios.get.mockReturnValue(new Promise(() => {}))

		const wrapper = shallowMount(SearchIndexFacts)

		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.rows).toEqual([])
	})

	it('says OpenRegister does not answer when the read fails', async () => {
		axios.get.mockRejectedValue(new Error('Request failed with status code 502'))

		const wrapper = await mounted()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.error).toBe('Request failed with status code 502')
		expect(wrapper.vm.rows).toEqual([])
		expect(wrapper.find('[data-testid="search-index-facts-error"]').exists()).toBe(true)
	})

	it('passes on the refusal OpenRegister wrote', async () => {
		axios.get.mockResolvedValue({ data: { error: 'Search index tables are not set up.' } })

		const wrapper = await mounted()

		expect(wrapper.vm.error).toBe('Search index tables are not set up.')
	})

	it('queues a maintenance run and confirms it', async () => {
		axios.get.mockResolvedValue({ data: STATUS })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mounted()

		await wrapper.vm.queueRun()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('/apps/openregister/api/settings/search-index/run')
		expect(mockShowSuccess).toHaveBeenCalledWith('The search index is updated in the background.')
		expect(mockShowError).not.toHaveBeenCalled()
		expect(wrapper.vm.queueing).toBe(false)
	})

	it('names the error when the run cannot be queued', async () => {
		axios.get.mockResolvedValue({ data: STATUS })
		axios.post.mockRejectedValue(new Error('Forbidden'))
		const wrapper = await mounted()

		await wrapper.vm.queueRun()

		expect(mockShowError).toHaveBeenCalledWith('The search index could not be updated: Forbidden')
		expect(mockShowSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.queueing).toBe(false)
	})
})
