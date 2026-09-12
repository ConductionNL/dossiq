// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * VersionHistoryPanel: self-sufficient version-history modal opened by the
 * Documents-tab object-list's Versions row action. It reads the document off
 * `row.informatieobject` (the extended reference), not a `document` prop a
 * parent DossierTab used to pass down directly — CnObjectListWidget merges
 * `props.row` for an open-modal row action (nextcloud-vue#1117).
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-cnobjectlistwidget-supports-multi-select-and-bulk-actions
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockRequest = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { request: (...a) => mockRequest(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin' }) }))

function control(name) {
	return defineComponent({
		name,
		props: ['size', 'disabled', 'title', 'type', 'name'],
		emits: ['close'],
		render() {
			return h('div', { class: name }, this.$slots.default?.() ?? this.$slots.icon?.() ?? [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcEmptyContent: control('NcEmptyContent'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcModal: control('NcModal'),
}))
vi.mock('vue-material-design-icons/History.vue', () => ({ default: control('History') }))

const { default: VersionHistoryPanel } =
	await import('../../src/views/cases/components/VersionHistoryPanel.vue')

/** A PROPFIND multistatus body with two version entries plus the live node. */
const PROPFIND_XML = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
	<d:response><d:href>/dav/versions/admin/versions/42/</d:href></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v1</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 01 Sep 2026 10:00:00 GMT</d:getlastmodified></d:prop></d:propstat></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v2</d:href><d:propstat><d:prop><d:getlastmodified>Tue, 02 Sep 2026 10:00:00 GMT</d:getlastmodified></d:prop></d:propstat></d:response>
</d:multistatus>`

beforeEach(() => {
	mockRequest.mockReset()
	mockEmit.mockReset()
	mockRequest.mockResolvedValue({ data: PROPFIND_XML })
})

describe('VersionHistoryPanel', () => {
	it('reads the document off row.informatieobject, not a document prop', async () => {
		const row = { id: 'zio-1', informatieobject: { fileId: 42, status: 'draft' } }
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()

		expect(mockRequest).toHaveBeenCalledWith(expect.objectContaining({
			method: 'PROPFIND',
			url: expect.stringContaining('/versions/42'),
		}))
		expect(wrapper.vm.versions).toHaveLength(2)
	})

	it('excludes the live node itself from the parsed version list', async () => {
		const row = { informatieobject: { fileId: 42, status: 'draft' } }
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()

		// MUTATION CHECK (red half): dropping the href-suffix exclusion would
		// count the live node as a THIRD version — pinning the count at 2
		// (not >=2) is what catches that.
		expect(wrapper.vm.versions).toHaveLength(2)
	})

	it('disables restore for a final document, not for a draft one', async () => {
		const draft = mount(VersionHistoryPanel, {
			props: { open: true, row: { informatieobject: { fileId: 1, status: 'draft' } } },
		})
		await flushPromises()
		expect(draft.vm.restoreDisabled).toBe(false)

		const final = mount(VersionHistoryPanel, {
			props: { open: true, row: { informatieobject: { fileId: 1, status: 'final' } } },
		})
		await flushPromises()
		expect(final.vm.restoreDisabled).toBe(true)
	})

	it('restores a version via MOVE and refreshes the page and its own list', async () => {
		const row = { informatieobject: { fileId: 42, status: 'draft' } }
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()
		mockRequest.mockClear()
		mockRequest.mockResolvedValueOnce({ data: {} }) // the MOVE
		mockRequest.mockResolvedValueOnce({ data: PROPFIND_XML }) // the refetch

		await wrapper.vm.restoreVersion({ id: '/dav/versions/admin/versions/42/v1' })

		expect(mockRequest).toHaveBeenCalledWith(expect.objectContaining({
			method: 'MOVE',
			url: '/dav/versions/admin/versions/42/v1',
		}))
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
	})

	it('renders no modal at all when open is false', () => {
		const wrapper = mount(VersionHistoryPanel, {
			props: { open: false, row: { informatieobject: { fileId: 1, status: 'draft' } } },
		})
		expect(wrapper.find('.NcModal').exists()).toBe(false)
	})
})
