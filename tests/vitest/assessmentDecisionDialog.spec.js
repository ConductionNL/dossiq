// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Decide a case from the assessments of its documents.
 *
 * The dialog is generic and the manifest action configures it, so every test
 * mounts it with the props the REAL action carries.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AssessmentDecisionDialog from '../../src/dialogs/AssessmentDecisionDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const decisionService = fs.readFileSync(
	path.join(
		ROOT,
		'lib',
		'Service',
		'DecisionDocument',
		'PublicationReadiness.php',
	),
	'utf8',
)
const CASE_ID = '11111111-1111-4111-8111-111111111111'

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const actions = caseDetail.config.headerActions
const action = actions.find((entry) => entry.id === 'assessment-decide')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const ASSESSED = [
	{ classification: 'openbaar' },
	{ classification: 'openbaar' },
	{ classification: 'deels_openbaar' },
]

/**
 * Mount the dialog as the manifest action configures it.
 *
 * @param {object} answers What the reads answer.
 * @param {Array<object>|Error} answers.outstanding The outstanding documents, or the failure.
 * @param {Array<object>|Error} answers.assessed The stored assessments, or the failure.
 * @return {Promise<{wrapper: object, axios: object}>} The mounted dialog and the axios double.
 */
async function mountDialog({ outstanding = [], assessed = ASSESSED, letter = null } = {}) {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url, config) => {
		if (String(url).includes('/document-assessments/outstanding')) {
			return outstanding instanceof Error
				? Promise.reject(outstanding)
				: Promise.resolve({
						data: { count: outstanding.length, documents: outstanding },
					})
		}
		if (
			String(url).includes(
				'/apps/openregister/api/objects/dossiq/wooDocumentAssessment',
			)
		) {
			expect(config.params.caseRef).toBe(CASE_ID)
			return assessed instanceof Error
				? Promise.reject(assessed)
				: Promise.resolve({ data: { results: assessed } })
		}
		if (String(url).includes(`/apps/dossiq/api/cases/${CASE_ID}/letters/decision`)) {
			if (letter instanceof Error) {
				return Promise.reject(letter)
			}
			return Promise.resolve({
				data: {
					declaration: { title: 'Woo-besluit', documentType: 'Besluit' },
					document: letter,
				},
			})
		}
		return Promise.reject(new Error('unexpected GET ' + url))
	})

	const wrapper = mount(AssessmentDecisionDialog, {
		props: { ...action.props, caseId: CASE_ID },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				CnChoiceCards: true,
				BeschikkingComposerDialog: true,
				NcNoteCard: { template: '<div><slot /></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcTextArea: true,
			},
		},
	})
	await flushPromises()

	return { wrapper, axios }
}

describe('AssessmentDecisionDialog', () => {
	beforeEach(async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
		axios.post.mockReset()
		;(await import('@nextcloud/event-bus')).emit.mockReset()
	})

	it('proposes the mixed outcome and counts the documents in its description', async () => {
		const { wrapper } = await mountDialog()

		expect(wrapper.vm.proposedOutcome).toBe('deels_openbaar')
		expect(wrapper.vm.outcomeOptions.map((option) => option.label)).toEqual([
			'Made public',
			'Made public in part',
			'Not made public',
		])
		expect(wrapper.vm.outcomeOptions[1].description).toBe(
			'Proposal from the assessment: 2 public, 1 disclosed in part',
		)
		expect(wrapper.vm.outcomeOptions[0].description).toBe(
			'All requested documents become public',
		)
		// Read-only at full contrast: the decision follows the assessment.
		const cards = wrapper.find('cn-choice-cards-stub')
		expect(cards.attributes('readonly')).toBeDefined()
		expect(cards.attributes('disabled')).toBeUndefined()
	})

	it('testOutstandingDocumentsBlockSubmit', async () => {
		const { wrapper, axios } = await mountDialog({
			outstanding: [{ id: 'io-3', title: 'Mail aannemer, 3 maart 2026' }],
		})

		expect(
			wrapper.find('[data-testid="assessment-decision-blocked"]').text(),
		).toContain('Mail aannemer, 3 maart 2026')
		expect(
			wrapper
				.find('[data-testid="assessment-decision-save"]')
				.attributes('disabled'),
		).toBeDefined()
		await wrapper
			.find('[data-testid="assessment-decision-save"]')
			.trigger('click')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('offers nothing to save when the outstanding list cannot be read', async () => {
		const { wrapper } = await mountDialog({
			outstanding: Object.assign(new Error('503'), {
				response: {
					status: 503,
					data: { message: 'OpenRegister is not available.' },
				},
			}),
		})

		expect(
			wrapper.find('[data-testid="assessment-decision-load-error"]').text(),
		).toBe('OpenRegister is not available.')
		expect(
			wrapper
				.find('[data-testid="assessment-decision-save"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	it('still decides when only the counts cannot be read', async () => {
		const { wrapper } = await mountDialog({ assessed: new Error('403') })

		expect(wrapper.vm.outcomeOptions[1].description).toBe(
			'Some of the documents become public, in full or in part',
		)
		expect(
			wrapper
				.find('[data-testid="assessment-decision-counts-error"]')
				.exists(),
		).toBe(true)
		expect(
			wrapper
				.find('[data-testid="assessment-decision-save"]')
				.attributes('disabled'),
		).toBeUndefined()
	})

	it('testItPostsTheDecision', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockResolvedValue({
			data: { decisionId: 'dec-1', caseId: CASE_ID },
		})
		wrapper.vm.description = '  Deels openbaar gemaakt.  '
		await flushPromises()

		await wrapper
			.find('[data-testid="assessment-decision-save"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toContain(`/apps/dossiq/api/cases/${CASE_ID}/woo/decision`)
		expect(body).toEqual({
			decision: { description: 'Deels openbaar gemaakt.' },
		})
		expect(
			wrapper.find('[data-testid="assessment-decision-done"]').exists(),
		).toBe(true)
		// REQ-WCS-003: the page reads the case again, so what follows the
		// decision is offered without a reload.
		expect((await import('@nextcloud/event-bus')).emit).toHaveBeenCalledWith(
			'cn:page:refresh',
			{},
		)
	})

	it('shows the case\'s letter and records the decision with it', async () => {
		const { wrapper, axios } = await mountDialog({
			letter: { id: 'io-9', title: 'Besluit Woo-verzoek.docx', creatiedatum: '2026-10-28' },
		})
		axios.post.mockResolvedValue({ data: { decisionId: 'dec-1' } })

		expect(wrapper.find('[data-testid="assessment-decision-letter-title"]').text()).toBe('Besluit Woo-verzoek.docx')
		expect(wrapper.find('[data-testid="assessment-decision-letter-other"]').exists()).toBe(true)
		await wrapper.find('[data-testid="assessment-decision-save"]').trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1]).toEqual({ decision: {}, letter: 'io-9' })
	})

	it('says the letter could not be read instead of hiding the row', async () => {
		const { wrapper } = await mountDialog({ letter: new Error('404') })

		expect(wrapper.find('[data-testid="assessment-decision-letter"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="assessment-decision-letter-unread"]').exists()).toBe(true)
	})

	it('offers to draft the letter when the case has none', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockResolvedValue({
			data: {
				declaration: { title: 'Woo-besluit', documentType: 'Besluit' },
				document: { id: 'io-10', title: 'Woo-besluit.docx', creatiedatum: '2026-10-28' },
			},
		})

		await wrapper.find('[data-testid="assessment-decision-letter-draft"]').trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][0]).toContain(`/apps/dossiq/api/cases/${CASE_ID}/letters/decision`)
		expect(wrapper.find('[data-testid="assessment-decision-letter-title"]').text()).toBe('Woo-besluit.docx')
	})

	it('posts an empty decision when no summary is given, so the server writes its own', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockResolvedValue({ data: { decisionId: 'dec-1' } })

		await wrapper
			.find('[data-testid="assessment-decision-save"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1]).toEqual({ decision: {} })
	})

	it('shows a refusal, reads the list again and does not read as decided', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: { error: 'Cannot create besluit: 1 document(s) not assessed' },
			},
		})

		await wrapper
			.find('[data-testid="assessment-decision-save"]')
			.trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="assessment-decision-error"]').text(),
		).toContain('not assessed')
		expect(
			wrapper.find('[data-testid="assessment-decision-done"]').exists(),
		).toBe(false)
		expect(
			axios.get.mock.calls.filter(([url]) =>
				String(url).includes('/outstanding'),
			),
		).toHaveLength(2)
		expect((await import('@nextcloud/event-bus')).emit).not.toHaveBeenCalled()
	})
})

describe('the assessment-decide header action', () => {
	it('opens the registered dialog', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('AssessmentDecisionDialog')
		expect(registrySource).toMatch(
			/AssessmentDecisionDialog: \{\s*kind: 'modal'/,
		)
		expect(action.props.decideUrl).toContain('{caseId}')
	})

	it('hands over to Publish through the field the decision route writes (REQ-WCS-003)', () => {
		// The decision action is offered while the case has no publication
		// state, Publish once that state is `ready`, and the decision service
		// is what writes `ready`. Three places that must keep naming one field.
		expect(action.visibleWhen.all).toContainEqual({
			field: 'wooPublicationStatus',
			op: 'empty',
		})
		const publish = actions.find((entry) => entry.id === 'woo-publish')
		expect(publish.visibleWhen.all).toContainEqual({
			field: 'wooPublicationStatus',
			op: 'eq',
			value: 'ready',
		})
		expect(decisionService).toContain(
			"changes: ['wooPublicationStatus' => 'ready']",
		)
	})

	it('is scoped to the case type that configures it', () => {
		expect(action.visibleWhen.all).toContainEqual({
			field: 'caseType',
			op: 'eq',
			value: '3c0f5a00-0000-4000-a000-00000000a001',
		})
		expect(action.visibleWhen.all).toContainEqual({
			field: '@self.archived',
			op: 'empty',
		})
	})
})
