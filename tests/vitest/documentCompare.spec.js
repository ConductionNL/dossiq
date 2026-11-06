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
 * @spec openspec/specs/document-compare/spec.md#requirement-an-original-and-the-file-that-went-out-are-compared-side-by-side-req-dcp-001
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

describe('the items of a delivered set', () => {
	// The table, the Compare button and its accessible name are the library's
	// field-list widget (tests/components/CnFieldListDialogAction.spec.js runs
	// this very configuration). What dossiq owns is the declaration and the
	// modal it names.
	const root = path.resolve(__dirname, '../..')
	const manifest = JSON.parse(
		fs.readFileSync(path.join(root, 'src', 'manifest.json'), 'utf8'),
	)
	const registry = fs.readFileSync(path.join(root, 'src', 'registry.js'), 'utf8')
	const page = manifest.pages.find((p) => p.id === 'WooDeliveredSetDetail')
	const widget = page.config.widgets.find((w) => w.id === 'woo-delivered-set-items')

	it('lists the set\'s own items, read only, with the columns the record shows', () => {
		expect(widget.type).toBe('field-list')
		expect(widget.content).toMatchObject({ field: 'items', variant: 'table', readOnly: true, emptyText: 'This set has no items.' })
		expect(widget.content.columns.map((c) => c.path)).toEqual(['fileName', 'classification', 'sha256'])
		expect(widget.content.columns[1].map).toEqual({ openbaar: 'Public', deels_openbaar: 'Disclosed in part' })
		expect(page.config.layout.some((l) => l.widgetId === widget.id)).toBe(true)
	})

	it('offers Compare only on an item whose file that went out is not its original', () => {
		const [compare] = widget.content.rowActions
		expect(compare).toMatchObject({ id: 'compare', label: 'Compare', placement: 'inline', dialog: 'DocumentCompareDialog' })
		expect(compare.when).toEqual({ deliveredRef: { set: true, neq: '@row.originalRef' } })
		expect(compare.ariaLabel).toBe('Compare {fileName} with the original')
	})

	it('opens the compare dialog on that item of that set of that case', () => {
		const [compare] = widget.content.rowActions
		expect(compare.props.filesUrl).toBe(
			'/apps/dossiq/api/cases/@object.case/woo/delivered-sets/@objectId/items/@index',
		)
		expect(registry).toMatch(/DocumentCompareDialog: \{\s*kind: 'modal',\s*component: DocumentCompareDialog/)
	})
})
