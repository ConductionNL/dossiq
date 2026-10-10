// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Gather documents on a Woo request case.
 *
 * The dialog searches the two platform sources through Nextcloud's unified
 * search, with the provider and filters the server named, and integriq's
 * source through dossiq's own endpoint. A source that cannot answer shows its
 * reason. What is refused at the add stays on screen with the server's
 * sentence; what was added leaves the pick list.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GatherDocumentsDialog from '../../src/dialogs/GatherDocumentsDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const CASE_ID = '11111111-1111-4111-8111-111111111111'
const OTHER_DOC = '33333333-3333-4333-8333-333333333333'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const SOURCES = [
	{
		id: 'files',
		label: 'Files',
		kind: 'platform',
		available: true,
		reason: '',
		search: { provider: 'files', filters: {} },
	},
	{
		id: 'cases',
		label: 'Cases',
		kind: 'platform',
		available: true,
		reason: '',
		search: {
			provider: 'openregister_objects',
			filters: { register: 'dossiq', schema: 'informatieobject' },
		},
	},
	{
		id: 'microsoft365',
		label: 'SharePoint, Teams and mail',
		kind: 'integriq',
		available: true,
		reason: '',
		search: null,
	},
]

const RECORDED_PLAN = {
	recorded: true,
	plan: {
		custodians: [
			{ name: 'Wethouder Ruimte' },
			{ name: 'Afdeling Vergunningen' },
		],
		terms: 'Stationsweg',
		periodFrom: '2025-01-01',
		periodTo: '',
	},
}

/**
 * Mount the dialog over the given sources.
 *
 * @param {Array<object>} sources What GET /woo/sources answers.
 * @param {object} plan What GET /woo/plan answers.
 * @return {Promise<{wrapper: object, axios: object}>} The mounted dialog and the axios double.
 */
async function mountDialog(sources = SOURCES, plan = RECORDED_PLAN) {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url) => {
		if (String(url).endsWith('/woo/plan')) {
			return Promise.resolve({ data: plan })
		}
		if (String(url).endsWith('/woo/sources')) {
			return Promise.resolve({ data: { sources } })
		}
		if (String(url).includes('search/providers/files/search')) {
			return Promise.resolve({
				data: {
					ocs: {
						data: {
							entries: [
								{
									title: 'notulen.pdf',
									subline: 'Team Ruimte',
									resourceUrl: '/f/11',
									attributes: {
										fileId: '11',
										path: '/Team Ruimte/notulen.pdf',
									},
								},
								{
									title: 'memo.pdf',
									subline: 'Team Verkeer',
									resourceUrl: '/f/12',
									attributes: {
										fileId: '12',
										path: '/Team Verkeer/memo.pdf',
									},
								},
							],
						},
					},
				},
			})
		}
		if (String(url).includes('search/providers/openregister_objects/search')) {
			return Promise.resolve({
				data: {
					ocs: {
						data: {
							entries: [
								{
									title: 'Advies welstand',
									subline: 'Omgevingsvergunning Stationsweg',
									resourceUrl: `/apps/dossiq/documents/${OTHER_DOC}`,
									attributes: {},
								},
							],
						},
					},
				},
			})
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
	axios.post.mockImplementation((url) => {
		if (String(url).endsWith('/woo/collection/queries')) {
			return Promise.resolve({ data: { query: { id: 'q-1' } } })
		}
		if (String(url).endsWith('/woo/sources/search')) {
			return Promise.resolve({
				data: {
					source: 'microsoft365',
					rows: [
						{
							key: 'message:1',
							name: 'Re: Stationsweg',
							location: 'Postvak',
							date: '2025-02-02',
							snippet: 'planning',
						},
					],
					remaining: 3,
					notices: ['delegated-grant-missing'],
				},
			})
		}
		return Promise.reject(new Error(`unexpected POST ${url}`))
	})

	const wrapper = mount(GatherDocumentsDialog, {
		props: { caseId: CASE_ID },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcTextField: {
					props: ['modelValue', 'label'],
					emits: ['update:modelValue'],
					template:
						'<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
				NcSelect: {
					props: ['modelValue', 'options', 'inputLabel'],
					emits: ['update:modelValue'],
					template:
						'<select :aria-label="inputLabel" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o" :value="o">{{ o }}</option></select>',
				},
				NcCheckboxRadioSwitch: {
					props: ['modelValue', 'disabled'],
					emits: ['update:modelValue'],
					template:
						'<label><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
				},
			},
		},
	})
	await flushPromises()

	return { wrapper, axios }
}

describe('GatherDocumentsDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('is a registered modal opened from a header action on the Woo case type only', () => {
		const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
		const action = caseDetail.config.headerActions.find(
			(a) => a.id === 'woo-gather-documents',
		)
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('GatherDocumentsDialog')
		expect(JSON.stringify(action.visibleWhen)).toContain(
			'3c0f5a00-0000-4000-a000-00000000a001',
		)
		expect(registrySource).toMatch(/GatherDocumentsDialog: \{\s*kind: 'modal'/)
	})

	it('calls the unified search per platform source and the dossiq endpoint for integriq', async () => {
		const { wrapper, axios } = await mountDialog()

		await wrapper.find('[data-testid="gather-terms"]').setValue('Stationsweg')
		await wrapper.find('[data-testid="gather-from"]').setValue('2025-01-01')
		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		const filesCall = axios.get.mock.calls.find(([url]) =>
			String(url).includes('providers/files/search'),
		)
		expect(filesCall[1].params).toEqual({
			term: 'Stationsweg',
			limit: 50,
			since: '2025-01-01',
		})
		const casesCall = axios.get.mock.calls.find(([url]) =>
			String(url).includes('providers/openregister_objects/search'),
		)
		expect(casesCall[1].params).toEqual({
			term: 'Stationsweg',
			limit: 50,
			register: 'dossiq',
			schema: 'informatieobject',
			since: '2025-01-01',
		})
		const integriqCall = axios.post.mock.calls.find(([url]) =>
			String(url).endsWith(`/cases/${CASE_ID}/woo/sources/search`),
		)
		expect(integriqCall[1]).toEqual({
			source: 'microsoft365',
			terms: 'Stationsweg',
			from: '2025-01-01',
			to: '',
		})

		expect(
			wrapper.find('[data-testid="gather-results-files"]').text(),
		).toContain('notulen.pdf')
		expect(
			wrapper.find('[data-testid="gather-results-cases"]').text(),
		).toContain('Advies welstand')
		expect(
			wrapper.find('[data-testid="gather-results-microsoft365"]').text(),
		).toContain('Re: Stationsweg')
		expect(
			wrapper.find('[data-testid="gather-results-microsoft365"]').text(),
		).toContain('more results. Narrow your terms')
		expect(
			wrapper.find(`[data-testid="gather-pick-cases-${OTHER_DOC}"]`).exists(),
		).toBe(true)
	})

	it('shows a source that is not connected with its reason and does not search it', async () => {
		const sources = SOURCES.map((s) =>
			s.id === 'microsoft365'
				? { ...s, available: false, reason: 'integriq-not-installed' }
				: s,
		)
		const { wrapper, axios } = await mountDialog(sources)

		expect(
			wrapper.find('[data-testid="gather-source-reason-microsoft365"]').text(),
		).toBe('Not connected: integriq is not installed.')
		await wrapper.find('[data-testid="gather-terms"]').setValue('Stationsweg')
		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		expect(
			axios.post.mock.calls.filter(([url]) =>
				String(url).endsWith('/woo/sources/search'),
			),
		).toEqual([])
		expect(wrapper.find('[data-testid="gather-results-files"]').exists()).toBe(
			true,
		)
	})

	it('adds the picks and keeps a refused one on screen with the server sentence', async () => {
		const { wrapper, axios } = await mountDialog()
		await wrapper.find('[data-testid="gather-terms"]').setValue('Stationsweg')
		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		await wrapper
			.find('[data-testid="gather-pick-files-11"] input')
			.setValue(true)
		wrapper.vm.custodian = 'Afdeling Vergunningen'
		await wrapper.vm.$nextTick()
		await wrapper
			.find('[data-testid="gather-pick-files-12"] input')
			.setValue(true)

		axios.post.mockImplementationOnce(() =>
			Promise.resolve({
				data: {
					results: [
						{
							key: '11',
							source: 'files',
							status: 'added',
							documentId: 'd1',
							reason: '',
							message: '',
						},
						{
							key: '12',
							source: 'files',
							status: 'refused',
							documentId: '',
							reason: 'not-readable',
							message:
								'You cannot read this document, so it cannot be added.',
						},
					],
					added: 1,
					refused: 1,
				},
			}),
		)
		await wrapper.find('[data-testid="gather-add"]').trigger('click')
		await flushPromises()

		const addCall = axios.post.mock.calls.find(([url]) =>
			String(url).endsWith('/woo/sources/add'),
		)
		expect(addCall[1]).toEqual({
			terms: 'Stationsweg',
			picks: [
				{
					source: 'files',
					custodian: 'Wethouder Ruimte',
					key: '11',
					location: '/Team Ruimte/notulen.pdf',
				},
				{
					source: 'files',
					custodian: 'Afdeling Vergunningen',
					key: '12',
					location: '/Team Verkeer/memo.pdf',
				},
			],
		})
		expect(wrapper.find('[data-testid="gather-refusals"]').text()).toBe(
			'memo.pdf: You cannot read this document, so it cannot be added.',
		)
		expect(wrapper.emitted('close')).toBeUndefined()
		const { emit } = await import('@nextcloud/event-bus')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})
	it('testAPlatformSearchIsRecordedAsAQuery', async () => {
		const { wrapper, axios } = await mountDialog()
		await wrapper.find('[data-testid="gather-search"]').trigger('click')
		await flushPromises()

		const stored = axios.post.mock.calls.filter(([url]) =>
			String(url).endsWith(`/cases/${CASE_ID}/woo/collection/queries`),
		)
		expect(stored.map(([, body]) => body.source).sort()).toEqual([
			'cases',
			'files',
		])
		const files = stored.find(([, body]) => body.source === 'files')[1]
		expect(files).toEqual({
			source: 'files',
			terms: 'Stationsweg',
			periodFrom: '2025-01-01',
			periodTo: '',
			resultKeys: ['11', '12'],
		})
	})

	it('without a recorded plan says so and does not search', async () => {
		const { wrapper, axios } = await mountDialog(SOURCES, {
			recorded: false,
			plan: { custodians: [{ name: 'A' }] },
		})

		expect(wrapper.find('[data-testid="gather-no-plan"]').text()).toBe(
			'Record the search plan before collecting',
		)
		await wrapper.find('[data-testid="gather-terms"]').setValue('Stationsweg')
		expect(
			wrapper.find('[data-testid="gather-search"]').attributes('disabled'),
		).toBeDefined()
		expect(axios.post).not.toHaveBeenCalled()
	})
})
