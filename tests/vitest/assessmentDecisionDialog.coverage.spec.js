// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AssessmentDecisionDialog paths the manifest-driven spec does not reach:
 * the case taken from the route when the action hands over an unresolved
 * token, a single proposed outcome with its refusal-ground chips, the
 * unconfigured dialog (no assessments, no classifications, no letter), the
 * term left or overrun against the case deadline, editing the date and the
 * reasons before saving, composing another letter, a failed draft, the aside
 * without text, and closing from Cancel and from the dialog itself.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { emit } = await import('@nextcloud/event-bus')
const { default: AssessmentDecisionDialog } = await import('../../src/dialogs/AssessmentDecisionDialog.vue')

const CASE_ID = 'case-7'

const SOURCE = { register: 'dossiq', schema: 'assessment', caseField: 'caseRef', classificationField: 'verdict' }
const CLASSIFICATIONS = [
	{ value: 'open', label: 'Public' },
	{ value: 'closed', label: 'Secret', decisionLabel: 'Kept secret', description: 'Nothing is published' },
]

const stubs = {
	CnDialog: {
		props: ['name', 'eyebrow', 'subtitle', 'noClose'],
		emits: ['closing'],
		template: '<div class="cn-dialog" :data-eyebrow="eyebrow"><slot /><div class="actions"><slot name="actions" /></div><button class="dialog-x" @click="$emit(\'closing\')" /></div>',
	},
	CnChoiceCards: {
		props: ['modelValue', 'options'],
		template: '<ul class="cards" :data-value="modelValue"><li v-for="o in options" :key="o.value">{{ o.label }}: {{ o.description }}</li></ul>',
	},
	GenerateDocumentDialog: {
		props: ['caseId', 'open'],
		emits: ['close', 'generated'],
		template: '<div class="compose" :data-case="caseId"><button class="compose-close" @click="$emit(\'close\')" /><button class="compose-done" @click="$emit(\'generated\')" /></div>',
	},
	NcNoteCard: { props: ['type', 'heading'], template: '<div class="note"><slot /></div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><span class="icon"><slot name="icon" /></span><slot /></button>',
	},
	NcTextArea: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<textarea class="reasons" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

/**
 * Answer the dialog's reads.
 *
 * @param {object} answers What each read answers.
 * @return {void}
 */
function answer({ assessed = [], kase = null, letter = undefined } = {}) {
	axios.get.mockImplementation((url, config) => {
		const u = String(url)
		if (u.endsWith(`/apps/dossiq/api/cases/${CASE_ID}/document-assessments/outstanding`)) {
			return Promise.resolve({ data: { documents: [] } })
		}
		if (u.endsWith('/apps/openregister/api/objects/dossiq/assessment')) {
			expect(config.params).toEqual({ caseRef: CASE_ID, _limit: 500 })
			return Promise.resolve({ data: { results: assessed } })
		}
		if (u.endsWith(`/apps/openregister/api/objects/dossiq/case/${CASE_ID}`)) {
			return Promise.resolve({ data: kase })
		}
		if (u.endsWith(`/apps/dossiq/api/cases/${CASE_ID}/letters/decision`) && letter !== undefined) {
			return Promise.resolve({ data: { declaration: { documentType: 'Besluit' }, document: letter } })
		}
		return Promise.reject(new Error('unexpected GET ' + u))
	})
}

async function build (props = {}, route = undefined) {
	const w = mount(AssessmentDecisionDialog, {
		props: { caseId: CASE_ID, decideUrl: '/apps/dossiq/api/cases/{caseId}/decide', ...props },
		global: { stubs, mocks: route ? { $route: route } : {} },
	})
	await flushPromises()
	return w
}

const byId = (w, id) => w.find(`[data-testid="assessment-decision-${id}"]`)

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	emit.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
	delete globalThis.OC
})

describe('AssessmentDecisionDialog, further paths', () => {
	it('reads the case from the route when handed an unresolved token, and proposes the one outcome used', async () => {
		answer({
			assessed: [
				{ verdict: 'open', weigeringsgronden: ['5.1.2e', { code: '5.2' }] },
				{ verdict: 'open', weigeringsgronden: ['5.1.2e', ''] },
			],
		})
		const w = await build({ caseId: '@objectId', assessments: SOURCE, classifications: CLASSIFICATIONS, title: 'Decide request' }, { params: { id: CASE_ID } })
		expect(axios.get).toHaveBeenCalledWith(`/index.php/apps/dossiq/api/cases/${CASE_ID}/document-assessments/outstanding`)
		expect(w.find('.cards').attributes('data-value')).toBe('open')
		expect(w.findAll('.cards li').map((li) => li.text())).toEqual([
			'Public: Proposal from the assessment: 2 public',
			'Kept secret: Nothing is published',
		])
		expect(w.findAll('.assessment-decision__chip').map((c) => c.text())).toEqual(['5.1.2e · %n documents', '5.2 · %n document'])
		expect([w.find('.cn-dialog').attributes('data-eyebrow'), w.vm.heading]).toEqual(['Decide request', 'Decide request'])
	})

	it('without configuration says the counts are not shown and reads no letter', async () => {
		answer()
		const w = await build()
		expect(byId(w, 'counts-error').text()).toBe('The number of documents per assessment is not shown here.')
		expect(w.find('.cards').exists()).toBe(false)
		expect(w.find('.assessment-decision__chip').exists()).toBe(false)
		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/document-assessments/outstanding`,
			`/index.php/apps/openregister/api/objects/dossiq/case/${CASE_ID}`,
		])
		expect(byId(w, 'letter').exists()).toBe(false)
	})

	it('counts the days left to the deadline, and the days overrun', async () => {
		vi.useFakeTimers({ now: new Date('2026-06-10T09:00:00'), toFake: ['Date'] })
		answer({ kase: { id: CASE_ID, deadline: '2026-06-15T00:00:00Z' } })
		const left = await build()
		expect(left.find('.assessment-decision__term').text()).toBe('%n days left')
		expect(left.vm.daysLeft).toBe(5)
		expect(left.text()).toContain('At the latest')

		answer({ kase: { id: CASE_ID, deadline: '2026-06-09' } })
		const late = await build()
		expect(late.find('.assessment-decision__term').text()).toBe('%n day late')

		answer({ kase: { id: CASE_ID, deadline: 'not-a-date' } })
		const odd = await build()
		expect(odd.find('.assessment-decision__term').exists()).toBe(false)
	})

	it('posts the date and reasons typed into the form, with the Gavel on the button', async () => {
		answer()
		const w = await build()
		expect(byId(w, 'save').find('.icon svg').exists()).toBe(true)
		await byId(w, 'date').setValue('2020-01-02')
		await w.find('.reasons').setValue('  Granted in full.  ')
		axios.post.mockResolvedValueOnce({ data: { decisionId: 'd-1' } })
		await byId(w, 'save').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`/index.php/apps/dossiq/api/cases/${CASE_ID}/decide`, {
			decision: { description: 'Granted in full.', decisionDate: '2020-01-02' },
		})
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(byId(w, 'done').text()).toBe('The decision is recorded on this case.')
		expect(byId(w, 'cancel').text()).toBe('Close')
		expect(byId(w, 'save').exists()).toBe(false)
	})

	it('opens the composer for another letter, closes it, and reads the letter again once generated', async () => {
		answer({ letter: { id: 'io-1', title: 'Besluit.docx', creatiedatum: '2026-10-28' } })
		const w = await build({ letterPurpose: 'decision' })
		expect(w.find('.compose').exists()).toBe(false)
		await byId(w, 'letter-other').trigger('click')
		expect(w.find('.compose').attributes('data-case')).toBe(CASE_ID)
		await w.find('.compose-close').trigger('click')
		expect(w.find('.compose').exists()).toBe(false)

		await byId(w, 'letter-other').trigger('click')
		answer({ letter: { id: 'io-2', title: 'Besluit v2.docx' } })
		await w.find('.compose-done').trigger('click')
		await flushPromises()
		expect(byId(w, 'letter-title').text()).toBe('Besluit v2.docx')
	})

	it('says when the letter could not be drafted and lets the user try again', async () => {
		answer({ letter: null })
		const w = await build({ letterPurpose: 'decision' })
		axios.post.mockRejectedValueOnce(new Error('500'))
		await byId(w, 'letter-draft').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`/index.php/apps/dossiq/api/cases/${CASE_ID}/letters/decision`)
		expect(byId(w, 'letter').find('[role="alert"]').text()).toBe('The letter could not be drafted.')
		expect(byId(w, 'letter-draft').attributes('disabled')).toBeUndefined()
	})

	it('shows no aside when it carries no text, even with its app installed', async () => {
		globalThis.OC = { appswebroots: { decidiq: '/apps/decidiq' } }
		answer()
		const w = await build({ aside: { whenApp: 'decidiq' } })
		expect(byId(w, 'aside').exists()).toBe(false)
	})

	it('closes from Cancel and from the dialog', async () => {
		answer()
		const w = await build()
		await byId(w, 'cancel').trigger('click')
		await w.find('.dialog-x').trigger('click')
		expect(w.emitted('close')).toEqual([[], []])
		expect(axios.post).not.toHaveBeenCalled()
	})
})
