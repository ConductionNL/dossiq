// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Redact the partly public documents of a case, per board DqLakken.
 *
 * The dialog is generic and the manifest action configures it, so every test
 * mounts it with the props the REAL action carries.
 *
 * @spec openspec/changes/partly-public-documents-get-a-redacted-copy/specs/document-redaction/spec.md#requirement-the-redaction-panel-follows-board-dqlakken-req-drd-005
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DocumentRedactionDialog, {
	segmentsOf,
} from '../../src/dialogs/DocumentRedactionDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const CASE_ID = '11111111-1111-4111-8111-111111111111'

const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
const action = caseDetail.config.headerActions.find(
	(entry) => entry.id === 'document-redact',
)
const page = manifest.pages.find((entry) => entry.id === 'CaseRedaction')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const TEXT = 'Geachte Sanne de Vries, bel 06 1234 5678. Contactpersoon is P. Jansen.'
const ENTITIES = [
	{ type: 'PERSON', value: 'Sanne de Vries', source: 'filinq' },
	{ type: 'PHONE', value: '06 1234 5678', source: 'filinq' },
	{ type: 'PERSON', value: 'P. Jansen', source: 'hermiq' },
]

/**
 * Mount the panel as the CaseRedaction page configures it.
 *
 * @param {object} answers What the requests answer.
 * @param {Array<object>} answers.documents The partly public documents.
 * @param {boolean} answers.asPage Draw it as the page instead of the dialog.
 * @return {Promise<{wrapper: object, axios: object}>} The mounted dialog and the axios double.
 */
async function mountDialog({
	documents = [
		{
			documentRef: 'doc-1',
			title: 'Offerte verlichting.pdf',
			weigeringsgronden: ['5.1.2e'],
			redactedDocumentRef: '',
		},
	],
	asPage = false,
} = {}) {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url) => {
		if (String(url).endsWith('/redactions')) {
			return Promise.resolve({ data: { documents } })
		}
		return Promise.reject(new Error('unexpected GET ' + url))
	})
	axios.post.mockImplementation((url) => {
		if (String(url).endsWith('/detect')) {
			return Promise.resolve({
				data: { text: TEXT, redaction: { entities: ENTITIES } },
			})
		}
		return Promise.resolve({ data: {} })
	})

	const wrapper = mount(DocumentRedactionDialog, {
		props: { ...page.config, caseId: CASE_ID, asPage },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: {
					props: ['disabled', 'pressed'],
					emits: ['click', 'update:pressed'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\'); $emit(\'update:pressed\', true)"><slot /></button>',
				},
				NcSelect: true,
				NcTextField: true,
				RouterLink: { props: ['to'], template: '<a><slot /></a>' },
			},
		},
	})
	await flushPromises()

	return { wrapper, axios }
}

/**
 * Click a verdict for one passage.
 *
 * @param {object} wrapper The dialog.
 * @param {number} at The passage.
 * @param {string} verdict The verdict.
 * @return {Promise<void>}
 */
async function judge(wrapper, at, verdict) {
	await wrapper
		.find(`[data-testid="document-redaction-verdict-${at}-${verdict}"]`)
		.trigger('click')
}

describe('DocumentRedactionDialog', () => {
	beforeEach(async () => {
		const axios = (await import('@nextcloud/axios')).default
		axios.get.mockReset()
		axios.post.mockReset()
		;(await import('@nextcloud/event-bus')).emit.mockReset()
	})

	it('is a page the case header navigates to, whose routes exist', () => {
		expect(action.type).toBe('navigate')
		expect(action.target).toBe('/cases/{objectId}/redact')
		expect(page.route).toBe('/cases/:id/redact')
		expect(page.component).toBe('CaseRedactionView')
		for (const name of ['index', 'detect', 'check', 'redact', 'upload']) {
			expect(routes).toContain(`'documentRedaction#${name}'`)
		}
	})

	it('detects the passages of the first document and shows them', async () => {
		const { wrapper, axios } = await mountDialog()

		expect(axios.post.mock.calls[0][0]).toBe(
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/redactions/doc-1/detect`,
		)
		expect(
			wrapper.find('[data-testid="document-redaction-position"]').text(),
		).toContain('document 1 of 1')
		expect(
			wrapper.find('[data-testid="document-redaction-passage-0"]').text(),
		).toContain('Name: "Sanne de Vries"')
		expect(
			wrapper.find('[data-testid="document-redaction-passage-1"]').text(),
		).toContain('Phone number: "06 1234 5678"')
		expect(
			wrapper.find('[data-testid="document-redaction-passage-0"]').text(),
		).toContain('5.1.2e')
	})

	it('draws the page with its title, breadcrumb and buttons in the header', async () => {
		const { wrapper } = await mountDialog({ asPage: true })

		expect(wrapper.find('[data-testid="document-redaction-page"]').exists()).toBe(true)
		expect(wrapper.find('.document-redaction__title').text()).toBe('Redact: Offerte verlichting.pdf')
		expect(wrapper.find('[data-testid="document-redaction-crumbs"]').text()).toContain('All cases')
		const head = wrapper.find('.document-redaction__head')
		expect(head.find('[data-testid="document-redaction-previous"]').exists()).toBe(true)
		expect(head.find('[data-testid="document-redaction-redact"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid="document-redaction-redact"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="document-redaction-close"]').exists()).toBe(false)
	})

	it('keeps the header buttons on the page when no document waits, disabled', async () => {
		const { wrapper } = await mountDialog({ asPage: true, documents: [] })

		expect(wrapper.find('[data-testid="document-redaction-empty"]').exists()).toBe(true)
		for (const id of ['previous', 'next', 'redact']) {
			const button = wrapper.find(`.document-redaction__head [data-testid="document-redaction-${id}"]`)
			expect(button.exists()).toBe(true)
			expect(button.attributes('disabled')).toBeDefined()
		}
	})

	it('records nothing until every passage has a verdict', async () => {
		const { wrapper, axios } = await mountDialog()
		await judge(wrapper, 0, 'accept')
		await judge(wrapper, 1, 'accept')

		const redact = wrapper.find('[data-testid="document-redaction-redact"]')
		expect(redact.attributes('disabled')).toBeDefined()
		await redact.trigger('click')
		expect(axios.post).toHaveBeenCalledTimes(1)

		await judge(wrapper, 2, 'skip')
		expect(
			wrapper
				.find('[data-testid="document-redaction-redact"]')
				.attributes('disabled'),
		).toBeUndefined()
	})

	it('checks only the passages to redact, then asks for the copy', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockImplementation((url) => {
			if (String(url).endsWith('/redact')) {
				return Promise.resolve({
					data: {
						redactedDocumentRef: 'copy-1',
						redaction: { method: 'filinq' },
					},
				})
			}
			return Promise.resolve({ data: {} })
		})
		await judge(wrapper, 0, 'accept')
		await judge(wrapper, 1, 'accept')
		await judge(wrapper, 2, 'skip')

		await wrapper
			.find('[data-testid="document-redaction-redact"]')
			.trigger('click')
		await flushPromises()

		const calls = axios.post.mock.calls.map((call) => call[0])
		expect(calls).toEqual([
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/redactions/doc-1/detect`,
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/redactions/doc-1/check`,
			`/index.php/apps/dossiq/api/cases/${CASE_ID}/redactions/doc-1/redact`,
		])
		expect(
			axios.post.mock.calls[1][1].entities.map((entity) => entity.value),
		).toEqual(['Sanne de Vries', '06 1234 5678'])
		expect(
			wrapper.find('[data-testid="document-redaction-done"]').exists(),
		).toBe(true)
		expect((await import('@nextcloud/event-bus')).emit).toHaveBeenCalled()
	})

	it('shows the server sentence when the copy is refused', async () => {
		const { wrapper, axios } = await mountDialog()
		axios.post.mockImplementation((url) => {
			if (String(url).endsWith('/redact')) {
				return Promise.reject(
					Object.assign(new Error('503'), {
						response: {
							status: 503,
							data: {
								error: 'detection-unavailable',
								message: 'Entity detection is off.',
							},
						},
					}),
				)
			}
			return Promise.resolve({ data: {} })
		})
		await judge(wrapper, 0, 'accept')
		await judge(wrapper, 1, 'skip')
		await judge(wrapper, 2, 'skip')

		await wrapper
			.find('[data-testid="document-redaction-redact"]')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="document-redaction-error"]').text()).toBe(
			'Entity detection is off.',
		)
		expect(
			wrapper.find('[data-testid="document-redaction-done"]').exists(),
		).toBe(false)
	})

	it('does not detect again for a document whose copy is recorded', async () => {
		const { axios } = await mountDialog({
			documents: [
				{
					documentRef: 'doc-1',
					title: 'Offerte',
					redactedDocumentRef: 'copy-1',
					redaction: { entities: ENTITIES },
				},
			],
		})

		expect(axios.post).not.toHaveBeenCalled()
	})
})

describe('segmentsOf', () => {
	it('marks every passage with its verdict and keeps the rest plain', () => {
		const segments = segmentsOf(TEXT, [
			{ value: 'Sanne de Vries', verdict: 'accept' },
			{ value: 'P. Jansen', verdict: 'skip' },
			{ value: 'niet in de tekst', verdict: 'accept' },
		])

		expect(segments.map((segment) => segment.text).join('')).toBe(TEXT)
		expect(segments.filter((segment) => segment.verdict !== null)).toEqual([
			{ text: 'Sanne de Vries', verdict: 'accept' },
			{ text: 'P. Jansen', verdict: 'skip' },
		])
	})
})
