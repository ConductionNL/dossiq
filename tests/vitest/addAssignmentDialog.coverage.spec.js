// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AddAssignmentDialog driven through its template: the dialog name, typing a
 * person, picking a role and a type, entering the dates, the Cancel and Add
 * buttons, closing the dialog, and the field errors it renders when Add is
 * pressed with the form incomplete.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template: '<div class="dlg"><h2 class="dlg-name">{{ name }}</h2><slot /><div class="dlg-actions"><slot name="actions" /></div><button class="dlg-x" @click="$emit(\'update:open\', false)" /><button class="dlg-keep" @click="$emit(\'update:open\', true)" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type'],
		emits: ['click'],
		template: '<button :class="\'btn-\' + (type || \'default\')" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'error', 'helperText'],
		emits: ['update:modelValue'],
		template: '<div><input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /><p v-if="error" class="helper">{{ helperText }}</p></div>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<div class="sel" :data-label="inputLabel"><button v-for="o in options" :key="o.id" class="opt" @click="$emit(\'update:modelValue\', o)">{{ o.label }}</button><button class="clear" @click="$emit(\'update:modelValue\', null)" /><span class="current">{{ modelValue ? modelValue.label : \'\' }}</span></div>',
	},
}))

const { default: AddAssignmentDialog } = await import('../../src/dialogs/AddAssignmentDialog.vue')

const roleOptions = [
	{ id: 'r1', label: 'Lead' },
	{ id: 'r2', label: 'Reviewer' },
]

const build = () => mount(AddAssignmentDialog, { props: { roleOptions } })
const roleSelect = (w) => w.findAll('.sel').find((s) => s.attributes('data-label') === 'Role')
const typeSelect = (w) => w.findAll('.sel').find((s) => s.attributes('data-label') === 'Type')

afterEach(() => {
	vi.useRealTimers()
})

describe('AddAssignmentDialog through its template', () => {
	it('names the dialog and shows the regular type selected', () => {
		const w = build()
		expect(w.find('.dlg-name').text()).toBe('Add role assignment')
		expect(typeSelect(w).find('.current').text()).toBe('Regular assignment')
		expect(roleSelect(w).find('.current').text()).toBe('')
	})

	it('renders both field errors when Add is pressed on an empty form', async () => {
		const w = build()
		await w.find('.btn-primary').trigger('click')
		expect(w.find('.helper').text()).toBe('Person is required')
		expect(w.find('.field-error').text()).toBe('Role is required')
		expect(w.emitted('save')).toBeUndefined()
	})

	it('emits the assignment typed and picked into the form', async () => {
		const w = build()
		await w.find('#aa-person input').setValue('ann@example.org')
		await roleSelect(w).findAll('.opt')[1].trigger('click')
		await typeSelect(w).findAll('.opt')[2].trigger('click')
		await w.find('#aa-vanaf').setValue('2026-07-01')
		await w.find('#aa-tot').setValue('2026-12-31')
		expect(roleSelect(w).find('.current').text()).toBe('Reviewer')
		expect(typeSelect(w).find('.current').text()).toBe('Deputy')
		await w.find('.btn-primary').trigger('click')
		expect(w.emitted('save')).toEqual([[{
			persoonId: 'ann@example.org',
			roleId: 'r2',
			allocationType: 'plaatsvervanger',
			vanaf: '2026-07-01',
			totEnMet: '2026-12-31',
		}]])
		expect(w.find('.field-error').exists()).toBe(false)
	})

	it('clears role and type when the selection is removed', async () => {
		const w = build()
		await roleSelect(w).findAll('.opt')[0].trigger('click')
		await typeSelect(w).findAll('.opt')[1].trigger('click')
		await roleSelect(w).find('.clear').trigger('click')
		await typeSelect(w).find('.clear').trigger('click')
		expect(w.vm.form.roleId).toBe('')
		expect(w.vm.form.allocationType).toBe('')
		expect(typeSelect(w).find('.current').text()).toBe('Regular assignment')
		await w.find('#aa-person input').setValue('ann')
		await w.find('.btn-primary').trigger('click')
		expect(w.find('.field-error').text()).toBe('Role is required')
		expect(w.emitted('save')).toBeUndefined()
	})

	it('offers no role to pick when none are given, so Add keeps refusing', async () => {
		const w = mount(AddAssignmentDialog)
		expect(roleSelect(w).findAll('.opt')).toHaveLength(0)
		await w.find('#aa-person input').setValue('ann')
		await w.find('.btn-primary').trigger('click')
		expect(w.find('.field-error').text()).toBe('Role is required')
		expect(w.emitted('save')).toBeUndefined()
	})

	it('closes from Cancel and from the dialog, but not when it stays open', async () => {
		const w = build()
		await w.find('.btn-default').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
		await w.find('.dlg-keep').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
		await w.find('.dlg-x').trigger('click')
		expect(w.emitted('close')).toHaveLength(2)
		expect(w.emitted('save')).toBeUndefined()
	})
})
