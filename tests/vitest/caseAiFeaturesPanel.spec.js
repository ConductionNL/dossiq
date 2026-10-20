import axios from '@nextcloud/axios'
// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The AI features panel on the case (board DqZaakAssistent): a refusal is
 * rendered naming the feature, the reason and hermiq's gate, never as a
 * generic failure; the report group shows its count with the near-duplicates
 * beside it; an unreadable panel says it is incomplete.
 *
 * IT MOCKS THE READS AND THE HTTP CLIENT, NOT THE GATEWAY, so a refusal goes
 * through the same AiFeatureRefusal and refusalSentence a real one does.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-unredacted-document-is-refused-in-words-a-handler-can-act-on
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseAiFeaturesPanel from '../../src/components/case/CaseAiFeaturesPanel.vue'
import { converse } from '../../src/services/assistantApi.js'
import {
	fetchCaseDocuments,
	fetchCaseFeatures,
	fetchCasePrompts,
	fetchReportGroup,
} from '../../src/services/caseAiFeaturesApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace('{slug}', params.slug ?? ''),
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton' },
	NcLoadingIcon: { name: 'NcLoadingIcon' },
	NcNoteCard: { name: 'NcNoteCard' },
}))
vi.mock('../../src/services/caseAiFeaturesApi.js', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		fetchCaseFeatures: vi.fn(),
		fetchReportGroup: vi.fn(),
		fetchCaseDocuments: vi.fn(),
		fetchCasePrompts: vi.fn(),
	}
})
vi.mock('../../src/services/assistantApi.js', () => ({ converse: vi.fn() }))

/** A feature hermiq offers on premise. */
const SUMMARY = {
	slug: 'document-summary',
	surface: 'case',
	available: true,
	provider: 'Hermiq',
	residency: 'on-premise',
}

/**
 * Mount the panel with template t/n and light stubs.
 *
 * @return {object} The wrapper.
 */
function mountPanel() {
	const fill = (text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match)
	return mount(CaseAiFeaturesPanel, {
		props: { objectId: 'c-1' },
		global: {
			mocks: {
				t: (app, text, vars) => fill(text, vars),
				n: (app, one, many, count, vars) =>
					fill(count === 1 ? one : many, vars),
			},
			stubs: {
				NcLoadingIcon: true,
				NcButton: {
					props: ['disabled', 'type'],
					template:
						'<button :type="type" :disabled="disabled"><slot /></button>',
				},
				NcNoteCard: {
					props: ['type'],
					template: '<div class="note"><slot /></div>',
				},
				RouterLink: {
					props: ['to'],
					template: '<a class="case-link"><slot /></a>',
				},
			},
		},
	})
}

describe('CaseAiFeaturesPanel', () => {
	beforeEach(() => {
		vi.mocked(fetchCaseFeatures).mockReset()
		vi.mocked(fetchReportGroup).mockReset()
		vi.mocked(fetchCaseDocuments).mockReset()
		axios.post.mockReset()
		vi.mocked(fetchReportGroup).mockResolvedValue({
			declared: false,
			available: false,
			group: null,
		})
		vi.mocked(fetchCaseDocuments).mockResolvedValue([
			{ id: '42', title: 'besluit.pdf' },
		])
		vi.mocked(fetchCasePrompts).mockReset()
		vi.mocked(fetchCasePrompts).mockResolvedValue([])
		vi.mocked(converse).mockReset()
	})

	it("renders a redaction refusal naming the feature and hermiq's gate", async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([SUMMARY])
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: { error: 'The document is not redacted.', gate: 'redaction' },
			},
		})
		const wrapper = mountPanel()
		await flushPromises()

		expect(
			wrapper
				.find('[data-testid="case-ai-feature-where-document-summary"]')
				.text(),
		).toBe('Hermiq, runs in on-premise')
		await wrapper
			.find('[data-testid="case-ai-feature-document-document-summary"]')
			.setValue('42')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][1].documentReference).toBe('42')
		const refusal = wrapper.find(
			'[data-testid="case-ai-feature-refusal-document-summary"]',
		)
		expect(refusal.text()).toContain(
			'document-summary will not read a document that has not been redacted.',
		)
		expect(refusal.text()).toContain('Gate: redaction')
		expect(
			wrapper
				.find('[data-testid="case-ai-feature-failed-document-summary"]')
				.exists(),
		).toBe(false)
	})

	it('refuses a run with no document before anything is sent', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([SUMMARY])
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(
			wrapper
				.find('[data-testid="case-ai-feature-refusal-document-summary"]')
				.text(),
		).toContain('no document was chosen')
	})

	it('shows a transport failure apart from a refusal', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([SUMMARY])
		axios.post.mockRejectedValue(new Error('Network Error'))
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper
			.find('[data-testid="case-ai-feature-document-document-summary"]')
			.setValue('42')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(
			wrapper
				.find('[data-testid="case-ai-feature-failed-document-summary"]')
				.exists(),
		).toBe(true)
		expect(
			wrapper
				.find('[data-testid="case-ai-feature-refusal-document-summary"]')
				.exists(),
		).toBe(false)
	})

	it('renders the output of a run', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([SUMMARY])
		axios.post.mockResolvedValue({
			data: {
				feature: 'document-summary',
				documentReference: '42',
				output: 'Verleend.',
				notices: [],
			},
		})
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper
			.find('[data-testid="case-ai-feature-document-document-summary"]')
			.setValue('42')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(
			wrapper
				.find('[data-testid="case-ai-feature-output-document-summary"]')
				.text(),
		).toBe('Verleend.')
	})

	it('offers no run for a feature hermiq does not offer, and reads no documents', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([
			{ ...SUMMARY, available: false, provider: null, residency: null },
		])
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('form').exists()).toBe(false)
		expect(wrapper.text()).toContain(
			'Unavailable: hermiq does not offer this feature here.',
		)
		expect(fetchCaseDocuments).not.toHaveBeenCalled()
	})

	it('shows the group count with the near-duplicates beside it', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		vi.mocked(fetchReportGroup).mockResolvedValue({
			declared: true,
			available: true,
			group: {
				groupId: 'g-7',
				count: 200,
				nearDuplicates: [{ reportId: 'case-9', score: 0.61 }],
			},
		})
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-group-count"]').text()).toBe(
			'200 reports in this group',
		)
		const near = wrapper.findAll('[data-testid="case-ai-group-near-item"]')
		expect(near).toHaveLength(1)
		expect(near[0].text()).toContain('case-9')
		expect(near[0].text()).toContain('61% alike')
		expect(wrapper.find('[data-testid="case-ai-features-empty"]').exists()).toBe(
			true,
		)
	})

	it('renders no group section for a case type that does not group reports', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-group"]').exists()).toBe(false)
	})

	it('says the panel is incomplete when the features could not be read', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue(null)
		const wrapper = mountPanel()
		await flushPromises()

		expect(
			wrapper.find('[data-testid="case-ai-features-unreadable"]').exists(),
		).toBe(true)
		expect(wrapper.find('[data-testid="case-ai-features-empty"]').exists()).toBe(
			false,
		)
	})
	it("offers hermiq's prompts and shows the answer to one", async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		vi.mocked(fetchCasePrompts).mockResolvedValue([
			{
				id: 'p-1',
				label: 'Samenvatting van de mails',
				prompt: 'Vat de mails samen.',
			},
		])
		vi.mocked(converse).mockResolvedValue({
			reply: 'Drie mails, alle van de aanvrager.',
		})
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('[data-testid="case-ai-question-p-1"]').trigger('click')
		await flushPromises()

		expect(converse).toHaveBeenCalledWith('c-1', 'Vat de mails samen.')
		expect(wrapper.find('[data-testid="case-ai-question-answer"]').text()).toBe(
			'Drie mails, alle van de aanvrager.',
		)
	})

	it("shows the assistant's refusal when a question is not answered", async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		vi.mocked(fetchCasePrompts).mockResolvedValue([
			{ id: 'p-1', label: 'Vraag', prompt: 'Vraag?' },
		])
		vi.mocked(converse).mockRejectedValue({
			response: { data: { error: 'Blocked by guardrail.' } },
		})
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('[data-testid="case-ai-question-p-1"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-question-failed"]').text()).toBe(
			'Blocked by guardrail.',
		)
	})

	it('renders no questions when hermiq offers none', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('[data-testid="case-ai-questions"]').exists()).toBe(
			false,
		)
	})
	/**
	 * The record-action posts the panel made, in order.
	 *
	 * @return {object[]} The posted bodies.
	 */
	function decisions() {
		return axios.post.mock.calls
			.filter(([url]) => url === '/apps/dossiq/api/ai/record-action')
			.map(([, body]) => body)
	}

	it('reports a used run output to the oversight trail', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([SUMMARY])
		axios.post.mockResolvedValue({ data: { output: 'Verleend.' } })
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper
			.find('[data-testid="case-ai-feature-document-document-summary"]')
			.setValue('42')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		await wrapper
			.find('[data-testid="case-ai-feature-decision-document-summary-use"]')
			.trigger('click')
		await flushPromises()

		expect(decisions()).toHaveLength(1)
		expect(decisions()[0]).toMatchObject({
			caseId: 'c-1',
			type: 'decision_support',
			userAction: 'accepted',
			suggestion: 'Verleend.',
			documentId: '42',
		})
	})

	it('reports an adapted answer with the version that was used', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		vi.mocked(fetchCasePrompts).mockResolvedValue([
			{ id: 'p-1', label: 'Vraag', prompt: 'Vraag?' },
		])
		vi.mocked(converse).mockResolvedValue({ reply: 'Ja.' })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('[data-testid="case-ai-question-p-1"]').trigger('click')
		await flushPromises()
		await wrapper
			.find('[data-testid="case-ai-question-decision-adapt"]')
			.trigger('click')
		await wrapper
			.find('[data-testid="case-ai-question-decision-draft"]')
			.setValue('Ja, mits.')
		await wrapper
			.find('[data-testid="case-ai-question-decision-use-adapted"]')
			.trigger('click')
		await flushPromises()

		expect(decisions()[0]).toMatchObject({
			type: 'qa',
			userAction: 'modified',
			suggestion: 'Ja.',
			actualValue: 'Ja, mits.',
			prompt: 'Vraag?',
		})
	})

	it('reports an answer nobody acted on as superseded by the next question, then closed', async () => {
		vi.mocked(fetchCaseFeatures).mockResolvedValue([])
		vi.mocked(fetchCasePrompts).mockResolvedValue([
			{ id: 'p-1', label: 'Vraag', prompt: 'Vraag?' },
		])
		vi.mocked(converse)
			.mockResolvedValueOnce({ reply: 'Eerste.' })
			.mockResolvedValueOnce({ reply: 'Tweede.' })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountPanel()
		await flushPromises()

		await wrapper.find('[data-testid="case-ai-question-p-1"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="case-ai-question-p-1"]').trigger('click')
		await flushPromises()
		wrapper.unmount()
		await flushPromises()

		expect(decisions()).toHaveLength(2)
		expect(decisions()[0]).toMatchObject({
			userAction: 'ignored',
			unusedBecause: 'superseded',
			suggestion: 'Eerste.',
		})
		expect(decisions()[1]).toMatchObject({
			userAction: 'ignored',
			unusedBecause: 'closed',
			suggestion: 'Tweede.',
		})
	})
})
