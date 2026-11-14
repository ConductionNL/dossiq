// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ZgwMappingDialog driven through its template: the dialog title for the
 * resource, toggling the mapping, editing register and schema, editing the
 * four JSON mapping textareas, the rendered JSON error, Save, Cancel and
 * the dialog's own close.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'size'],
		emits: ['closing'],
		template: '<div class="dlg"><h2 class="dlg-name">{{ name }}</h2><slot /><slot name="actions" /><button class="dlg-x" @click="$emit(\'closing\')" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type'],
		emits: ['click'],
		template: '<button :class="\'btn-\' + type" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}))

const { default: ZgwMappingDialog } = await import('../../src/dialogs/ZgwMappingDialog.vue')

const build = (props = {}) => mount(ZgwMappingDialog, { props: { open: true, resourceKey: 'zaken', ...props } })

describe('ZgwMappingDialog through its template', () => {
	it('renders nothing while closed and titles the dialog with the resource', () => {
		expect(mount(ZgwMappingDialog, { props: { open: false } }).find('.dlg').exists()).toBe(false)
		const w = build({ mapping: { sourceRegister: 'dossiq', propertyMapping: { title: 'omschrijving' } } })
		expect(w.find('.dlg-name').text()).toBe('Edit ZGW Mapping: zaken')
		expect(w.find('.switch').text()).toBe('Enable this mapping')
		expect(w.find('input[aria-label="Register ID"]').element.value).toBe('dossiq')
		expect(JSON.parse(w.find('#zgw-mapping-property').element.value)).toEqual({ title: 'omschrijving' })
	})

	it('saves what was edited in every field', async () => {
		const w = build()
		await w.find('.switch input').setValue(false)
		await w.find('input[aria-label="Register ID"]').setValue('reg-7')
		await w.find('input[aria-label="Schema ID"]').setValue('schema-9')
		await w.find('#zgw-mapping-property').setValue('{"title":"omschrijving"}')
		await w.find('#zgw-mapping-reverse').setValue('{"omschrijving":"title"}')
		await w.find('#zgw-mapping-value').setValue('{"status":{"open":"open"}}')
		await w.find('#zgw-mapping-query-param').setValue('{"q":"search"}')
		await w.find('.btn-primary').trigger('click')
		expect(w.emitted('save')).toEqual([[{
			enabled: false,
			sourceRegister: 'reg-7',
			sourceSchema: 'schema-9',
			zgwResource: 'zaken',
			zgwApiVersion: '1',
			propertyMapping: { title: 'omschrijving' },
			reverseMapping: { omschrijving: 'title' },
			valueMapping: { status: { open: 'open' } },
			queryParameterMapping: { q: 'search' },
		}]])
	})

	it('shows the JSON error under the fields and clears it on a valid save', async () => {
		const w = build()
		expect(w.find('.error-message').exists()).toBe(false)
		await w.find('#zgw-mapping-value').setValue('{ nope')
		await w.find('.btn-primary').trigger('click')
		expect(w.find('.error-message').text()).toMatch(/^Invalid JSON in one of the mapping fields: \S/)
		expect(w.emitted('save')).toBeUndefined()
		await w.find('#zgw-mapping-value').setValue('{}')
		await w.find('.btn-primary').trigger('click')
		expect(w.find('.error-message').exists()).toBe(false)
		expect(w.emitted('save')).toHaveLength(1)
	})

	it('closes from Cancel and from the dialog without saving', async () => {
		const w = build()
		await w.find('.btn-tertiary').trigger('click')
		await w.find('.dlg-x').trigger('click')
		expect(w.emitted('close')).toEqual([[], []])
		expect(w.emitted('save')).toBeUndefined()
	})
})
