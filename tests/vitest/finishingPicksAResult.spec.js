// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finish and Abort pick their result from the case type.
 *
 * Volgende stap > Afronden asked for "Resultaattype" as free text; a typed
 * name reached the server as the result's id and failed. `/acts` now carries
 * the case type's results, and the dialog offers them in a picker and offers
 * no free field.
 *
 * @spec openspec/changes/finishing-picks-a-result/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (
	await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')
).default

const DIALOG_RENDERS_ITS_SLOT = {
	CnChoiceCards: {
		props: ['options', 'modelValue'],
		template:
			'<div><div v-for="option in options" :key="option.value"><slot v-if="option.value === modelValue" name="option-details" :option="option" /></div></div>',
	},
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	CaseActFields: false,
	NcButton: {
		props: ['disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
}

const RESULTS = [
	{ id: 'rt-full', name: 'Toegewezen' },
	{ id: 'rt-part', name: 'Deels toegewezen' },
]

const VERDICTS = [
	{ act: 'finish', allowed: true },
	{ act: 'abort', allowed: true },
	{ act: 'archive', allowed: false, reason: 'Not ended.' },
]

/**
 * The menu, loaded, with one act's form open.
 *
 * @param {string} id The act to open.
 * @param {Array<object>} resultTypes What `/acts` answers as results.
 * @return {Promise<object>} The mounted wrapper.
 */
async function formFor(id, resultTypes) {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/acts')) {
			return Promise.resolve({ data: { acts: VERDICTS, resultTypes } })
		}
		return Promise.resolve({
			data: url.endsWith('/lifecycle') ? {} : { transitions: [] },
		})
	})
	const wrapper = shallowMount(CaseLifecycleMenuDialog, {
		props: { caseId: 'case-1' },
		global: { stubs: DIALOG_RENDERS_ITS_SLOT },
	})
	await flushPromises()
	wrapper.vm.choose(wrapper.vm.entries.find((row) => row.id === id))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('the ending acts carry the case type results', () => {
	it('copies the results onto finish and abort, and not onto archive', () => {
		const menu = buildActsMenu({
			acts: { acts: VERDICTS, resultTypes: RESULTS },
		})
		const byId = Object.fromEntries(menu.map((entry) => [entry.id, entry]))

		expect(byId.finish.resultTypes).toEqual(RESULTS)
		expect(byId.abort.resultTypes).toEqual(RESULTS)
		expect(byId.archive.resultTypes).toEqual([])
	})
})

describe('Afronden in the lifecycle dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('draws every act as a card, a refused one disabled with its reason, and confirms under the act\'s name (DqVolgendeStap)', async () => {
		const wrapper = await formFor('finish', RESULTS)
		const archive = wrapper.vm.choiceOptions.find((option) => option.value.endsWith(':archive'))

		expect(archive).toMatchObject({ disabled: true, disabledReason: 'Not ended.', description: '', icon: 'ArchiveOutline' })
		expect(wrapper.vm.chosenKey.endsWith(':finish')).toBe(true)
		expect(wrapper.find('[data-testid="case-acts-confirm"]').text()).toBe('Finish')

		wrapper.vm.chooseByKey(archive.value)
		expect(wrapper.vm.chosenKey.endsWith(':finish'), 'a refused act cannot be chosen').toBe(true)
	})

	it('offers the results in a picker and no free field', async () => {
		const wrapper = await formFor('finish', RESULTS)

		const picker = wrapper.findComponent({ name: 'NcSelect' })
		expect(picker.exists()).toBe(true)
		expect(picker.props('options')).toEqual(RESULTS)
		expect(wrapper.find('[data-testid="case-act-result-input"]').exists()).toBe(
			false,
		)
	})

	it('sends the picked id, not a name', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await formFor('finish', RESULTS)
		wrapper.vm.reason = 'Besluit verzonden.'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(false)

		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', RESULTS[1])
		await wrapper.vm.$nextTick()
		await wrapper.vm.confirm()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/case/case-1/finish'),
			expect.objectContaining({ resultTypeId: 'rt-part' }),
		)
	})

	it('control: a case type without results shows neither picker nor free field', async () => {
		const wrapper = await formFor('finish', [])

		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
		expect(wrapper.find('[data-testid="case-act-result-input"]').exists()).toBe(
			false,
		)
		wrapper.vm.reason = 'Klaar.'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(true)
	})
})
