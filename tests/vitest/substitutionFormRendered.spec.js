// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SubstitutionFormModal as rendered: every field writes the form it shows,
 * the case type picker appears only for the case type scope, and Cancel and
 * closing the dialog leave.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/substitutionApi.js', () => ({ createSubstitution: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection: vi.fn().mockResolvedValue([{ id: 'ct-1', title: 'Omgevingsvergunning' }]) }),
}))

/**
 * A stand-in that renders its slots and can emit a new value.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function standIn(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'inputLabel', 'options'],
		emits: ['update:modelValue', 'click', 'update:open'],
		template: '<div><slot /><slot name="actions" /></div>',
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcDialog: { name: 'NcDialog', emits: ['update:open'], template: '<div><slot /><slot name="actions" /></div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcSelect: standIn('NcSelect'),
	NcTextField: standIn('NcTextField'),
}))

const { default: SubstitutionFormModal } = await import('../../src/modals/SubstitutionFormModal.vue')

/**
 * Mount the form.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function render(props = {}) {
	const w = mount(SubstitutionFormModal, { props })
	await flushPromises()
	return w
}

/**
 * The stand-in of a kind carrying a label.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} label The label or input label.
 * @return {object} The component wrapper.
 */
function byLabel(w, name, label) {
	return w.findAllComponents({ name }).find((c) => c.props('label') === label || c.props('inputLabel') === label)
}

describe('SubstitutionFormModal as rendered', () => {
	it('writes the absentee for a coordinator, and the substitute, dates and comment', async () => {
		const w = await render({ allowCoordinator: true })

		byLabel(w, 'NcTextField', 'Absent handler (user id)').vm.$emit('update:modelValue', 'anna')
		byLabel(w, 'NcTextField', 'Substitute (user id)').vm.$emit('update:modelValue', 'bob')
		await w.find('#sub-start').setValue('2026-11-01')
		await w.find('#sub-end').setValue('2026-11-30')
		await w.find('#sub-comment').setValue('Vakantie')

		expect(w.vm.form).toMatchObject({ absentee: 'anna', substitute: 'bob', startDate: '2026-11-01', endDate: '2026-11-30', comment: 'Vakantie' })
	})

	it('asks no absentee of a handler registering their own substitute', async () => {
		const w = await render()
		expect(byLabel(w, 'NcTextField', 'Absent handler (user id)')).toBeUndefined()
	})

	it('picks the reason and the scope, and offers the case types only for that scope', async () => {
		const w = await render()
		expect(byLabel(w, 'NcSelect', 'Case types')).toBeUndefined()

		const sick = w.vm.reasonOptions.find((o) => o.value !== 'verlof')
		byLabel(w, 'NcSelect', 'Reason').vm.$emit('update:modelValue', sick)
		const caseTypes = w.vm.scopeOptions.find((o) => o.value === 'caseTypes')
		byLabel(w, 'NcSelect', 'Scope').vm.$emit('update:modelValue', caseTypes)
		await flushPromises()

		expect(w.vm.selectedReason).toEqual(sick)
		const picker = byLabel(w, 'NcSelect', 'Case types')
		expect(picker).toBeDefined()
		picker.vm.$emit('update:modelValue', [{ id: 'ct-1', title: 'Omgevingsvergunning' }])
		expect(w.vm.selectedCaseTypes).toEqual([{ id: 'ct-1', title: 'Omgevingsvergunning' }])
	})

	it('leaves on Cancel and when the dialog closes', async () => {
		const w = await render()

		w.findAllComponents({ name: 'NcButton' }).find((c) => c.text() === 'Cancel').vm.$emit('click')
		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(w.emitted('close').length).toBeGreaterThanOrEqual(2)
	})

	it('shows the substitute error, the server reason and the spinner while saving', async () => {
		const w = await render()
		w.vm.errors.substitute = 'Choose a substitute'
		w.vm.serverError = 'Overlapping substitution'
		w.vm.saving = true
		await flushPromises()

		expect(w.text()).toContain('Choose a substitute')
		expect(w.text()).toContain('Overlapping substitution')
		expect(w.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
	})
})
