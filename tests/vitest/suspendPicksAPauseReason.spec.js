// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opschorten picks one of the case type's pause reasons, shows the ground it
 * rests on under the days, and keeps the free text behind "Other". A case type
 * without reasons keeps the free-text reason alone.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

import { readTransitionInputs } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

const REASONS = [
	{ key: 'machtiging', name: 'Wacht op machtiging van de verzoeker', legalBasis: 'Awb 4:5, hersteltermijn' },
]
const SUSPEND = { kind: 'gesture', id: 'suspend' }
// The library's "Other" entry (nextcloud-vue utils/transitionInputs.js).
const OTHER = '__other'

/**
 * One input of an act's form.
 *
 * @param {object} act The act.
 * @param {string} field The input.
 * @return {object|undefined} The declaration.
 */
const input = (act, field) => actForm(act).inputs.find((row) => row.field === field)

describe('the pause reason choice', () => {
	it('offers a picker only on suspend, read from the case type\'s reasons, with Other falling back to the text', () => {
		expect(input(SUSPEND, 'pauseReason')).toMatchObject({
			optionsSource: 'pauseReasons',
			optionValue: 'key',
			optionLabel: 'name',
			required: true,
			label: 'Reason',
			other: { label: 'Other', input: 'reason' },
			testid: 'case-act-pause-reason-picker',
		})
		expect(input({ id: 'hold' }, 'pauseReason')).toBeUndefined()
		expect(input({ id: 'extend' }, 'pauseReason')).toBeUndefined()
	})

	it('shows the picked reason\'s legal basis under the days', () => {
		expect(input(SUSPEND, 'days').helperFrom).toEqual({ input: 'pauseReason', key: 'legalBasis' })
	})

	it('names Awb 4:5 under the days when no reason is picked (DqVolgendeStap "Awb 4:5, hersteltermijn")', () => {
		expect(input(SUSPEND, 'days').description).toBe('Awb 4:5, recovery period')
	})

	it('fills the days with the picked reason\'s default (4.1)', () => {
		expect(input(SUSPEND, 'days').prefillFrom).toEqual({ input: 'pauseReason', key: 'defaultDays' })
	})

	it('asks for the free text without reasons, and with them only after Other', () => {
		const { inputs } = actForm(SUSPEND)
		const read = (pauseReasons, values) => readTransitionInputs(inputs, values, { context: { pauseReasons } })

		expect(read([], { reason: '' }).complete).toBe(false)
		expect(read([], { reason: 'Aanvulling gevraagd' }).complete).toBe(true)
		expect(read(REASONS, { pauseReason: '' }).complete).toBe(false)
		expect(read(REASONS, { pauseReason: 'machtiging', reason: '' }).complete).toBe(true)
		expect(read(REASONS, { pauseReason: OTHER, reason: '' }).complete).toBe(false)
	})

	it('sends a declared key and never the Other entry', () => {
		const { inputs } = actForm(SUSPEND)
		const context = { pauseReasons: REASONS }
		expect(readTransitionInputs(inputs, { pauseReason: 'machtiging' }, { context }).data.pauseReason).toBe('machtiging')
		expect(readTransitionInputs(inputs, { pauseReason: OTHER, reason: 'x' }, { context }).data.pauseReason).toBe('')
	})
})

describe('suspending from the lifecycle menu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * The menu with Opschorten open, over a case type with or without reasons.
	 *
	 * @param {Array<object>} pauseReasons What `/lifecycle` answers.
	 * @return {Promise<object>} The wrapper.
	 */
	async function suspendForm(pauseReasons) {
		axios.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/lifecycle')
				? { canSuspend: true, pauseReasons }
				: url.endsWith('/acts') ? { acts: [] } : { transitions: [] },
		}))
		const wrapper = shallowMount(CaseLifecycleMenuDialog, {
			props: { caseId: 'case-1' },
			global: { stubs: { CnDialog: { template: '<div><slot /><slot name="actions" /></div>' }, CnChoiceCards: true } },
		})
		await flushPromises()
		wrapper.vm.choose(wrapper.vm.entries.find((row) => row.id === 'suspend'))
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it('waits for a pick, then posts the key without asking for text', async () => {
		const wrapper = await suspendForm(REASONS)
		expect(wrapper.vm.canConfirm).toBe(false)

		wrapper.vm.values = { ...wrapper.vm.values, pauseReason: 'machtiging' }
		expect(wrapper.vm.canConfirm).toBe(true)

		axios.post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirm()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ reason: '', days: 14, pauseReason: 'machtiging' })
	})

	it('needs the text after Other, and posts no key', async () => {
		const wrapper = await suspendForm(REASONS)
		wrapper.vm.values = { ...wrapper.vm.values, pauseReason: OTHER }
		expect(wrapper.vm.canConfirm).toBe(false)

		wrapper.vm.values = { ...wrapper.vm.values, reason: 'Ander verhaal' }
		axios.post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirm()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ reason: 'Ander verhaal', pauseReason: '' })
	})

	it('hands the case type\'s reasons and the paired layout to the fields', async () => {
		const wrapper = await suspendForm(REASONS)
		expect(wrapper.vm.fieldProps).toMatchObject({
			context: { pauseReasons: REASONS },
			columns: 2,
			markRequired: false,
			// Board DqVolgendeStap marks the inputs that are NOT required:
			// "Dagen die de aanvrager krijgt (niet verplicht)".
			markOptional: true,
			'data-testid': 'case-act-fields-suspend',
		})
	})

	it('works as before on a case type without reasons', async () => {
		const wrapper = await suspendForm(undefined)
		expect(wrapper.vm.pauseReasons).toEqual([])
		wrapper.vm.values = { ...wrapper.vm.values, reason: 'Aanvulling gevraagd' }
		expect(wrapper.vm.canConfirm).toBe(true)
	})
})
