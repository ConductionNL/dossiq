// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BulkReassignModal, mounted: the handler fields and case type filter feed
 * the preview, the preview table lists cases and tasks, Reassign all only
 * works after a non-empty preview, and the per-item results and failures
 * are shown.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A rendering stand-in for a Nextcloud control that keeps its props and slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function control(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'open', 'size', 'modelValue', 'options', 'disabled', 'type', 'label', 'placeholder', 'inputLabel', 'ariaLabelCombobox', 'trackBy'],
		emits: ['update:open', 'closing', 'update:modelValue', 'click'],
		render() {
			return h('div', { class: name }, [
				...(this.$slots.icon?.() ?? []),
				...(this.$slots.default?.() ?? []),
				...(this.$slots.actions?.() ?? []),
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcEmptyContent: control('NcEmptyContent'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
}))
const previewReassignment = vi.fn()
const executeReassignment = vi.fn()
vi.mock('../../src/services/substitutionApi.js', () => ({
	previewReassignment: (...a) => previewReassignment(...a),
	executeReassignment: (...a) => executeReassignment(...a),
}))
const objectStore = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: BulkReassignModal } = await import('../../src/modals/BulkReassignModal.vue')

const PREVIEW = {
	cases: [{ id: 'c1', title: 'Dakkapel', caseType: 'ct-1', status: 'open', deadline: '2026-12-01T17:00:00Z' }],
	tasks: [{ id: 't1', status: 'todo', dueDate: '' }],
}

/**
 * Mount the modal, let the case types load, and fill the given handlers.
 *
 * @param {string} from The departing handler.
 * @param {string} to   The receiving handler.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(from = '', to = '') {
	const wrapper = mount(BulkReassignModal)
	await flushPromises()
	const [fromField, toField] = wrapper.findAllComponents({ name: 'NcTextField' })
	fromField.vm.$emit('update:modelValue', from)
	toField.vm.$emit('update:modelValue', to)
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The buttons: preview, close, reassign all.
 *
 * @param {object} wrapper The mounted modal.
 * @return {Array<object>} The button wrappers.
 */
function buttons(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' })
}

beforeEach(() => {
	vi.clearAllMocks()
	objectStore.fetchCollection.mockResolvedValue([{ id: 'ct-1', title: 'Omgevingsvergunning' }])
	previewReassignment.mockResolvedValue(PREVIEW)
	executeReassignment.mockResolvedValue({
		succeeded: 1,
		failed: 1,
		batchId: 'b-7',
		results: [
			{ type: 'case', id: 'c1', title: 'Dakkapel', success: true },
			{ type: 'task', id: 't1', success: false },
		],
	})
})

describe('BulkReassignModal mounted', () => {
	it('offers the case types and keeps preview and reassign off until there is something to do', async () => {
		const wrapper = await mounted()

		expect(objectStore.fetchCollection).toHaveBeenCalledWith('caseType', { _limit: 200 })
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([{ id: 'ct-1', title: 'Omgevingsvergunning' }])
		expect(buttons(wrapper)[0].props('disabled')).toBe(true)
		expect(buttons(wrapper)[2].props('disabled')).toBe(true)
	})

	it('previews the departing handler\'s work, filtered by the chosen case type, in a table', async () => {
		const wrapper = await mounted(' jan ', 'piet')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'ct-1', title: 'Omgevingsvergunning' })
		await wrapper.vm.$nextTick()

		buttons(wrapper)[0].vm.$emit('click')
		await flushPromises()

		expect(previewReassignment).toHaveBeenCalledWith('jan', 'ct-1')
		expect(wrapper.find('[data-testid="reassign-preview"] h4').text()).toBe('Affected open work (2)')
		const rows = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
		expect(rows).toEqual([
			['Case', 'Dakkapel', 'Omgevingsvergunning', 'open', '2026-12-01'],
			['Task', '—', '', 'todo', '—'],
		])
		expect(buttons(wrapper)[2].props('disabled')).toBe(false)
	})

	it('shows the empty state for a preview with no open work and keeps reassign off', async () => {
		previewReassignment.mockResolvedValue({ cases: [], tasks: [] })
		const wrapper = await mounted('jan', 'piet')

		buttons(wrapper)[0].vm.$emit('click')
		await flushPromises()

		expect(previewReassignment).toHaveBeenCalledWith('jan', undefined)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No open work to reassign')
		expect(wrapper.find('table').exists()).toBe(false)
		expect(buttons(wrapper)[2].props('disabled')).toBe(true)
	})

	it('reassigns everything, lists each item\'s outcome and announces it', async () => {
		const wrapper = await mounted('jan', ' piet ')
		buttons(wrapper)[0].vm.$emit('click')
		await flushPromises()

		buttons(wrapper)[2].vm.$emit('click')
		await flushPromises()

		expect(executeReassignment).toHaveBeenCalledWith('jan', 'piet', undefined)
		const results = wrapper.find('[data-testid="reassign-results"]')
		expect(results.find('p').text()).toBe('1 succeeded, 1 failed (batch b-7)')
		expect(results.findAll('li').map((li) => [li.classes()[0], li.text().replace(/\s+/g, ' ')])).toEqual([
			['ok', 'Dakkapel — reassigned'],
			['fail', 't1 — failed'],
		])
		expect(wrapper.find('[data-testid="reassign-preview"]').exists()).toBe(false)
		expect(wrapper.emitted('reassigned')[0][0].batchId).toBe('b-7')
	})

	it('shows the server\'s refusal of a preview and of a reassignment', async () => {
		previewReassignment.mockRejectedValueOnce({ response: { data: { error: 'Unknown user' } } })
		const wrapper = await mounted('ghost', 'piet')
		buttons(wrapper)[0].vm.$emit('click')
		await flushPromises()
		expect(wrapper.find('.form-error--server').text()).toBe('Unknown user')

		buttons(wrapper)[0].vm.$emit('click')
		await flushPromises()
		executeReassignment.mockRejectedValueOnce(new Error('Gateway timeout'))
		buttons(wrapper)[2].vm.$emit('click')
		await flushPromises()
		expect(wrapper.find('.form-error--server').text()).toBe('Gateway timeout')
		expect(wrapper.emitted('reassigned')).toBeUndefined()
	})

	it('treats a failing case type read as no case types', async () => {
		objectStore.fetchCollection.mockRejectedValue(new Error('down'))
		const wrapper = await mounted()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([])
	})

	it('closes on Close, on the dialog closing, and when the dialog is set closed', async () => {
		const wrapper = await mounted()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('close')).toBeUndefined()
		dialog.vm.$emit('update:open', false)
		dialog.vm.$emit('closing')
		buttons(wrapper)[1].vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(3)
	})
})
