// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The generic document compare (decision 182, document-compare REQ-DCP-001),
 * configured by the Woo case type on its delivered set page
 * (woo-delivered-set-is-a-record REQ-WDS-004). The file set items widget
 * offers Compare on an item whose file that went out is not its original, and
 * only when the case type configured where the pair is read. The dialog mounts
 * filinq's split view with both files; without filinq it says so and offers
 * the two files as links instead of drawing a comparison.
 *
 * Contract with filinq (anonymization-review-workbench REQ-DDARW-014):
 * `mountCompare(el, { original, delivered, labels })`, each file
 * `{ fileName, mimeType, url }`, returning `{ unmount() }`; no events.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		String(url).replace(/\{(\w+)\}/g, (_, key) =>
			String(params?.[key] ?? `{${key}}`),
		),
	generateFilePath: (app, type, file) => `/apps/${app}/${type}/${file}`,
}))

/**
 * A stand-in for one @nextcloud/vue control that renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['name', 'size', 'type', 'variant', 'ariaLabel'],
		emits: ['click', 'closing'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
}

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: control('NcButton'),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: control('NcDialog'),
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: control('NcLoadingIcon'),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({
	default: control('NcNoteCard'),
}))

const { default: DocumentCompareDialog } =
	await import('../../src/dialogs/DocumentCompareDialog.vue')
const { default: FileSetItems } =
	await import('../../src/components/fileSet/FileSetItems.vue')
const { isFilinqInstalled, loadFilinqCompare, resetFilinqCompare } =
	await import('../../src/utils/filinqCompare.js')

const ITEM = {
	original: {
		fileName: 'besluit.docx',
		mimeType:
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		readable: true,
	},
	delivered: {
		fileName: 'besluit-gelakt.pdf',
		mimeType: 'application/pdf',
		readable: true,
	},
}
const BASE = '/apps/dossiq/api/cases/case-1/woo/delivered-sets/set-1/items/0'

beforeEach(() => {
	mockGet.mockReset()
	mockGet.mockResolvedValue({ data: ITEM })
	resetFilinqCompare()
	delete window.OCA
	window.OC = { appswebroots: {} }
})

afterEach(() => {
	document.head.querySelectorAll('script').forEach((s) => s.remove())
})

/**
 * Mount the dialog for the pair at BASE.
 *
 * @return {object} The wrapper.
 */
function dialog() {
	return mount(DocumentCompareDialog, {
		props: { filesUrl: BASE },
		attachTo: document.body,
	})
}

describe('DocumentCompareDialog', () => {
	it('testWithoutTheViewerItSaysSo', async () => {
		const wrapper = dialog()
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(BASE)
		expect(
			wrapper.find('[data-testid="document-compare-needs-filinq"]').text(),
		).toContain('The compare view needs Filinq')
		const links = wrapper
			.findAll('[data-testid="document-compare-links"] a')
			.map((a) => a.attributes('href'))
		expect(links).toEqual([BASE + '/original', BASE + '/delivered'])
		expect(
			wrapper.find('[data-testid="document-compare-view"]').isVisible(),
		).toBe(false)
		expect(document.head.querySelector('script')).toBeNull()
	})

	it('testItPassesBothFilesToTheViewer', async () => {
		const unmount = vi.fn()
		const mountCompare = vi.fn(() => ({ unmount }))
		window.OC.appswebroots = { filinq: '/apps/filinq' }
		window.OCA = { Filinq: { mountCompare } }

		const wrapper = dialog()
		await flushPromises()

		expect(mountCompare).toHaveBeenCalledTimes(1)
		const [el, options] = mountCompare.mock.calls[0]
		expect(el).toBe(
			wrapper.find('[data-testid="document-compare-view"]').element,
		)
		expect(options).toEqual({
			original: {
				fileName: 'besluit.docx',
				mimeType: ITEM.original.mimeType,
				url: BASE + '/original',
			},
			delivered: {
				fileName: 'besluit-gelakt.pdf',
				mimeType: 'application/pdf',
				url: BASE + '/delivered',
			},
			labels: { original: 'Original', delivered: 'Delivered' },
		})
		expect(
			wrapper.find('[data-testid="document-compare-needs-filinq"]').exists(),
		).toBe(false)

		wrapper.unmount()
		expect(unmount).toHaveBeenCalledTimes(1)
	})

	it("uses the host's pane titles when it passes them", async () => {
		const mountCompare = vi.fn(() => ({ unmount: vi.fn() }))
		window.OC.appswebroots = { filinq: '/apps/filinq' }
		window.OCA = { Filinq: { mountCompare } }

		mount(DocumentCompareDialog, {
			props: { filesUrl: BASE, labels: { delivered: 'Gepubliceerd' } },
		})
		await flushPromises()

		expect(mountCompare.mock.calls[0][1].labels).toEqual({
			original: 'Original',
			delivered: 'Gepubliceerd',
		})
	})

	it('falls back to the links when filinq refuses the call', async () => {
		window.OC.appswebroots = { filinq: '/apps/filinq' }
		window.OCA = {
			Filinq: {
				mountCompare: () => {
					throw new TypeError('nope')
				},
			},
		}

		const wrapper = dialog()
		await flushPromises()

		expect(
			wrapper.find('[data-testid="document-compare-needs-filinq"]').exists(),
		).toBe(true)
	})

	it('says so when the pair cannot be read, and shows no links', async () => {
		mockGet.mockRejectedValue(new Error('403'))

		const wrapper = dialog()
		await flushPromises()

		expect(wrapper.find('[data-testid="document-compare-error"]').text()).toBe(
			'The files of this item could not be read.',
		)
		expect(wrapper.find('[data-testid="document-compare-links"]').exists()).toBe(
			false,
		)
	})
})

describe('loadFilinqCompare', () => {
	it('answers null without filinq and loads nothing', async () => {
		expect(isFilinqInstalled({})).toBe(false)
		expect(await loadFilinqCompare({ roots: {} })).toBeNull()
		expect(document.head.querySelector('script')).toBeNull()
	})

	it("loads filinq's compare bundle once and answers its mountCompare", async () => {
		const roots = { filinq: '/apps/filinq' }
		const first = loadFilinqCompare({ roots })
		const second = loadFilinqCompare({ roots })
		const scripts = document.head.querySelectorAll('script')
		expect(scripts).toHaveLength(1)
		expect(scripts[0].getAttribute('src')).toBe(
			'/apps/filinq/js/filinq-compare.js',
		)

		const mountCompare = vi.fn()
		window.OCA = { Filinq: { mountCompare } }
		scripts[0].onload()
		expect(await first).toBe(mountCompare)
		expect(await second).toBe(mountCompare)
	})

	it('answers null when the bundle fails, and tries again next time', async () => {
		const roots = { filinq: '/apps/filinq' }
		const first = loadFilinqCompare({ roots })
		document.head.querySelector('script').onerror()
		expect(await first).toBeNull()

		loadFilinqCompare({ roots })
		expect(document.head.querySelectorAll('script')).toHaveLength(2)
	})
})

describe('FileSetItems', () => {
	const content = {
		filesUrl:
			'/apps/dossiq/api/cases/{case}/woo/delivered-sets/{set}/items/{index}',
		classificationLabels: {
			openbaar: 'Public',
			deels_openbaar: 'Disclosed in part',
		},
	}
	const objectData = {
		case: 'case-1',
		items: [
			{
				fileName: 'besluit-gelakt.pdf',
				classification: 'deels_openbaar',
				deliveredRef: 'doc-red',
				originalRef: 'doc-orig',
				sha256: 'a'.repeat(64),
			},
			{
				fileName: 'nota.pdf',
				classification: 'openbaar',
				deliveredRef: 'doc-2',
				originalRef: 'doc-2',
				sha256: 'b'.repeat(64),
			},
		],
	}

	it('lists every item and offers Compare on the redacted one only', () => {
		const wrapper = mount(FileSetItems, {
			props: { objectId: 'set-1', objectData, content },
		})

		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(wrapper.find('[data-testid="file-set-item-0"]').text()).toContain(
			'Disclosed in part',
		)
		expect(wrapper.find('[data-testid="file-set-compare-0"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="file-set-compare-1"]').exists()).toBe(
			false,
		)
	})

	it('offers no Compare when the case type configured no files URL', () => {
		const wrapper = mount(FileSetItems, {
			props: { objectId: 'set-1', objectData, content: {} },
		})

		expect(wrapper.find('[data-testid="file-set-compare-0"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="file-set-item-0"]').text()).toContain(
			'deels_openbaar',
		)
	})

	it('opens the compare dialog on that item with the configured URL and closes it again', async () => {
		const wrapper = mount(FileSetItems, {
			props: { objectId: 'set-1', objectData, content },
		})

		await wrapper.find('[data-testid="file-set-compare-0"]').trigger('click')
		const opened = wrapper.findComponent(DocumentCompareDialog)
		expect(opened.props('filesUrl')).toBe(BASE)

		opened.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent(DocumentCompareDialog).exists()).toBe(false)
	})

	it('is registered, and the Woo case type configures it on the set detail page', () => {
		const root = path.resolve(__dirname, '../..')
		const registry = fs.readFileSync(
			path.join(root, 'src', 'registry.js'),
			'utf8',
		)
		const manifest = JSON.parse(
			fs.readFileSync(path.join(root, 'src', 'manifest.json'), 'utf8'),
		)
		expect(registry).toMatch(
			/'file-set-items': \{[\s\S]*?component: FileSetItems/,
		)
		const page = manifest.pages.find((p) => p.id === 'WooDeliveredSetDetail')
		const widget = page.config.widgets.find((w) => w.type === 'file-set-items')
		expect(widget.content.filesUrl).toContain('{index}')
		expect(page.config.layout.some((l) => l.widgetId === widget.id)).toBe(true)
	})
})
