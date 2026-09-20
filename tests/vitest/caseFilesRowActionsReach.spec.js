// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three file acts are reachable, not merely registered.
 *
 * `registryOrphans.spec.js` proves the manifest NAMES each dialog, and
 * `versionHistoryPanel.spec.js` proves the panel works once it is open.
 * Neither proves a user can open it, and that gap is the whole defect these
 * rows record: both dialogs were imported, registered, unit tested and
 * unreachable for five days.
 *
 * So this mounts the REAL `CnFilesBrowser` from the pinned library, hands
 * it the `rowActions` the manifest declares for the `case-files` widget,
 * and asserts the three acts render as controls on a file row and dispatch
 * with the clicked node merged in. The component is imported by its source
 * path on purpose: `vitest.config.js` aliases the bare package name to a
 * stub, and a stub cannot answer whether the library renders a row action.
 *
 * @spec openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import CnFilesBrowser from '@conduction/nextcloud-vue/src/components/CnFilesBrowser/CnFilesBrowser.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/**
 * The `case-files` widget's declared row actions, read from the manifest
 * rather than restated, so a manifest edit is what this test reacts to.
 *
 * @return {Array<object>} The declared row actions.
 */
function declaredRowActions() {
	const found = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node === null || typeof node !== 'object') {
			return
		}
		if (node.id === 'case-files' && node.props?.rowActions) {
			found.push(node.props.rowActions)
		}
		Object.values(node).forEach(walk)
	}
	walk(manifest)
	return found.length === 1 ? found[0] : []
}

/** One file node, shaped the way the browser's own list shapes one. */
const NODE = {
	fileid: 4711,
	basename: 'besluit.pdf',
	path: '/Zaken/ZAAK-1/besluit.pdf',
	type: 'file',
	mime: 'application/pdf',
	size: 1024,
	mtime: new Date('2026-09-01T10:00:00Z').getTime(),
}

/**
 * Mount the real browser with the manifest's row actions and one file, and
 * capture what a click dispatches.
 *
 * @return {object} The wrapper and the dispatch spy.
 */
function mountBrowser() {
	const dispatched = []
	const wrapper = mount(CnFilesBrowser, {
		props: {
			folderPath: '/Zaken/ZAAK-1',
			rowActions: declaredRowActions(),
		},
		global: {
			provide: {
				cnDispatchAction: (action) => dispatched.push(action),
			},
			stubs: {
				NcBreadcrumbs: true,
				NcBreadcrumb: true,
				NcEmptyContent: true,
				NcLoadingIcon: true,
				NcActions: { template: '<div><slot /></div>' },
				NcActionSeparator: true,
				// `v-bind="$attrs"` alone: in Vue 3 the parent's `@click` arrives
				// as `onClick` in `$attrs`, so re-emitting would fire it twice.
				NcActionButton: {
					props: ['closeAfterClick'],
					template: '<button v-bind="$attrs"><slot /></button>',
				},
				NcButton: { template: '<button><slot /></button>' },
				NcModal: true,
				NcDialog: true,
				NcTextField: true,
				NcCheckboxRadioSwitch: true,
				CnIcon: true,
			},
		},
	})
	return { wrapper, dispatched }
}

describe('case-files — the three acts are controls a handler can click', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('declares the three acts in the manifest', () => {
		// The fixture for everything below. A manifest that declares none of
		// them would otherwise make every assertion here vacuously true.
		const ids = declaredRowActions().map((action) => action.id)
		expect(ids).toContain('versions')
		expect(ids).toContain('mark-final')
		expect(ids).toContain('change-confidentiality')
	})

	it('renders each declared act as a control on a file row', async () => {
		const { wrapper } = mountBrowser()
		wrapper.vm.nodes = [NODE]
		wrapper.vm.loading = false
		await wrapper.vm.$nextTick()

		for (const id of ['versions', 'mark-final', 'change-confidentiality']) {
			expect(
				wrapper.find(`[data-testid="cn-files-browser-host-action-${id}"]`).exists(),
				`The \`${id}\` row action is declared in src/manifest.json and the `
					+ 'files browser renders no control for it. A declared act nobody '
					+ 'can click is the state row 4.3 was found in.',
			).toBe(true)
		}
	})

	it('opens the version panel on the file the row named', async () => {
		const { wrapper, dispatched } = mountBrowser()
		wrapper.vm.nodes = [NODE]
		wrapper.vm.loading = false
		await wrapper.vm.$nextTick()

		await wrapper
			.find('[data-testid="cn-files-browser-host-action-versions"]')
			.trigger('click')

		expect(dispatched).toHaveLength(1)
		expect(dispatched[0].type).toBe('open-modal')
		expect(dispatched[0].target).toBe('VersionHistoryPanel')
		// The clicked node, merged in by the library. Without this the panel
		// opens on nothing and renders its refusal.
		expect(dispatched[0].props.fileId).toBe(4711)
		expect(dispatched[0].props.fileName).toBe('besluit.pdf')
	})

	it('opens the bulk dialog in the mode the row action names', async () => {
		const { wrapper, dispatched } = mountBrowser()
		wrapper.vm.nodes = [NODE]
		wrapper.vm.loading = false
		await wrapper.vm.$nextTick()

		await wrapper
			.find('[data-testid="cn-files-browser-host-action-mark-final"]')
			.trigger('click')
		await wrapper
			.find('[data-testid="cn-files-browser-host-action-change-confidentiality"]')
			.trigger('click')

		expect(dispatched.map((action) => action.target)).toEqual([
			'BulkDocumentActionDialog',
			'BulkDocumentActionDialog',
		])
		expect(dispatched.map((action) => action.props.mode)).toEqual([
			'mark-final',
			'confidentiality',
		])
		expect(dispatched.every((action) => action.props.fileId === 4711)).toBe(true)
	})
})
