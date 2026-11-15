// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BulkReassignModal mounted and driven through its template: the handler
 * fields and the case-type filter feed the preview, the preview table lists
 * the affected work, and the per-item results show after the reassignment.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => (await import('./helpers/ncFormStubs.js')).ncFormStubs())

const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/services/substitutionApi.js', () => ({ executeReassignment: vi.fn(), previewReassignment: vi.fn() }))

const substitution = await import('../../src/services/substitutionApi.js')
const { default: BulkReassignModal } = await import('../../src/modals/BulkReassignModal.vue')

const PREVIEW = {
	cases: [{ id: 'c1', title: 'Dakkapel Kerkstraat 4', caseType: 'ct-omg', status: 'in behandeling', deadline: '2026-12-01T17:00:00Z' }],
	tasks: [{ id: 't1', title: 'Advies welstand', status: 'open' }],
}

/**
 * Mount the modal and let the case types load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountModal() {
	const wrapper = mount(BulkReassignModal)
	await flushPromises()
	return wrapper
}

/**
 * The button with the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The button text.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

describe('BulkReassignModal mounted', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		objectStore.fetchCollection.mockResolvedValue([{ id: 'ct-omg', title: 'Omgevingsvergunning' }, { id: 'ct-bez', title: 'Bezwaar' }])
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('previews the work of the departing handler for the chosen case type, then reassigns it and lists the results', async () => {
		const wrapper = await mountModal()
		expect(wrapper.find('.nc-frame__name').text()).toBe('Bulk reassign workload')
		expect(wrapper.find('.nc-select').findAll('li').map((li) => li.text())).toEqual(['Omgevingsvergunning', 'Bezwaar'])
		expect(button(wrapper, 'Preview affected work').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Reassign all').attributes('disabled')).toBeDefined()

		const [from, to] = wrapper.findAllComponents({ name: 'NcTextField' })
		from.vm.$emit('update:modelValue', 'ann')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'ct-omg', title: 'Omgevingsvergunning' })
		await flushPromises()

		substitution.previewReassignment.mockResolvedValueOnce(PREVIEW)
		await button(wrapper, 'Preview affected work').trigger('click')
		await flushPromises()

		expect(substitution.previewReassignment).toHaveBeenCalledWith('ann', 'ct-omg')
		const preview = wrapper.find('[data-testid="reassign-preview"]')
		expect(preview.find('h4').text()).toBe('Affected open work (2)')
		expect(preview.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))).toEqual([
			['Case', 'Dakkapel Kerkstraat 4', 'Omgevingsvergunning', 'in behandeling', '2026-12-01'],
			['Task', 'Advies welstand', '', 'open', '—'],
		])
		expect(button(wrapper, 'Reassign all').attributes('disabled')).toBeDefined()

		to.vm.$emit('update:modelValue', 'bob')
		await flushPromises()
		substitution.executeReassignment.mockResolvedValueOnce({
			succeeded: 1,
			failed: 1,
			batchId: 'b-42',
			results: [{ type: 'case', id: 'c1', title: 'Dakkapel Kerkstraat 4', success: true }, { type: 'task', id: 't1', success: false }],
		})
		await button(wrapper, 'Reassign all').trigger('click')
		await flushPromises()

		expect(substitution.executeReassignment).toHaveBeenCalledWith('ann', 'bob', 'ct-omg')
		expect(wrapper.find('[data-testid="reassign-preview"]').exists()).toBe(false)
		const results = wrapper.find('[data-testid="reassign-results"]')
		expect(results.find('p').text()).toBe('1 succeeded, 1 failed (batch b-42)')
		expect(results.findAll('li').map((li) => [li.classes()[0], li.text().replace(/\s+/g, ' ')])).toEqual([
			['ok', 'Dakkapel Kerkstraat 4 — reassigned'],
			['fail', 't1 — failed'],
		])
		expect(wrapper.emitted('reassigned')).toHaveLength(1)
	})

	it('says when there is no open work, and shows a failed preview', async () => {
		const wrapper = await mountModal()
		wrapper.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', 'ann')
		await flushPromises()

		substitution.previewReassignment.mockResolvedValueOnce({ cases: [], tasks: [] })
		await button(wrapper, 'Preview affected work').trigger('click')
		await flushPromises()
		expect(substitution.previewReassignment).toHaveBeenCalledWith('ann', undefined)
		expect(wrapper.find('[data-testid="reassign-preview"] .nc-empty strong').text()).toBe('No open work to reassign')
		expect(wrapper.find('table').exists()).toBe(false)

		substitution.previewReassignment.mockRejectedValueOnce(new Error('Unknown user ann'))
		await button(wrapper, 'Preview affected work').trigger('click')
		await flushPromises()
		expect(wrapper.find('.form-error--server').text()).toBe('Unknown user ann')
	})

	it('closes from its close button, the dialog closing and the dialog being closed', async () => {
		const wrapper = await mountModal()

		await button(wrapper, 'Close').trigger('click')
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		dialog.vm.$emit('closing')
		dialog.vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(3)
	})
})
