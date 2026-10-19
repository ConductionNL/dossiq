/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The handover dialog's New team field is a search field (board DqOverdragen):
 * the teams that match what was typed come back as rows with a group icon, and
 * the chosen row's id is the team the case moves to.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/teamSearch.js', () => ({
	searchTeams: vi.fn(async () => [{ id: 'juridische-zaken', label: 'Juridische zaken' }]),
}))
vi.mock('../../src/services/caseSummary.js', () => ({
	caseEyebrow: (act) => act,
	fetchCaseSummary: async () => null,
}))

const { default: CaseHandoverDialog, withTypedTeam } = await import('../../src/dialogs/CaseHandoverDialog.vue')

/**
 * Mount the dialog with the library stubbed.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(CaseHandoverDialog, {
		props: { caseId: 'case-1' },
		global: {
			stubs: {
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnSearchSelect: {
					name: 'CnSearchSelect',
					props: ['modelValue', 'options'],
					emits: ['update:modelValue', 'search'],
					template: '<div class="search-select" />',
				},
				NcButton: true,
				NcCheckboxRadioSwitch: true,
				NcTextArea: true,
			},
		},
	})
}

describe('CaseHandoverDialog team search', () => {
	it('offers the matching teams as rows with a group icon', async () => {
		const wrapper = mountDialog()
		const field = wrapper.findComponent({ name: 'CnSearchSelect' })

		field.vm.$emit('search', 'juri')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options')).toEqual([
			{ id: 'juridische-zaken', label: 'Juridische zaken', icon: 'AccountGroupOutline' },
			{ id: 'juri', label: 'Use "juri"', icon: 'AccountGroupOutline' },
		])
	})

	it('still takes a typed team name, also when the search finds no team', async () => {
		expect(withTypedTeam([], 'Toezicht')).toEqual([{ id: 'Toezicht', label: 'Use "Toezicht"', icon: 'AccountGroupOutline' }])
		expect(withTypedTeam([{ id: 'juridische-zaken', label: 'Juridische zaken' }], 'juridische zaken')).toHaveLength(1)

		const wrapper = mountDialog()
		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('update:modelValue', 'Toezicht')
		await flushPromises()

		expect(wrapper.vm.team).toBe('Toezicht')
	})

	it('moves the case to the team whose row was chosen', async () => {
		const wrapper = mountDialog()

		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('update:modelValue', 'juridische-zaken')
		await flushPromises()

		expect(wrapper.vm.team).toBe('juridische-zaken')
	})
})
