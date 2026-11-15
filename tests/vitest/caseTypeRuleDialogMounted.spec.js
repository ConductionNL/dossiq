// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseTypeRuleDialog, mounted: each control writes the rule field it labels,
 * the status, target, recipient and value controls appear for the event and
 * action that need them, and Save emits the rule the controls describe.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A rendering stand-in for a control that keeps its props, test id and slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function control(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'open', 'size', 'modelValue', 'options', 'label', 'clearable', 'inputLabel', 'fields', 'disabled', 'variant'],
		emits: ['update:open', 'update:modelValue', 'click'],
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
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnConditionListEditor: control('CnConditionListEditor') }))

const { default: CaseTypeRuleDialog } = await import('../../src/modals/CaseTypeRuleDialog.vue')

const CHOICES = {
	statuses: [{ id: 'st-besluit', label: 'Besluitvorming' }],
	templates: [{ id: 'tpl-1', label: 'Ontvangstbevestiging' }],
	roles: [{ id: 'adviseur', label: 'Adviseur' }],
}
const FIELDS = [
	{ id: 'amount', label: 'Bedrag', type: 'number' },
	{ id: 'status', label: 'Status', type: 'string' },
	{ id: 'attachment', label: 'Bijlage', type: 'presence' },
]

/**
 * Emit a model update from the control with the given test id.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} testId  The control's data-testid.
 * @param {*}      value   The new value.
 * @return {Promise<void>}
 */
async function input(wrapper, testId, value) {
	const target = [...wrapper.findAllComponents({ name: 'NcSelect' }), ...wrapper.findAllComponents({ name: 'NcTextField' })]
		.find((c) => c.attributes('data-testid') === testId)
	target.vm.$emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

describe('CaseTypeRuleDialog mounted', () => {
	it('builds a status-entered message rule from its controls and saves it', async () => {
		const w = mount(CaseTypeRuleDialog, { props: { choices: CHOICES, fields: FIELDS, newId: 'rule-9' } })
		expect(w.findComponent({ name: 'NcDialog' }).props('name')).toBe('Add rule')
		expect(w.find('[data-testid="rule-status"]').exists()).toBe(false)

		await input(w, 'rule-name', ' Bevestigen ')
		await input(w, 'rule-when', { id: 'statusEntered' })
		expect(w.find('[data-testid="rule-save"]').exists()).toBe(true)
		await input(w, 'rule-status', CHOICES.statuses[0])
		await input(w, 'rule-action', { id: 'sendMessage' })
		expect(w.findAllComponents({ name: 'NcSelect' }).find((c) => c.attributes('data-testid') === 'rule-target').props('inputLabel')).toBe('Message')
		await input(w, 'rule-target', CHOICES.templates[0])
		await input(w, 'rule-recipient-role', CHOICES.roles[0])

		w.findAllComponents({ name: 'NcButton' })[1].vm.$emit('click')

		expect(w.emitted('save')[0][0]).toEqual({
			id: 'rule-9',
			enabled: true,
			name: 'Bevestigen',
			when: { event: 'statusEntered', status: 'st-besluit' },
			conditions: [],
			actions: [{ action: 'sendMessage', emailTemplate: 'tpl-1', recipientRole: 'adviseur' }],
		})
	})

	it('offers only settable fields for a set-field rule and sends the typed value', async () => {
		const w = mount(CaseTypeRuleDialog, { props: { choices: CHOICES, fields: FIELDS } })

		await input(w, 'rule-action', { id: 'setField' })
		const target = w.findAllComponents({ name: 'NcSelect' }).find((c) => c.attributes('data-testid') === 'rule-target')
		expect(target.props('options')).toEqual([{ id: 'amount', label: 'Bedrag' }])
		await input(w, 'rule-target', { id: 'amount' })
		const value = w.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === 'Value')
		value.vm.$emit('update:modelValue', '500')
		await w.vm.$nextTick()

		w.findAllComponents({ name: 'NcButton' })[1].vm.$emit('click')
		expect(w.emitted('save')[0][0].actions).toEqual([{ action: 'setField', field: 'amount', value: '500' }])
	})

	it('falls back to defaults when a select is cleared', async () => {
		const w = mount(CaseTypeRuleDialog, { props: { choices: CHOICES, fields: FIELDS } })

		await input(w, 'rule-when', { id: 'statusEntered' })
		await input(w, 'rule-status', null)
		await input(w, 'rule-when', null)
		await input(w, 'rule-action', { id: 'sendMessage' })
		await input(w, 'rule-target', null)
		await input(w, 'rule-recipient-role', null)
		expect([w.vm.form.event, w.vm.form.status, w.vm.form.target, w.vm.form.recipientRole]).toEqual(['saved', '', '', ''])

		await input(w, 'rule-action', null)
		expect(w.vm.form.action).toBe('addTask')
		await input(w, 'rule-task-title', 'Advies vragen')
		expect(w.vm.form.title).toBe('Advies vragen')
	})

	it('keeps Save off until the rule is complete, and closes on Cancel or when set closed', async () => {
		const w = mount(CaseTypeRuleDialog, { props: { rule: { id: 'r1', when: { event: 'saved' }, actions: [{ action: 'addTask', title: '' }] } } })
		const save = () => w.findAllComponents({ name: 'NcButton' })[1]
		expect(w.findComponent({ name: 'NcDialog' }).props('name')).toBe('Edit rule')
		expect(save().props('disabled')).toBe(true)

		await input(w, 'rule-task-title', 'Controleren')
		expect(save().props('disabled')).toBe(false)

		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		expect(w.emitted('close')).toBeUndefined()
		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		w.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		expect(w.emitted('close')).toHaveLength(2)
	})
})
