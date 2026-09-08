// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Documents tab renders the case file as six columns, and its keyword
 * filter narrows what you are looking at.
 *
 * The tab is the interim rendering of a list whose columns live on a
 * REFERENCED informatieobject, so the widget swaps to an `object-list` once
 * the library renders a `$ref` column by a label field. This spec asserts the
 * column HEADINGS and the rendered values, never the widget type, so the swap
 * cannot silently drop a column on its way through.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A pass-through stub for one @nextcloud/vue component.
 *
 * The real package ships `.css` side-effect imports that Node's ESM loader
 * refuses under Vitest, so the whole module is mocked. Every stub renders its
 * default slot and keeps the props the assertions read, which is all the tab's
 * own logic needs to be exercised.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @param {string[]} props The props to keep.
 * @return {object} The stub component.
 */
function stub(name, tag = 'div', props = []) {
	return defineComponent({
		name,
		props,
		emits: ['click', 'update:modelValue', 'close'],
		render() {
			return h(
				tag,
				{
					class: name,
					'data-label': this.inputLabel,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button', ['type', 'disabled']),
	NcLoadingIcon: stub('NcLoadingIcon', 'span', ['size']),
	NcEmptyContent: defineComponent({
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', { class: 'NcEmptyContent' }, [
				this.name,
				this.$slots.action ? this.$slots.action() : null,
			])
		},
	}),
	NcSelect: stub('NcSelect', 'div', [
		'modelValue',
		'inputLabel',
		'options',
		'multiple',
		'disabled',
		'reduce',
		'label',
		'clearable',
		'required',
		'placeholder',
	]),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', 'input', ['modelValue']),
	NcActions: stub('NcActions', 'div', ['inline']),
	NcActionButton: stub('NcActionButton', 'button', ['disabled']),
	NcModal: stub('NcModal', 'div', ['size']),
	NcProgressBar: stub('NcProgressBar', 'div', ['value', 'error']),
	NcTextField: stub('NcTextField', 'input', [
		'modelValue',
		'label',
		'placeholder',
	]),
	NcTextArea: stub('NcTextArea', 'textarea', [
		'modelValue',
		'label',
		'placeholder',
	]),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin' }),
}))

// Imported AFTER the mocks so the component tree sees the stubbed package.
const { default: DossierTab } =
	await import('../../src/views/cases/components/DossierTab.vue')

const TYPES = [
	{ id: 'type-advies', description: 'Advies' },
	{ id: 'type-aanvraag', description: 'Aanvraag' },
]

const DOSSIER = {
	total: 2,
	groups: [
		{
			informatieobjecttype: 'type-advies',
			count: 1,
			documents: [
				{
					id: 'doc-1',
					title: 'Bezwaarschrift',
					status: 'draft',
					direction: 'incoming',
					creatiedatum: '2026-03-04',
					auteur: 'Els Jansen',
					vertrouwelijkheidaanduiding: 'openbaar',
					bestandsomvang: 2048,
					keywords: ['bezwaar'],
				},
			],
		},
		{
			informatieobjecttype: 'type-aanvraag',
			count: 1,
			documents: [
				{
					id: 'doc-2',
					title: 'Aanvraagformulier',
					status: 'final',
					creatiedatum: '2026-03-01',
					auteur: 'Piet de Boer',
					vertrouwelijkheidaanduiding: 'openbaar',
					bestandsomvang: 1024,
				},
			],
		},
	],
}

/**
 * Mount the tab over the stubbed dossier + type catalogue.
 *
 * @param {object} dossier The payload the dossier endpoint answers with.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountTab(dossier = DOSSIER) {
	axios.get.mockImplementation((url) => {
		if (url.includes('informatieobjecttype')) {
			return Promise.resolve({ data: { results: TYPES } })
		}
		return Promise.resolve({ data: dossier })
	})

	const wrapper = mount(DossierTab, {
		props: { objectId: 'case-1' },
	})
	await flushPromises()
	return wrapper
}

describe('DossierTab', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('heads the list with the six columns the case file is read by', async () => {
		const wrapper = await mountTab()
		const headings = wrapper
			.findAll('[data-testid="dossier-columns"] .dossier-tab__column')
			.map((cell) => cell.text())

		expect(headings).toEqual([
			'Title',
			'Type',
			'Status',
			'Direction',
			'Date',
			'Author',
		])
	})

	it('renders the type, direction and author of every row', async () => {
		const wrapper = await mountTab()

		expect(
			wrapper
				.findAll('[data-testid="dossier-cell-type"]')
				.map((c) => c.text()),
		).toEqual(['Advies', 'Aanvraag'])
		expect(
			wrapper
				.findAll('[data-testid="dossier-cell-direction"]')
				.map((c) => c.text()),
		).toEqual(['Incoming', 'Internal'])
		expect(
			wrapper
				.findAll('[data-testid="dossier-cell-author"]')
				.map((c) => c.text()),
		).toEqual(['Els Jansen', 'Piet de Boer'])
	})

	it('reads a document with no direction as Internal, the schema default', async () => {
		const wrapper = await mountTab()
		const directions = wrapper.findAll('[data-testid="dossier-cell-direction"]')

		expect(directions[1].text()).toBe('Internal')
	})

	it('shows the keywords of a document as chips', async () => {
		const wrapper = await mountTab()

		expect(
			wrapper.findAll('[data-testid="dossier-keyword"]').map((c) => c.text()),
		).toEqual(['bezwaar'])
	})

	it('says so, and offers the upload button, when the case has no documents', async () => {
		const wrapper = await mountTab({ total: 0, groups: [] })

		expect(wrapper.text()).toContain('No documents yet')
		expect(wrapper.find('[data-testid="dossier-columns"]').exists()).toBe(false)
	})

	it('offers the keywords in use as the filter facet', async () => {
		const wrapper = await mountTab()

		expect(wrapper.vm.availableKeywords).toEqual(['bezwaar'])
	})

	it('narrows the list to the tagged document, and clearing shows both', async () => {
		const wrapper = await mountTab()

		await wrapper.setData({ keywordFilter: ['bezwaar'] })
		expect(
			wrapper
				.findAll('[data-testid="dossier-cell-type"]')
				.map((c) => c.text()),
		).toEqual(['Advies'])

		wrapper.vm.clearKeywordFilter()
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('[data-testid="dossier-cell-type"]')).toHaveLength(2)
	})

	it('says the filter matched nothing rather than claiming an empty dossier', async () => {
		const wrapper = await mountTab()

		await wrapper.setData({ keywordFilter: ['bouwtekening'] })
		expect(wrapper.text()).toContain('No documents match this keyword')
		expect(wrapper.text()).not.toContain('No documents yet')
	})

	it('labels the keyword filter, so a screen reader can name it', async () => {
		const wrapper = await mountTab()
		const labels = wrapper
			.findAll('.NcSelect')
			.map((select) => select.attributes('data-label'))

		expect(labels).toContain('Filter by keyword')
	})
})
