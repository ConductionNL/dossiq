// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Assess documents from the case page.
 *
 * The dialog is generic: the header action in the manifest configures it. So
 * every test mounts it with the props the REAL manifest action carries, and a
 * manifest that stops configuring it fails here rather than on screen.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DocumentAssessDialog from '../../src/dialogs/DocumentAssessDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const CASE_ID = '11111111-1111-4111-8111-111111111111'

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const action = caseDetail.config.headerActions.find(
	(entry) => entry.id === 'document-assess',
)

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const THREE = [
	{ id: 'io-1', title: 'Notitie budget verlichting' },
	{ id: 'io-2', title: 'Export meldingen 2025.xlsx' },
	{ id: 'io-3', title: '' },
]

/**
 * Mount the dialog as the manifest action configures it.
 *
 * @param {Array<Array<object>>} lists What each successive read of the outstanding list answers.
 * @return {Promise<{wrapper: object, axios: object}>} The mounted dialog and the axios double.
 */
async function mountDialog(lists = [THREE], extra = {}) {
	const axios = (await import('@nextcloud/axios')).default
	let read = 0
	axios.get.mockImplementation((url) => {
		if (String(url).includes('/document-assessments/outstanding')) {
			const documents = lists[Math.min(read, lists.length - 1)]
			read++
			return Promise.resolve({ data: { count: documents.length, documents } })
		}
		if (
			String(url).includes(
				'/apps/openregister/api/objects/dossiq/wooRefusalGround',
			)
		) {
			return Promise.resolve({
				data: {
					results: [
						{ code: '5.1.2.e', label: 'Persoonlijke levenssfeer' },
						{ code: '5.1.2.i', label: 'Goed functioneren van de Staat' },
					],
				},
			})
		}
		return Promise.reject(new Error('unexpected GET ' + url))
	})

	const wrapper = mount(DocumentAssessDialog, {
		props: { ...action.props, caseId: CASE_ID, ...extra },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcSelect: {
					props: ['modelValue', 'options', 'inputLabel', 'multiple'],
					emits: ['update:modelValue'],
					template: '<div :data-label="inputLabel" />',
				},
			},
		},
	})
	await flushPromises()

	return { wrapper, axios }
}

/**
 * Pick a classification by its value, the way the select would.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} value The classification value.
 * @return {Promise<void>}
 */
async function classify(wrapper, value) {
	const option = wrapper.vm.classificationOptions.find(
		(entry) => entry.id === value,
	)
	wrapper.vm.classification = option
	await flushPromises()
}

describe('DocumentAssessDialog', () => {
	beforeEach(async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
		axios.post.mockReset()
		;(await import('@nextcloud/event-bus')).emit.mockReset()
	})

	it('lists what is still open, with a title for every document', async () => {
		const { wrapper, axios } = await mountDialog()

		expect(axios.get.mock.calls[0][0]).toContain(
			`/apps/dossiq/api/cases/${CASE_ID}/document-assessments/outstanding`,
		)
		expect(
			wrapper.find('[data-testid="document-assess-row-io-1"]').text(),
		).toContain('Notitie budget verlichting')
		expect(
			wrapper.find('[data-testid="document-assess-row-io-3"]').text(),
		).toContain('Document without a title')
		expect(
			wrapper
				.find('[data-testid="document-assess-save"]')
				.attributes('disabled'),
		).toBeDefined()
	})

	it('testItPostsOneRequestForTheSelection', async () => {
		const { wrapper, axios } = await mountDialog([THREE, [THREE[2]]])
		axios.post.mockResolvedValue({
			data: {
				saved: [{}, {}],
				errors: [],
				outstanding: { count: 1, documents: ['io-3'] },
			},
		})

		await wrapper
			.find('[data-testid="document-assess-select-io-1"]')
			.setValue(true)
		await wrapper
			.find('[data-testid="document-assess-select-io-2"]')
			.setValue(true)
		await classify(wrapper, 'deels_openbaar')

		// A classification that needs a ground cannot be saved without one.
		expect(
			wrapper
				.find('[data-testid="document-assess-save"]')
				.attributes('disabled'),
		).toBeDefined()
		expect(wrapper.vm.groundOptions.map((option) => option.id)).toEqual([
			'5.1.2.e',
			'5.1.2.i',
		])
		wrapper.vm.pickedGrounds = [wrapper.vm.groundOptions[0]]
		await flushPromises()

		await wrapper.find('[data-testid="document-assess-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toContain(`/apps/dossiq/api/cases/${CASE_ID}/woo/assessment`)
		expect(body).toEqual({
			assessments: [
				{
					documentRef: 'io-1',
					classification: 'deels_openbaar',
					weigeringsgronden: ['5.1.2.e'],
				},
				{
					documentRef: 'io-2',
					classification: 'deels_openbaar',
					weigeringsgronden: ['5.1.2.e'],
				},
			],
		})
		// The l10n double of this suite leaves `%n` as it is, so the count is
		// asserted on the request above and only the presence of the notice here.
		expect(wrapper.find('[data-testid="document-assess-notice"]').exists()).toBe(
			true,
		)
		expect((await import('@nextcloud/event-bus')).emit).toHaveBeenCalledTimes(1)
		expect(
			wrapper.find('[data-testid="document-assess-row-io-1"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="document-assess-row-io-3"]').exists(),
		).toBe(true)
	})

	it('posts no grounds for a classification that needs none', async () => {
		const { wrapper, axios } = await mountDialog([THREE, []])
		axios.post.mockResolvedValue({ data: { saved: [{}], errors: [] } })

		await wrapper
			.find('[data-testid="document-assess-select-all"]')
			.setValue(true)
		await classify(wrapper, 'openbaar')
		await wrapper.find('[data-testid="document-assess-save"]').trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[0][1].assessments).toHaveLength(3)
		expect(axios.post.mock.calls[0][1].assessments[0].weigeringsgronden).toEqual(
			[],
		)
		expect(wrapper.find('[data-testid="document-assess-empty"]').exists()).toBe(
			true,
		)
	})

	it('drops a saved document at once, even when the next read still lists it', async () => {
		// Rehearsal R3: the saved document stayed under "Nog geen oordeel" until
		// the page was reloaded, because the list was read again before the
		// search answered the new assessment.
		const { wrapper, axios } = await mountDialog([THREE, THREE])
		axios.post.mockResolvedValue({
			data: {
				saved: [{ documentRef: 'io-1', classification: 'openbaar' }],
				errors: [],
			},
		})

		await wrapper
			.find('[data-testid="document-assess-select-io-1"]')
			.setValue(true)
		await classify(wrapper, 'openbaar')
		await wrapper.find('[data-testid="document-assess-save"]').trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="document-assess-row-io-1"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="document-assess-row-io-2"]').exists(),
		).toBe(true)
		expect(wrapper.vm.selected).toEqual([])
	})

	it('testARefusalKeepsTheDocumentsOutstanding', async () => {
		const { wrapper, axios } = await mountDialog([THREE, THREE])
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: {
					saved: [],
					errors: [
						{
							documentRef: 'io-1',
							errors: {
								weigeringsgronden: 'Unknown weigeringsgrond: 9.9',
							},
						},
					],
				},
			},
		})

		await wrapper
			.find('[data-testid="document-assess-select-io-1"]')
			.setValue(true)
		await classify(wrapper, 'niet_openbaar')
		wrapper.vm.pickedGrounds = [{ id: '9.9', label: '9.9' }]
		await flushPromises()
		await wrapper.find('[data-testid="document-assess-save"]').trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="document-assess-refusal-io-1"]').text(),
		).toBe('Unknown weigeringsgrond: 9.9')
		expect(wrapper.find('[data-testid="document-assess-error"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="document-assess-notice"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.find('[data-testid="document-assess-row-io-1"]').exists(),
		).toBe(true)
		expect((await import('@nextcloud/event-bus')).emit).not.toHaveBeenCalled()
	})

	it('shows the sentence of a refusal that names no document', async () => {
		const { wrapper, axios } = await mountDialog([THREE, THREE])
		axios.post.mockRejectedValue({
			response: {
				status: 503,
				data: {
					error: 'woo-refusal-grounds-unavailable',
					message: 'The list cannot be read. Try again later.',
				},
			},
		})

		await wrapper
			.find('[data-testid="document-assess-select-io-2"]')
			.setValue(true)
		await classify(wrapper, 'openbaar')
		await wrapper.find('[data-testid="document-assess-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="document-assess-error"]').text()).toBe(
			'The list cannot be read. Try again later.',
		)
	})

	it('says so when the list cannot be read, and offers nothing to save', async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockRejectedValue({
			response: {
				status: 403,
				data: { message: 'You do not have access to this case.' },
			},
		})
		const wrapper = mount(DocumentAssessDialog, {
			props: { ...action.props, caseId: CASE_ID },
			global: {
				stubs: {
					NcDialog: {
						template: '<div><slot /><slot name="actions" /></div>',
					},
					NcButton: true,
					NcSelect: true,
				},
			},
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="document-assess-load-error"]').text(),
		).toBe('You do not have access to this case.')
		expect(wrapper.find('[data-testid="document-assess-table"]').exists()).toBe(
			false,
		)
	})
})

describe('the document-assess header action', () => {
	it('is configured on the case page and opens the registered dialog', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('DocumentAssessDialog')
		expect(registrySource).toMatch(/DocumentAssessDialog: \{\s*kind: 'modal'/)
		expect(action.props.assessUrl).toContain('{caseId}')
		expect(action.props.outstandingUrl).toContain('{caseId}')
		expect(
			action.props.classifications
				.filter((entry) => entry.needsGrounds)
				.map((entry) => entry.value),
		).toEqual(['deels_openbaar', 'niet_openbaar'])
	})

	it('testTheActionIsScopedToItsCaseType', () => {
		// REQ-WCS-001: not on any other case type, and not on an archived case.
		expect(action.visibleWhen.all).toContainEqual({
			field: 'caseTypeIdentifier',
			op: 'eq',
			value: 'woo-verzoek',
		})
		expect(action.visibleWhen.all).toContainEqual({
			field: '@self.archived',
			op: 'empty',
		})
	})
})

describe('DocumentAssessDialog opened from a document row (DqZaakDocumenten "Beoordelen")', () => {
	it('starts with that document chosen', async () => {
		const { wrapper } = await mountDialog([THREE], { documentId: 'io-2' })
		expect(wrapper.vm.selected).toEqual(['io-2'])
	})

	it('chooses nothing for a document that is not outstanding', async () => {
		const { wrapper } = await mountDialog([THREE], { documentId: 'io-9' })
		expect(wrapper.vm.selected).toEqual([])
	})
})
