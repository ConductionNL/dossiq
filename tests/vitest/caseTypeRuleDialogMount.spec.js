// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseTypeRuleDialog mounted and driven through its fields: which inputs each
 * event and action shows, what every select writes into the rule (and what a
 * cleared select falls back to), when the rule may be saved, and the rule it
 * emits.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => (await import('./helpers/ncFormStubs.js')).ncFormStubs())

const { default: CaseTypeRuleDialog } = await import('../../src/modals/CaseTypeRuleDialog.vue')

const CHOICES = {
	statuses: [{ id: 'st-intake', label: 'Intake' }, { id: 'st-besluit', label: 'Besluit' }],
	variants: [{ id: 'v-regulier', label: 'Regulier' }],
	templates: [{ id: 'tpl-ontvangst', label: 'Ontvangstbevestiging' }],
	documentTypes: [{ id: 'dt-tekening', label: 'Bouwtekening' }],
	roles: [{ id: 'gemachtigde', label: 'Gemachtigde' }],
}
const FIELDS = [
	{ id: 'status', label: 'Status', type: 'enum' },
	{ id: 'bouwkosten', label: 'Bouwkosten', type: 'number' },
	{ id: 'document:dt-tekening', label: 'Bouwtekening', type: 'presence' },
]

/**
 * The component of one kind carrying the given test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The component name.
 * @param {string} testid The data-testid.
 * @return {object|undefined} The component wrapper.
 */
function byTestId(wrapper, name, testid) {
	return wrapper.findAllComponents({ name }).find((c) => c.attributes('data-testid') === testid)
}

/**
 * Pick an option (or clear) on a select by its test id, and re-render.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} testid The data-testid.
 * @param {object|null} option The option.
 * @return {Promise<void>}
 */
async function pick(wrapper, testid, option) {
	byTestId(wrapper, 'NcSelect', testid).vm.$emit('update:modelValue', option)
	await flushPromises()
}

/**
 * Whether the save button is enabled.
 *
 * @param {object} wrapper The wrapper.
 * @return {boolean} Enabled.
 */
function canSave(wrapper) {
	return wrapper.find('[data-testid="rule-save"]').attributes('disabled') === undefined
}

describe('CaseTypeRuleDialog mounted', () => {
	it('adds a task rule on entering a status, with conditions, and only once a status and title are given', async () => {
		const wrapper = mount(CaseTypeRuleDialog, { props: { newId: 'rule-7', fields: FIELDS, choices: CHOICES } })
		expect(wrapper.find('.nc-frame__name').text()).toBe('Add rule')
		expect(byTestId(wrapper, 'NcSelect', 'rule-status')).toBeUndefined()
		expect(canSave(wrapper)).toBe(false)

		byTestId(wrapper, 'NcTextField', 'rule-name').vm.$emit('update:modelValue', '  Welstand bij besluit ')
		await pick(wrapper, 'rule-when', { id: 'statusEntered' })
		expect(byTestId(wrapper, 'NcSelect', 'rule-status').findAll('li').map((li) => li.text())).toEqual(['Intake', 'Besluit'])

		byTestId(wrapper, 'NcTextField', 'rule-task-title').vm.$emit('update:modelValue', ' Vraag welstandsadvies ')
		await flushPromises()
		expect(canSave(wrapper)).toBe(false)

		await pick(wrapper, 'rule-status', CHOICES.statuses[1])
		expect(byTestId(wrapper, 'NcSelect', 'rule-status').props('modelValue')).toEqual(CHOICES.statuses[1])
		expect(canSave(wrapper)).toBe(true)

		wrapper.findComponent({ name: 'CnConditionListEditor' }).vm.$emit('update:modelValue', [
			{ field: 'document:dt-tekening', operator: 'present', value: '' },
			{ field: '', operator: 'eq', value: '' },
		])
		await flushPromises()
		await wrapper.find('[data-testid="rule-save"]').trigger('click')

		expect(wrapper.emitted('save')).toEqual([[{
			id: 'rule-7',
			enabled: true,
			when: { event: 'statusEntered', status: 'st-besluit' },
			conditions: [{ kind: 'documentPresent', documentType: 'dt-tekening' }],
			actions: [{ action: 'addTask', title: 'Vraag welstandsadvies' }],
			name: 'Welstand bij besluit',
		}]])
	})

	it('offers the target of each action, and sends a message to a chosen role', async () => {
		const wrapper = mount(CaseTypeRuleDialog, { props: { fields: FIELDS, choices: CHOICES } })

		await pick(wrapper, 'rule-action', { id: 'chooseVariant' })
		expect(byTestId(wrapper, 'NcTextField', 'rule-task-title')).toBeUndefined()
		expect(byTestId(wrapper, 'NcSelect', 'rule-target').findAll('li').map((li) => li.text())).toEqual(['Regulier'])

		await pick(wrapper, 'rule-action', { id: 'requireDocument' })
		expect(byTestId(wrapper, 'NcSelect', 'rule-target').findAll('li').map((li) => li.text())).toEqual(['Bouwtekening'])

		await pick(wrapper, 'rule-action', { id: 'sendMessage' })
		expect(byTestId(wrapper, 'NcSelect', 'rule-recipient-role').findAll('li').map((li) => li.text())).toEqual(['The applicant', 'Gemachtigde'])
		expect(canSave(wrapper)).toBe(false)
		await pick(wrapper, 'rule-target', CHOICES.templates[0])
		await pick(wrapper, 'rule-recipient-role', CHOICES.roles[0])
		expect(byTestId(wrapper, 'NcSelect', 'rule-recipient-role').props('modelValue')).toEqual(CHOICES.roles[0])
		expect(canSave(wrapper)).toBe(true)

		await wrapper.find('[data-testid="rule-save"]').trigger('click')
		expect(wrapper.emitted('save')[0][0]).toEqual({
			id: 'rule-1',
			enabled: true,
			when: { event: 'saved' },
			conditions: [],
			actions: [{ action: 'sendMessage', emailTemplate: 'tpl-ontvangst', recipientRole: 'gemachtigde' }],
		})
	})

	it('sets a field to a value, offering only the case type\'s own valued fields', async () => {
		const wrapper = mount(CaseTypeRuleDialog, {
			props: { rule: { id: 'r-3', enabled: false, when: { event: 'created' }, actions: [{ action: 'setField', field: 'bouwkosten', value: 1000 }] }, fields: FIELDS, choices: CHOICES },
		})
		expect(wrapper.find('.nc-frame__name').text()).toBe('Edit rule')
		expect(byTestId(wrapper, 'NcSelect', 'rule-target').findAll('li').map((li) => li.text())).toEqual(['Bouwkosten'])
		expect(byTestId(wrapper, 'NcSelect', 'rule-target').props('modelValue')).toEqual({ id: 'bouwkosten', label: 'Bouwkosten' })

		const value = wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('modelValue') === 1000)
		value.vm.$emit('update:modelValue', '25000')
		await flushPromises()
		await wrapper.find('[data-testid="rule-save"]').trigger('click')

		expect(wrapper.emitted('save')[0][0]).toEqual({
			id: 'r-3',
			enabled: false,
			when: { event: 'created' },
			conditions: [],
			actions: [{ action: 'setField', field: 'bouwkosten', value: '25000' }],
		})
	})

	it('falls back when a select is cleared, and has no statuses without choices', async () => {
		const wrapper = mount(CaseTypeRuleDialog)

		await pick(wrapper, 'rule-when', { id: 'statusEntered' })
		expect(byTestId(wrapper, 'NcSelect', 'rule-status').findAll('li')).toHaveLength(0)
		await pick(wrapper, 'rule-status', null)
		expect(wrapper.vm.form.status).toBe('')
		await pick(wrapper, 'rule-when', null)
		expect(wrapper.vm.form.event).toBe('saved')

		await pick(wrapper, 'rule-action', { id: 'sendMessage' })
		await pick(wrapper, 'rule-target', null)
		await pick(wrapper, 'rule-recipient-role', null)
		expect([wrapper.vm.form.target, wrapper.vm.form.recipientRole]).toEqual(['', ''])
		expect(byTestId(wrapper, 'NcSelect', 'rule-recipient-role').props('modelValue').label).toBe('The applicant')
		await pick(wrapper, 'rule-action', null)
		expect(wrapper.vm.form.action).toBe('addTask')
	})

	it('closes from cancel and when the dialog is closed, not when it opens', async () => {
		const wrapper = mount(CaseTypeRuleDialog)

		await wrapper.find('.nc-button--tertiary').trigger('click')
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		dialog.vm.$emit('update:open', true)
		dialog.vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
