// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The triage rule dialog: a name, an effect and at least one condition before
 * anything is sent, and the change of an existing rule goes to that rule.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-rules-mark-documents-without-opening-them-visibly-req-wrt-002
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

const { default: TriageRuleDialog } =
	await import('../../src/dialogs/TriageRuleDialog.vue')

/**
 * Mount the dialog on case X.
 *
 * @param {object|null} rule The rule to change, or null.
 * @return {object} The wrapper.
 */
function mountDialog(rule = null) {
	return mount(TriageRuleDialog, {
		props: { caseId: 'case-x', rule },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcTextField: {
					props: ['modelValue', 'label'],
					emits: ['update:modelValue'],
					template:
						'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
				NcCheckboxRadioSwitch: {
					props: ['modelValue', 'value'],
					emits: ['update:modelValue'],
					template:
						'<input type="radio" v-bind="$attrs" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)">',
				},
			},
		},
	})
}

describe('TriageRuleDialog', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.put.mockReset()
	})

	it('testAnEffectIsRequired', async () => {
		const wrapper = mountDialog()
		await wrapper
			.find('[data-testid="triage-rule-name"]')
			.setValue('Nieuwsbrieven')
		await wrapper
			.find('[data-testid="triage-rule-senderDomain"]')
			.setValue('nieuwsbrief.example.nl')

		expect(wrapper.find('[data-testid="triage-rule-missing"]').text()).toBe(
			'Choose whether the rule marks documents in scope or out of scope.',
		)
		expect(
			wrapper.find('[data-testid="triage-rule-save"]').attributes('disabled'),
		).toBeDefined()
		await wrapper.find('[data-testid="triage-rule-save"]').trigger('click')
		expect(axios.post).not.toHaveBeenCalled()

		await wrapper
			.find('[data-testid="triage-rule-effect-out"]')
			.trigger('change')
		expect(wrapper.find('[data-testid="triage-rule-missing"]').exists()).toBe(
			false,
		)
		axios.post.mockResolvedValue({ data: { id: 'r-1' } })
		await wrapper.find('[data-testid="triage-rule-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/triage/rules',
			{
				name: 'Nieuwsbrieven',
				effect: 'out-of-scope',
				conditions: { senderDomain: 'nieuwsbrief.example.nl' },
			},
		)
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'r-1' }]])
	})

	it('asks for a condition, and sends the change of a rule to that rule', async () => {
		const wrapper = mountDialog({
			id: 'r-9',
			name: 'Buiten de periode',
			effect: 'out-of-scope',
			conditions: { dateTo: '2024-12-31' },
		})
		await wrapper.find('[data-testid="triage-rule-dateTo"]').setValue('')
		expect(wrapper.find('[data-testid="triage-rule-missing"]').text()).toBe(
			'Give the rule at least one condition, or it would mark every document.',
		)

		await wrapper
			.find('[data-testid="triage-rule-dateFrom"]')
			.setValue('2026-10-01')
		axios.put.mockRejectedValue({
			response: {
				data: { message: 'Another rule of this case has that name.' },
			},
		})
		await wrapper.find('[data-testid="triage-rule-save"]').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			'/index.php/apps/dossiq/api/cases/case-x/review/triage/rules/r-9',
			{
				name: 'Buiten de periode',
				effect: 'out-of-scope',
				conditions: { dateFrom: '2026-10-01' },
			},
		)
		expect(wrapper.find('[data-testid="triage-rule-error"]').text()).toBe(
			'Another rule of this case has that name.',
		)
	})
})
