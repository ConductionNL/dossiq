// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Each row of the contact page's documents and contact moments lists opens
 * what it names (REQ-CRS-04, row 5.4). The lists were there; a documents row
 * opened only its case and a contact-moment row opened nothing.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ContactMomentDetailDialog from '../../src/dialogs/ContactMomentDetailDialog.vue'
import { documentUrlOfDispatch, openDocumentOfDispatch } from '../../src/utils/contactDocuments.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const PAGES = ['ContactDetail', 'OrganisationDetail']

/**
 * One widget of one page.
 *
 * @param {string} pageId The page id.
 * @param {string} widgetId The widget id.
 * @return {object|undefined}
 */
function widget(pageId, widgetId) {
	const page = manifest.pages.find((entry) => entry.id === pageId)
	return page.config.widgets.find((w) => w.id === widgetId)
}

const STUBS = {
	NcDialog: { name: 'NcDialog', props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['href'], emits: ['click'], template: '<a v-if="href" :href="href"><slot /></a><button v-else @click="$emit(\'click\')"><slot /></button>' },
}

describe('the contact pages declare a row action per list', () => {
	it.each(PAGES)('%s opens a document from its documents row', (id) => {
		const actions = widget(id, 'contact-documents').content.rowActions
		expect(actions.map((a) => a.id)).toEqual(['open-document', 'open-case'])
		expect(actions[0]).toMatchObject({ type: 'handler', handler: 'openDocumentOfDispatch' })
	})

	it.each(PAGES)('%s opens a contact moment from its row', (id) => {
		const actions = widget(id, 'contact-moments').content.rowActions
		expect(actions).toHaveLength(1)
		expect(actions[0]).toMatchObject({
			id: 'open-moment',
			type: 'open-modal',
			target: 'ContactMomentDetailDialog',
		})
	})

	it('registers both names the manifest uses', () => {
		// Read as text: importing the registry mounts every component it names.
		const source = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
		expect(source).toMatch(/openDocumentOfDispatch: \{\n\t\tkind: 'handler',\n\t\thandler: openDocumentOfDispatch,/)
		expect(source).toMatch(/ContactMomentDetailDialog: \{\n\t\tkind: 'modal',\n\t\tcomponent: ContactMomentDetailDialog,/)
	})
})

describe('documentUrlOfDispatch', () => {
	it('opens a file-backed document in Files', () => {
		expect(documentUrlOfDispatch({ document: { id: 'd1', fileId: 42 } })).toBe('/index.php/f/42')
	})

	it('reads a numeric string file id', () => {
		expect(documentUrlOfDispatch({ document: { fileId: '7' } })).toBe('/index.php/f/7')
	})

	it('falls back to an http(s) link', () => {
		expect(documentUrlOfDispatch({ document: { link: ' https://example.org/a.pdf ' } })).toBe('https://example.org/a.pdf')
	})

	it('refuses a link that is not http(s)', () => {
		expect(documentUrlOfDispatch({ document: { link: 'javascript:alert(1)' } })).toBe('')
	})

	it('refuses a non-positive file id and falls through to nothing', () => {
		expect(documentUrlOfDispatch({ document: { fileId: 0 } })).toBe('')
	})

	it('answers nothing when the document was not inlined', () => {
		expect(documentUrlOfDispatch({ document: 'doc-uuid' })).toBe('')
		expect(documentUrlOfDispatch(null)).toBe('')
	})
})

describe('openDocumentOfDispatch', () => {
	let assign

	beforeEach(() => {
		assign = vi.fn()
		vi.stubGlobal('window', { location: { assign } })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('navigates to the document, not to the dispatch row', () => {
		openDocumentOfDispatch({ item: { id: 'dispatch-1', document: { fileId: 9 } } })
		expect(assign).toHaveBeenCalledWith('/index.php/f/9')
	})

	it('reads the row the object list appends last', () => {
		openDocumentOfDispatch({ actionId: 'open-document' }, { id: 'dispatch-2', document: { fileId: 3 } })
		expect(assign).toHaveBeenCalledWith('/index.php/f/3')
	})

	it('goes nowhere when the row names no openable document', () => {
		openDocumentOfDispatch({ item: { id: 'dispatch-3', document: 'uuid' } })
		expect(assign).not.toHaveBeenCalled()
	})
})

describe('ContactMomentDetailDialog', () => {
	it('shows the moment the row names, without its empty fields', () => {
		const wrapper = mount(ContactMomentDetailDialog, {
			props: {
				row: {
					id: 'm1',
					notificationChannel: 'phone',
					direction: 'inbound',
					summary: 'Asked about the permit',
					message: '',
					case: 'case-9',
				},
			},
			global: { stubs: STUBS },
		})
		expect(wrapper.find('[data-testid="contact-moment-detail-channel"]').text()).toBe('Phone')
		expect(wrapper.find('[data-testid="contact-moment-detail-direction"]').text()).toBe('Inbound')
		expect(wrapper.find('[data-testid="contact-moment-detail-summary"]').text()).toBe('Asked about the permit')
		expect(wrapper.find('[data-testid="contact-moment-detail-message"]').exists()).toBe(false)
		expect(wrapper.find('a').attributes('href')).toBe('/index.php/apps/dossiq/cases/case-9')
	})

	it('says so when nothing is readable, and offers no case link', () => {
		const wrapper = mount(ContactMomentDetailDialog, {
			props: { row: { id: 'm2' } },
			global: { stubs: STUBS },
		})
		expect(wrapper.find('[data-testid="contact-moment-detail-empty"]').exists()).toBe(true)
		expect(wrapper.find('a').exists()).toBe(false)
	})

	it('emits close from its Close button', async () => {
		const wrapper = mount(ContactMomentDetailDialog, {
			props: { row: { summary: 'x' } },
			global: { stubs: STUBS },
		})
		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
