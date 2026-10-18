// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opschorten picks one of the case type's pause reasons, shows the ground it
 * rests on under the days, and keeps the free text behind "Other". A case type
 * without reasons keeps the free-text reason alone.
 *
 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	asksReasonText,
	offersPauseReasons,
	OTHER_PAUSE_REASON,
	pauseReasonToSend,
	pickedPauseReason,
} from '../../src/utils/pauseReasonChoice.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseLifecycleMenuDialog = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default
const CaseActFields = (await import('../../src/components/case/CaseActFields.vue')).default

const REASONS = [
	{ key: 'machtiging', name: 'Wacht op machtiging van de verzoeker', legalBasis: 'Awb 4:5, hersteltermijn' },
]
const SUSPEND = { kind: 'gesture', id: 'suspend' }

describe('the pause reason choice', () => {
	it('offers a picker only on suspend, and only when the case type declares reasons', () => {
		expect(offersPauseReasons(SUSPEND, REASONS)).toBe(true)
		expect(offersPauseReasons(SUSPEND, [])).toBe(false)
		expect(offersPauseReasons({ id: 'hold' }, REASONS)).toBe(false)
	})

	it('asks for the free text without a picker, and with one only after Other', () => {
		expect(asksReasonText(SUSPEND, [], '')).toBe(true)
		expect(asksReasonText(SUSPEND, REASONS, 'machtiging')).toBe(false)
		expect(asksReasonText(SUSPEND, REASONS, OTHER_PAUSE_REASON)).toBe(true)
	})

	it('sends a declared key and never the Other entry', () => {
		expect(pauseReasonToSend('machtiging')).toBe('machtiging')
		expect(pauseReasonToSend(OTHER_PAUSE_REASON)).toBe('')
		expect(pauseReasonToSend('')).toBe('')
		expect(pickedPauseReason(REASONS, 'machtiging').legalBasis).toBe('Awb 4:5, hersteltermijn')
		expect(pickedPauseReason(REASONS, OTHER_PAUSE_REASON)).toBeNull()
	})
})

describe('the Opschorten fields', () => {
	/**
	 * The fields for suspend.
	 *
	 * @param {object} props Extra props.
	 * @return {object} The wrapper.
	 */
	const fields = (props) => shallowMount(CaseActFields, {
		props: { act: SUSPEND, inputs: { reason: true, result: false, until: false, days: true }, days: '14', ...props },
	})

	it('draws the picker and hides the text until Other is picked', () => {
		const picked = fields({ pauseReasons: REASONS, pauseReason: 'machtiging' })
		expect(picked.find('[data-testid="case-act-pause-reason-picker"]').exists()).toBe(true)
		expect(picked.find('#case-act-reason').exists()).toBe(false)

		const other = fields({ pauseReasons: REASONS, pauseReason: OTHER_PAUSE_REASON })
		expect(other.find('#case-act-reason').exists()).toBe(true)
	})

	it('shows the picked reason\'s legal basis under the days', () => {
		const wrapper = fields({ pauseReasons: REASONS, pauseReason: 'machtiging' })
		expect(wrapper.findComponent('[data-testid="case-act-days-input"]').attributes('helpertext')).toBe('Awb 4:5, hersteltermijn')
	})

	it('keeps today\'s free-text field when the case type declares no reasons', () => {
		const wrapper = fields({ pauseReasons: [] })
		expect(wrapper.find('[data-testid="case-act-pause-reason-picker"]').exists()).toBe(false)
		expect(wrapper.find('#case-act-reason').exists()).toBe(true)
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

		wrapper.vm.pauseReason = 'machtiging'
		expect(wrapper.vm.canConfirm).toBe(true)

		axios.post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirm()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ reason: '', days: 14, pauseReason: 'machtiging' })
	})

	it('needs the text after Other, and posts no key', async () => {
		const wrapper = await suspendForm(REASONS)
		wrapper.vm.pauseReason = OTHER_PAUSE_REASON
		expect(wrapper.vm.canConfirm).toBe(false)

		wrapper.vm.reason = 'Ander verhaal'
		axios.post.mockResolvedValue({ data: {} })
		await wrapper.vm.confirm()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ reason: 'Ander verhaal', pauseReason: '' })
	})

	it('works as before on a case type without reasons', async () => {
		const wrapper = await suspendForm(undefined)
		expect(wrapper.vm.pauseReasons).toEqual([])
		wrapper.vm.reason = 'Aanvulling gevraagd'
		expect(wrapper.vm.canConfirm).toBe(true)
	})
})
