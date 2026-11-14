// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two form dialogs driven through their controls: the case access link
 * (what the holder may do, until when, a password, which documents) and the
 * role editor (name required, type and parent picked from lists, mandate
 * level as a number).
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CreateAccessLinkDialog from '../../src/dialogs/CreateAccessLinkDialog.vue'
import RolEditorDialog from '../../src/dialogs/RolEditorDialog.vue'

const STUBS = {
	NcDialog: { name: 'NcDialog', emits: ['update:open'], props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'error', 'helperText'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<label class="switch"><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<div class="select" />' },
}

/**
 * Mount a dialog with the library's controls stubbed.
 *
 * @param {object} component The dialog.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountDialog(component, props) {
	return mount(component, { props, global: { stubs: STUBS } })
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The dialog.
 * @param {string} text The button text.
 * @return {object} The button wrapper.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text() === text)
}

/**
 * The stubbed text field with the given id.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The field id.
 * @return {object} The input wrapper.
 */
function field(wrapper, id) {
	return wrapper.find(`input#${id}`)
}

describe('CreateAccessLinkDialog', () => {
	it('sends what was typed and ticked: label, comment off, upload on, date and password', async () => {
		const wrapper = mountDialog(CreateAccessLinkDialog, { caseId: 'case-1', open: true })

		await field(wrapper, 'access-link-label').setValue('Welstandscommissie')
		await field(wrapper, 'access-link-expiry').setValue('2026-12-31')
		await field(wrapper, 'access-link-password').setValue('s3cret')
		const switches = wrapper.findAll('.switch input')
		expect(switches[0].element.disabled).toBe(true)
		await switches[1].setValue(false)
		await switches[2].setValue(true)
		await button(wrapper, 'Create the link').trigger('click')

		expect(wrapper.emitted('created')).toEqual([[{
			caseId: 'case-1',
			shareType: 'link',
			label: 'Welstandscommissie',
			capabilities: ['read', 'upload'],
			expiresAt: '2026-12-31',
			password: 's3cret',
			sharedDocuments: [],
		}]])
	})

	it('lists the case documents only when there are some, and sends the ticked ones', async () => {
		expect(mountDialog(CreateAccessLinkDialog, { caseId: 'case-1' }).text()).not.toContain('Documents to send with it')

		const wrapper = mountDialog(CreateAccessLinkDialog, {
			caseId: 'case-1',
			documents: [{ id: 'd1', name: 'Aanvraag.pdf' }, { id: 'd2', name: 'Tekening.pdf' }],
		})
		expect(wrapper.text()).toContain('Documents to send with it')
		const docSwitches = wrapper.findAll('.switch').filter((s) => s.text().endsWith('.pdf'))
		expect(docSwitches.map((s) => s.text())).toEqual(['Aanvraag.pdf', 'Tekening.pdf'])

		await docSwitches[1].find('input').setValue(true)
		await docSwitches[0].find('input').setValue(true)
		await docSwitches[1].find('input').setValue(false)
		expect(wrapper.vm.form.documents).toEqual(['d1'])
		expect(docSwitches[0].find('input').element.checked).toBe(true)

		await wrapper.vm.createLink()
		const payload = wrapper.emitted('created')[0][0]
		expect(payload.sharedDocuments).toEqual(['d1'])
		expect(payload.capabilities).toEqual(['read', 'comment'])
		expect(payload.password).toBeNull()
	})

	it('passes the dialog\'s open state on and closes on cancel', async () => {
		const wrapper = mountDialog(CreateAccessLinkDialog, { caseId: 'case-1', open: true })

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		await button(wrapper, 'Cancel').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})

describe('RolEditorDialog', () => {
	const parents = [{ id: 'r-root', label: 'Gemeente' }, { id: 'r-vth', label: 'VTH' }]

	it('titles a new role and refuses to save it without a name', async () => {
		const wrapper = mountDialog(RolEditorDialog, { parentOptions: parents })

		expect(wrapper.find('h2').text()).toBe('New role')
		await button(wrapper, 'Save').trigger('click')

		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.vm.errors).toEqual({ name: 'Naam is required' })
		expect(wrapper.findAllComponents({ name: 'NcTextField' })[0].props('error')).toBe(true)
	})

	it('saves what was typed and picked through the controls', async () => {
		const wrapper = mountDialog(RolEditorDialog, { parentOptions: parents })
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects[0].props('modelValue')).toEqual({ id: 'employee', label: 'Employee' })
		expect(selects[1].props('modelValue')).toEqual(parents[0])

		await field(wrapper, 'rol-naam').setValue('Vergunningverlener')
		selects[0].vm.$emit('update:modelValue', { id: 'teamleider', label: 'Team leader' })
		selects[1].vm.$emit('update:modelValue', parents[1])
		await field(wrapper, 'rol-afdeling').setValue('Ruimte')
		await field(wrapper, 'rol-team').setValue('Vergunningen')
		await field(wrapper, 'rol-niveau').setValue('3')
		await button(wrapper, 'Save').trigger('click')

		expect(wrapper.emitted('save')).toEqual([[{
			name: 'Vergunningverlener',
			type: 'teamleider',
			parentRole: 'r-vth',
			department: 'Ruimte',
			team: 'Vergunningen',
			mandateLevel: 3,
		}]])
		expect(selects[1].props('modelValue')).toEqual(parents[1])
	})

	it('clears type and parent when unpicked, and a non-number level becomes 0', async () => {
		const wrapper = mountDialog(RolEditorDialog, { role: { name: 'Jurist', type: 'manager', parentRole: 'r-vth', mandateLevel: 4 }, parentOptions: parents })
		expect(wrapper.find('h2').text()).toBe('Edit role')
		expect(field(wrapper, 'rol-niveau').element.value).toBe('4')
		const selects = wrapper.findAllComponents({ name: 'NcSelect' })

		selects[0].vm.$emit('update:modelValue', null)
		selects[1].vm.$emit('update:modelValue', null)
		await field(wrapper, 'rol-niveau').setValue('abc')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.form).toMatchObject({ type: '', parentRole: '', mandateLevel: 0 })
		expect(selects[0].props('modelValue')).toEqual({ id: 'employee', label: 'Employee' })
		expect(selects[1].props('modelValue')).toEqual(parents[0])
	})

	it('closes on cancel and when the dialog closes, not when it opens', async () => {
		const wrapper = mountDialog(RolEditorDialog, {})
		const dialog = wrapper.findComponent({ name: 'NcDialog' })

		dialog.vm.$emit('update:open', true)
		dialog.vm.$emit('update:open', false)
		await button(wrapper, 'Cancel').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})
