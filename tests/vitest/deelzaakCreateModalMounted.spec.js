// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DeelzaakCreateModal, mounted: it reads the parent and the case types on
 * mount, shows the parent's own refusal or the empty state instead of the
 * form, offers only the allowed sub-case types, and wires the form's inputs,
 * close paths and create button.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
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
		props: [
			'name',
			'open',
			'size',
			'description',
			'modelValue',
			'options',
			'disabled',
			'variant',
			'error',
			'placeholder',
			'label',
			'trackBy',
			'inputLabel',
			'ariaLabelCombobox',
		],
		emits: ['update:open', 'closing', 'update:modelValue', 'click'],
		render() {
			return h('div', { class: name, 'data-testid': this.$attrs['data-testid'] }, [
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
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: control('AlertCircleOutline') }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'behandelaar' }) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/logger.js', () => ({ default: { error: vi.fn() } }))
const createSubCase = vi.fn()
vi.mock('../../src/services/deelzaakApi.js', () => ({ createSubCase: (args) => createSubCase(args) }))

const objectStore = { fetchObject: vi.fn(), fetchCollection: vi.fn() }
const deelzaakStore = { validateSubCase: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => deelzaakStore }))

const { default: DeelzaakCreateModal } = await import('../../src/modals/DeelzaakCreateModal.vue')

const PARENT_TYPE = { id: 'ct-parent', title: 'Omgevingsvergunning', subCaseTypes: ['ct-advies', 'slug-zienswijze'] }
const TYPES = [
	{ id: 'ct-advies', title: 'Advies' },
	{ id: 'ct-x', slug: 'slug-zienswijze', title: 'Zienswijze' },
	{ id: 'ct-other', title: 'Niet toegestaan' },
]

/**
 * Answer the store's reads for a parent case and its type.
 *
 * @param {object} parent The parent case.
 * @param {object} parentType The parent's case type.
 * @return {void}
 */
function stores(parent, parentType = PARENT_TYPE) {
	objectStore.fetchObject.mockImplementation((schema, id) => Promise.resolve(schema === 'case' ? parent : (id === parentType.id ? parentType : null)))
	objectStore.fetchCollection.mockResolvedValue(TYPES)
}

/**
 * Mount the modal and let its reads settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(props = {}) {
	const wrapper = mount(DeelzaakCreateModal, { props: { parentCase: 'parent-1', ...props } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	stores({ id: 'parent-1', caseType: 'ct-parent' })
	deelzaakStore.validateSubCase.mockResolvedValue({ ok: true })
	createSubCase.mockResolvedValue({ ok: true, object: { id: 'sub-1' } })
})

describe('DeelzaakCreateModal mounted', () => {
	it('reads the parent, its type and the case types, and offers only the allowed ones', async () => {
		const wrapper = await mounted()

		expect(objectStore.fetchObject).toHaveBeenCalledWith('case', 'parent-1')
		expect(objectStore.fetchObject).toHaveBeenCalledWith('caseType', 'ct-parent')
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('caseType', { _limit: 200 })
		expect(wrapper.findComponent({ name: 'NcDialog' }).props('name')).toBe('Create sub-case')
		expect(wrapper.find('.parent-context-value').text()).toBe('Omgevingsvergunning')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options').map((ct) => ct.id)).toEqual(['ct-advies', 'ct-x'])
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})

	it('uses a given parent case type without reading it', async () => {
		const wrapper = await mounted({ parentCaseType: { id: 'ct-given', name: 'Gegeven', subCaseTypes: ['ct-other'] } })

		expect(objectStore.fetchObject).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.parent-context-value').text()).toBe('Gegeven')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options').map((ct) => ct.id)).toEqual(['ct-other'])
	})

	it('refuses a sub-case under a sub-case, and under a closed case, without a create button', async () => {
		stores({ id: 'parent-1', caseType: 'ct-parent', parentCase: 'grandparent' })
		let wrapper = await mounted()
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('description')).toBe('Sub-cases cannot themselves have sub-cases.')
		expect(wrapper.findAllComponents({ name: 'NcButton' })).toHaveLength(1)

		stores({ id: 'parent-1', caseType: 'ct-parent', endDate: '2026-01-01' })
		wrapper = await mounted()
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('description')).toBe('This case is closed; sub-cases can no longer be added.')
	})

	it('shows the empty state when the parent type allows no sub-case types', async () => {
		stores({ id: 'parent-1', caseType: 'ct-parent' }, { id: 'ct-parent', title: 'Melding', subCaseTypes: [] })
		const wrapper = await mounted()

		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No allowed sub-case types')
		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
	})

	it('treats failing reads as no parent and no case types', async () => {
		objectStore.fetchObject.mockRejectedValue(new Error('down'))
		objectStore.fetchCollection.mockRejectedValue(new Error('down'))
		const wrapper = await mounted({ parentCaseType: PARENT_TYPE })

		expect(wrapper.vm.caseTypes).toEqual([])
		expect(wrapper.vm.loadingParent).toBe(false)
		expect(wrapper.vm.loadingTypes).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No allowed sub-case types')
	})

	it('keeps a non-array case type answer out of the list', async () => {
		objectStore.fetchCollection.mockResolvedValue({ results: TYPES })
		const wrapper = await mounted()

		expect(wrapper.vm.caseTypes).toEqual([])
	})

	it('validates the form, clears an error on input, and creates the sub-case', async () => {
		const wrapper = await mounted()
		const buttons = () => wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons()[1].props('disabled')).toBe(true)

		wrapper.vm.validate()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.errors).toEqual({ title: 'Title is required', caseType: 'Sub-case type is required' })
		expect(wrapper.findAll('.form-error').map((p) => p.text())).toEqual(['Sub-case type is required', 'Title is required'])

		const select = wrapper.findComponent({ name: 'NcSelect' })
		select.vm.$emit('update:modelValue', TYPES[0])
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Advies brandweer')
		await wrapper.find('#dc-description').setValue('Graag snel')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.form).toMatchObject({ title: 'Advies brandweer', description: 'Graag snel', caseType: 'ct-advies' })
		expect(wrapper.vm.errors).toEqual({ title: '', caseType: '' })
		expect(buttons()[1].props('disabled')).toBe(false)

		buttons()[1].vm.$emit('click')
		await flushPromises()

		expect(createSubCase).toHaveBeenCalledWith(expect.objectContaining({ parentCaseUuid: 'parent-1', childCaseTypeId: 'ct-advies' }))
		expect(wrapper.emitted('created')).toEqual([['sub-1']])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows the server refusal under the form', async () => {
		deelzaakStore.validateSubCase.mockResolvedValue({ ok: false, reason: 'Maximum reached' })
		const wrapper = await mounted()
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', TYPES[0])
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'X')
		await wrapper.vm.$nextTick()

		wrapper.findAllComponents({ name: 'NcButton' })[1].vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.form-error--server').text()).toBe('Maximum reached')
		expect(createSubCase).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('closes on cancel, on the dialog closing, and when the dialog is set closed', async () => {
		const wrapper = await mounted()
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		dialog.vm.$emit('update:open', true)
		expect(wrapper.emitted('close')).toBeUndefined()

		dialog.vm.$emit('update:open', false)
		dialog.vm.$emit('closing')
		wrapper.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(3)
	})
})
