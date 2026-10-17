// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A move into a closing status asks for its result before it is posted.
 *
 * The lifecycle menu offered "Afgehandeld" with only a reason field, and the
 * engine refused the post with "Pick a result before closing this case." The
 * engine now says on the offered move whether it closes the case and which
 * results it may close with, and the form asks for one of those before it
 * lets the handler confirm. The confirm button is busy while the post runs.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildActsMenu, inputsFor } from '../../src/utils/caseActsMenu.js'

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
			'<div><div v-for="option in options" :key="option.value"><slot name="option-actions" :option="option" :selected="option.value === modelValue" /></div></div>',
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
	{ id: 'rt-granted', name: 'Verleend' },
	{ id: 'rt-refused', name: 'Geweigerd' },
]

const CLOSE = {
	id: 'tr-close',
	label: 'Afgehandeld',
	toStatus: 'st-done',
	guardsPassed: true,
	closesCase: true,
	resultTypes: RESULTS,
}

const START = { id: 'tr-start', label: 'In behandeling', toStatus: 'st-work' }

/**
 * The three server answers the menu reads.
 *
 * @param {string} url The requested URL.
 * @return {Promise<object>} The answer.
 */
function answers(url) {
	if (url.endsWith('/available-transitions')) {
		return Promise.resolve({ data: { transitions: [START, CLOSE] } })
	}
	if (url.endsWith('/lifecycle')) {
		return Promise.resolve({ data: { caseType: 'ct-1' } })
	}
	return Promise.resolve({ data: { held: false, draft: false, acts: [] } })
}

/**
 * The menu, loaded, with one transition's form open.
 *
 * @param {string} id The transition to open.
 * @return {Promise<object>} The mounted wrapper.
 */
async function formFor(id) {
	axios.get.mockImplementation(answers)
	const wrapper = shallowMount(CaseLifecycleMenuDialog, {
		props: { caseId: 'case-1' },
		global: { stubs: DIALOG_RENDERS_ITS_SLOT },
	})
	await flushPromises()
	wrapper.vm.choose(wrapper.vm.entries.find((row) => row.id === id))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('the menu copies what the engine says about a closing move', () => {
	it('carries closesCase and the result types on the transition entry', () => {
		const [start, close] = buildActsMenu({ transitions: [START, CLOSE] })

		expect(close.closesCase).toBe(true)
		expect(close.resultTypes).toEqual(RESULTS)
		expect(start.closesCase).toBe(false)
		expect(start.resultTypes).toEqual([])
	})

	it('asks for a result on a closing move with results, and only then', () => {
		const [start, close] = buildActsMenu({ transitions: [START, CLOSE] })
		const noResults = buildActsMenu({
			transitions: [{ ...CLOSE, resultTypes: [] }],
		})[0]

		expect(inputsFor(close).result).toBe(true)
		expect(inputsFor(start).result).toBe(false)
		expect(inputsFor(noResults).result).toBe(false)
	})
})

describe('the lifecycle dialog on a closing move', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers the case type result types in a picker', async () => {
		const wrapper = await formFor('tr-close')

		const picker = wrapper.findComponent({ name: 'NcSelect' })
		expect(picker.exists()).toBe(true)
		expect(picker.props('options')).toEqual(RESULTS)
		expect(wrapper.find('[data-testid="case-act-result-input"]').exists()).toBe(
			false,
		)
	})

	it('offers no picker on a move that does not close the case', async () => {
		const wrapper = await formFor('tr-start')

		expect(wrapper.findComponent({ name: 'NcSelect' }).exists()).toBe(false)
	})

	it('keeps confirm closed until a result is picked, then sends it', async () => {
		axios.post.mockResolvedValue({ data: { status: 'ok' } })
		const wrapper = await formFor('tr-close')
		wrapper.vm.reason = 'Klaar.'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(false)

		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', RESULTS[1])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(true)

		await wrapper.vm.confirm()
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/case/case-1/transition'),
			{
				transitionId: 'tr-close',
				comment: 'Klaar.',
				resultTypeId: 'rt-refused',
			},
		)
	})

	it('shows the confirm button busy while the post runs', async () => {
		let settle
		axios.post.mockImplementation(
			() =>
				new Promise((resolve) => {
					settle = () => resolve({ data: { status: 'ok' } })
				}),
		)
		const wrapper = await formFor('tr-start')
		wrapper.vm.reason = 'Door.'
		await wrapper.vm.$nextTick()

		const pending = wrapper.vm.confirm()
		await wrapper.vm.$nextTick()
		const confirm = wrapper.find('[data-testid="case-acts-confirm"]')
		expect(confirm.attributes('aria-busy')).toBe('true')
		expect(wrapper.find('[data-testid="case-acts-confirm-busy"]').exists()).toBe(
			true,
		)

		settle()
		await pending
		await flushPromises()
		expect(wrapper.vm.busy).toBe(false)
	})
})
