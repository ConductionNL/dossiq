// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * InitiatorPickerModal: choosing who submitted a case. A pick becomes the
 * initiator choice the case is created with; it can be skipped, and Use is
 * only possible once something is chosen.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockChoice = vi.fn()

vi.mock('../../src/services/initiatorSearch.js', () => ({
	initiatorChoice: (...a) => mockChoice(...a),
}))

function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'sources',
			'selectedLabel',
			'inputLabel',
			'labelOutside',
			'placeholder',
			'disabled',
			'variant',
			'name',
			'size',
		],
		emits: ['select', 'click', 'close'],
		render() {
			return h('div', { class: name }, this.$slots.default?.() ?? [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcModal: control('NcModal'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnPartyPicker: control('CnPartyPicker'),
}))

const { default: InitiatorPickerModal } =
	await import('../../src/modals/InitiatorPickerModal.vue')

const choice = {
	requester: 'brp-123',
	initiatorSourceId: '999990019',
	initiatorDisplayName: 'Ann de Vries',
}

beforeEach(() => {
	mockChoice.mockReset()
	mockChoice.mockResolvedValue(choice)
})

describe('InitiatorPickerModal', () => {
	it('offers residents, organisations and contacts, with nothing chosen yet', () => {
		const wrapper = mount(InitiatorPickerModal)
		const picker = wrapper.findComponent({ name: 'CnPartyPicker' })

		expect(picker.props('sources').map((s) => s.id)).toEqual([
			'person',
			'company',
			'contact',
		])
		expect(picker.props('modelValue')).toBe(null)
		expect(picker.props('selectedLabel')).toBe('')
		expect(
			wrapper.findAllComponents({ name: 'NcButton' })[1].props('disabled'),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="initiator-picker-selection"]').exists(),
		).toBe(false)
	})

	it('turns a pick into the initiator choice and confirms it', async () => {
		const wrapper = mount(InitiatorPickerModal)
		const row = { id: 'brp-123', displayName: 'Ann de Vries' }

		wrapper
			.findComponent({ name: 'CnPartyPicker' })
			.vm.$emit('select', { source: 'person', row })
		await flushPromises()

		expect(mockChoice).toHaveBeenCalledWith('person', row)
		const picker = wrapper.findComponent({ name: 'CnPartyPicker' })
		expect(picker.props('modelValue')).toBe('brp-123')
		expect(picker.props('selectedLabel')).toBe('Ann de Vries')
		expect(
			wrapper.find('[data-testid="initiator-picker-selection"]').text(),
		).toContain('999990019')

		const use = wrapper.findAllComponents({ name: 'NcButton' })[1]
		expect(use.props('disabled')).toBe(false)
		use.vm.$emit('click')
		expect(wrapper.emitted('confirm')[0][0]).toEqual(choice)
	})

	it('falls back to the source id, and a cleared pick clears the choice', async () => {
		mockChoice.mockResolvedValue({
			initiatorSourceId: 'contact-7',
			initiatorDisplayName: 'Bob',
		})
		const wrapper = mount(InitiatorPickerModal)

		await wrapper.vm.onSelect({ source: 'contact', row: {} })
		await flushPromises()
		expect(
			wrapper.findComponent({ name: 'CnPartyPicker' }).props('modelValue'),
		).toBe('contact-7')

		await wrapper.vm.onSelect(null)
		await flushPromises()
		expect(wrapper.vm.selection).toBe(null)
		expect(mockChoice).toHaveBeenCalledTimes(1)
	})

	it('skips and closes', () => {
		const wrapper = mount(InitiatorPickerModal)

		wrapper.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('skip')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
