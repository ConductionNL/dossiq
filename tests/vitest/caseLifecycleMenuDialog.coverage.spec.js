// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CaseLifecycleMenuDialog driven through its template: the approvals the
 * case waits on (heading, sentence, approvers and the ask button, with its
 * post, re-read and refusal), an act opened directly (its own title and
 * explainer, its inputs filled from the fields component, the post and the
 * warning when the requester was not told), Cancel going back to the menu
 * before closing, the dialog's own close, and an act whose configured dialog
 * the manifest does not know folding open in the menu instead.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { showWarning, showSuccess, emit } = vi.hoisted(() => ({
	showWarning: vi.fn(),
	showSuccess: vi.fn(),
	emit: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showWarning, showSuccess, showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit }))

const { default: CaseLifecycleMenuDialog } = await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')

const CASE_ID = 'case-1'
const BASE = `/index.php/apps/dossiq/api/case/${CASE_ID}`

const stubs = {
	CnDialog: {
		props: ['name', 'subtitle', 'eyebrow'],
		emits: ['closing'],
		template: '<div><h2 class="dlg-name">{{ name }}</h2><p class="dlg-subtitle">{{ subtitle }}</p><slot /><div class="dlg-actions"><slot name="actions" /></div><button class="dlg-x" @click="$emit(\'closing\')" /></div>',
	},
	CnChoiceCards: {
		props: ['options', 'modelValue'],
		emits: ['update:modelValue'],
		template: '<div class="cards"><div v-for="option in options" :key="option.value"><button class="card" :data-value="option.value" :disabled="option.disabled" @click="$emit(\'update:modelValue\', option.value)">{{ option.label }}</button><slot v-if="option.value === modelValue" name="option-details" :option="option" /></div></div>',
	},
	CnTransitionInputFields: {
		props: ['inputs', 'modelValue'],
		emits: ['update:modelValue'],
		template: '<div class="fields" :data-fields="inputs.map((i) => i.field).join(\',\')"><button class="fill" @click="$emit(\'update:modelValue\', { ...modelValue, reason: \'Waiting for the survey\', days: \'21\' })" /></div>',
	},
	CnIcon: true,
	NcLoadingIcon: true,
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

/**
 * Answer the three reads behind the menu.
 *
 * @param {object} acts The `/acts` answer.
 * @return {void}
 */
function answer(acts = { acts: [] }) {
	axios.get.mockImplementation((url) => {
		if (url === `${BASE}/available-transitions`) {
			return Promise.resolve({ data: { transitions: [] } })
		}
		if (url === `${BASE}/lifecycle`) {
			return Promise.resolve({ data: { canSuspend: false, canResume: false, canExtend: true, canReopen: false, caseType: 'ct-1' } })
		}
		if (url === `${BASE}/acts`) {
			return Promise.resolve({ data: acts })
		}
		return Promise.reject(new Error('unexpected GET ' + url))
	})
}

async function build (props = {}, provide = {}) {
	const w = mount(CaseLifecycleMenuDialog, {
		props: { caseId: CASE_ID, ...props },
		global: { stubs, provide },
	})
	await flushPromises()
	return w
}

const byId = (w, id) => w.find(`[data-testid="${id}"]`)
const readsOfActs = () => axios.get.mock.calls.filter(([url]) => url === `${BASE}/acts`).length

const AWAITING = {
	acts: [],
	awaitingApproval: [
		{ act: 'finish', label: 'Finishing the case', sentence: 'Waits for the mayor.', approvers: ['Mayor A', 'Alderman B'] },
		{ act: 'abort', sentence: 'Waits for the board.', approvers: [], decisionRef: 'dec-1' },
	],
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('CaseLifecycleMenuDialog through its template', () => {
	it('lists what the case waits for, on whom, and offers to ask only where nothing was asked yet', async () => {
		answer(AWAITING)
		const w = await build()
		const finish = byId(w, 'case-awaiting-finish')
		expect(finish.find('.case-acts__awaiting-label').text()).toBe('Finishing the case')
		expect(finish.find('.case-acts__reason').text()).toBe('Waits for the mayor.')
		expect(byId(w, 'case-awaiting-people-finish').text()).toBe('Mayor A, Alderman B')
		expect(byId(w, 'case-awaiting-ask-finish').text()).toBe('Ask for this approval')

		const abort = byId(w, 'case-awaiting-abort')
		expect(abort.find('.case-acts__awaiting-label').text()).toBe('abort')
		expect(byId(w, 'case-awaiting-people-abort').exists()).toBe(false)
		expect(byId(w, 'case-awaiting-ask-abort').exists()).toBe(false)
	})

	it('asks for the approval and reads the menu again, or shows the refusal', async () => {
		answer(AWAITING)
		const w = await build()
		expect(readsOfActs()).toBe(1)
		axios.post.mockResolvedValueOnce({ data: {} })
		await byId(w, 'case-awaiting-ask-finish').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${BASE}/approvals/finish`)
		expect(readsOfActs()).toBe(2)
		expect(byId(w, 'case-awaiting-error').exists()).toBe(false)

		axios.post.mockRejectedValueOnce(new Error('network'))
		await byId(w, 'case-awaiting-ask-finish').trigger('click')
		await flushPromises()
		expect(byId(w, 'case-awaiting-error').text()).toBe('The case could not be changed.')
		expect(readsOfActs()).toBe(2)
	})

	it('opens an act directly under its own title, posts what was filled in and warns that the requester was not told', async () => {
		answer()
		const w = await build({ act: 'extend' })
		expect(w.find('.dlg-name').text()).toBe('Extend term')
		expect(w.find('.dlg-subtitle').text()).toBe('With a reason. The requester gets a message.')
		expect(w.find('.cards').exists()).toBe(false)
		await w.find('.fill').trigger('click')
		axios.post.mockResolvedValueOnce({ data: { notice: { noticeStatus: 'failed' } } })
		await byId(w, 'case-acts-confirm').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${BASE}/extend`, {
			reason: 'Waiting for the survey',
			resultTypeId: '',
			until: '',
			// Extend asks no days, so the typed days are not sent.
			days: 0,
			pauseReason: '',
		})
		expect(showWarning).toHaveBeenCalledWith('The term is extended. The requester was not told.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('goes back to the menu from an act opened directly, and closes from the menu', async () => {
		answer()
		const w = await build({ act: 'extend' })
		await byId(w, 'case-acts-cancel').trigger('click')
		expect(w.emitted('close')).toBeUndefined()
		expect(w.find('.dlg-name').text()).toBe('What you can do with this case')
		expect(byId(w, 'case-acts-confirm').exists()).toBe(false)
		expect(w.find('.cards').exists()).toBe(true)
		await byId(w, 'case-acts-cancel').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
		await w.find('.dlg-x').trigger('click')
		expect(w.emitted('close')).toHaveLength(2)
	})

	it('folds the act open in the menu when its configured dialog is not in the manifest', async () => {
		answer()
		const dispatch = vi.fn()
		const w = await build({ actDialogs: { extend: 'no-such-action' } }, { cnDispatchAction: dispatch })
		await w.find('.card[data-value="gesture:extend"]').trigger('click')
		expect(dispatch).not.toHaveBeenCalled()
		expect(w.emitted('close')).toBeUndefined()
		expect(w.find('.dlg-name').text()).toBe('What you can do with this case')
		expect(w.find('.fields').exists()).toBe(true)
		expect(byId(w, 'case-acts-confirm').text()).toBe('Extend term')
	})
})
