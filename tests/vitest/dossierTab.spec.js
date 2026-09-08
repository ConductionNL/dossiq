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
const { showSuccess } = await import('@nextcloud/dialogs')
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

/**
 * The two controls in this tab that rendered and did nothing.
 *
 * `VersionHistoryPanel` emits `download-version` and `restore-version`, and
 * `DossierTab` mounted it with NO listeners, so both buttons emitted into the
 * void. The only coverage that existed asserted the Restore button is
 * DISABLED on a final document — and a control that does nothing looks exactly
 * like a control that is correctly disabled, so the one test that would have
 * caught this is the one test that could not.
 *
 * These assert the EFFECT: the request the click makes. Clicking a live
 * button and finding no request is a failure; that is the property the
 * disabled-state assertion never had.
 */
describe('DossierTab, the version panel actions', () => {
	const VERSION_HREF = '/remote.php/dav/versions/admin/versions/4242/1700000000'

	/** One version, in the multistatus shape PROPFIND answers with. */
	const VERSIONS_XML = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response><d:href>/remote.php/dav/versions/admin/versions/4242/</d:href></d:response>
  <d:response>
    <d:href>${VERSION_HREF}</d:href>
    <d:propstat><d:prop>
      <d:getlastmodified>Tue, 14 Nov 2026 22:13:20 GMT</d:getlastmodified>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`

	/** A dossier holding one document that HAS a Nextcloud file id. */
	const VERSIONED = {
		total: 1,
		groups: [
			{
				informatieobjecttype: 'type-advies',
				count: 1,
				documents: [
					{
						id: 'doc-1',
						title: 'Bezwaarschrift',
						status: 'draft',
						fileId: 4242,
						creatiedatum: '2026-03-04',
						auteur: 'Els Jansen',
						vertrouwelijkheidaanduiding: 'openbaar',
					},
				],
			},
		],
	}

	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * Mount the tab and open the version panel on its one document.
	 *
	 * @return {Promise<object>} The mounted wrapper, panel open.
	 */
	async function openVersionPanel() {
		axios.request.mockResolvedValue({ data: VERSIONS_XML })
		const wrapper = await mountTab(VERSIONED)

		wrapper.vm.showVersions(VERSIONED.groups[0].documents[0])
		await flushPromises()
		return wrapper
	}

	/**
	 * The buttons inside the open version panel, in render order.
	 *
	 * @param {object} wrapper The mounted tab.
	 * @return {Array} Download first, Restore second.
	 */
	function versionButtons(wrapper) {
		return wrapper.findAll('.dossier-version-panel__actions .NcButton')
	}

	it('lists a previous version with both of its buttons', async () => {
		const wrapper = await openVersionPanel()

		expect(wrapper.find('.dossier-version-panel').exists()).toBe(true)
		expect(versionButtons(wrapper).map((b) => b.text())).toEqual([
			'Download',
			'Restore',
		])
	})

	it('restores a version by MOVEing it onto the restore target', async () => {
		const wrapper = await openVersionPanel()
		axios.request.mockClear()

		await versionButtons(wrapper)[1].trigger('click')
		await flushPromises()

		const move = axios.request.mock.calls
			.map(([config]) => config)
			.find((config) => config.method === 'MOVE')

		expect(move, 'Restore must issue the MOVE, not merely emit').toBeTruthy()
		expect(move.url).toBe(VERSION_HREF)
		expect(move.headers.Destination).toContain(
			'/remote.php/dav/versions/admin/restore/target',
		)
	})

	it('rereads the dossier after a restore, so the row shows the restored file', async () => {
		const wrapper = await openVersionPanel()
		const before = axios.get.mock.calls.length

		await versionButtons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(axios.get.mock.calls.length).toBeGreaterThan(before)
	})

	it('downloads a version by opening its own href', async () => {
		const wrapper = await openVersionPanel()
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)

		await versionButtons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(open).toHaveBeenCalledWith(VERSION_HREF, '_blank')
		open.mockRestore()
	})
})

/**
 * The Share action that told the user a share had been created.
 *
 * `shareDocument()` emitted `count-changed` and raised a success toast without
 * making any request at all. It is gone, and this is the guard that keeps it
 * gone: a toast is cheap to add back and impossible to notice.
 */
describe('DossierTab, the share action that shared nothing', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers no Share action on a document row', async () => {
		const wrapper = await mountTab()

		const actions = wrapper
			.findAll('.dossier-document-row .NcActionButton')
			.map((button) => button.text())

		expect(actions.length, 'the row must still offer its other actions')
			.toBeGreaterThan(0)
		expect(actions).not.toContain('Share')
	})

	it('raises no success toast that no request backs', async () => {
		const wrapper = await mountTab()
		expect(typeof wrapper.vm.shareDocument).toBe('undefined')
		expect(showSuccess).not.toHaveBeenCalled()
	})
})
