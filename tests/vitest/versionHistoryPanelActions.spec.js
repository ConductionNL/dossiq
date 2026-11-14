// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * VersionHistoryPanel actions: download, Show in Files, close, failed restore
 * and the date shown for a version.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T07
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockRequest = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { request: (...a) => mockRequest(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateRemoteUrl: (u) => u,
	generateUrl: (u) => u,
}))
const mockShowError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: (...a) => mockShowError(...a) }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin' }) }))

function control(name) {
	return defineComponent({
		name,
		props: ['size', 'disabled', 'title', 'type', 'name', 'description'],
		emits: ['close'],
		render() {
			// `name` and `description` are RENDERED, because the refusal this
			// panel shows lives entirely in those two props: a stub that drops
			// them makes "it says which file it could not find" unassertable.
			return h('div', { class: name }, [
				this.name ? h('span', { class: 'stub-name' }, this.name) : null,
				this.description
					? h('span', { class: 'stub-description' }, this.description)
					: null,
				...(this.$slots.default?.() ?? this.$slots.icon?.() ?? []),
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcEmptyContent: control('NcEmptyContent'),
	NcLoadingIcon: control('NcLoadingIcon'),
	NcModal: control('NcModal'),
}))
vi.mock('vue-material-design-icons/History.vue', () => ({
	default: control('History'),
}))

const { default: VersionHistoryPanel } =
	await import('../../src/modals/VersionHistoryPanel.vue')

const PROPFIND_XML = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
	<d:response><d:href>/dav/versions/admin/versions/42/</d:href></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v1</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 01 Sep 2026 10:00:00 GMT</d:getlastmodified></d:prop></d:propstat></d:response>
</d:multistatus>`

const row = { informatieobject: { fileId: 42, status: 'draft' } }

beforeEach(() => {
	mockRequest.mockReset()
	mockEmit.mockReset()
	mockShowError.mockReset()
	mockRequest.mockResolvedValue({ data: PROPFIND_XML })
	vi.restoreAllMocks()
})

describe('VersionHistoryPanel actions', () => {
	it('opens a version\'s own DAV href in a new tab to download it', async () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()

		const download = wrapper.findAll('.NcButton').find((b) => b.text().includes('Download'))
		await download.trigger('click')

		expect(open).toHaveBeenCalledTimes(1)
		expect(open).toHaveBeenCalledWith('/dav/versions/admin/versions/42/v1', '_blank')
	})

	it('downloads nothing for a version without an href', () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(VersionHistoryPanel, { props: { open: false, row } })

		wrapper.vm.downloadVersion({ id: '' })
		wrapper.vm.downloadVersion(null)

		expect(open).not.toHaveBeenCalled()
	})

	it('offers the Files app when it has no file to read, opened without an opener', async () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(VersionHistoryPanel, { props: { open: true } })
		await flushPromises()

		// The refusal's button sits in NcEmptyContent's #action slot, which the stub does not render.
		expect(wrapper.find('.stub-name').text()).toBe('No file to read versions of')
		wrapper.vm.showInFiles()

		expect(mockRequest).not.toHaveBeenCalled()
		expect(open).toHaveBeenCalledWith('/apps/files', '_blank', 'noopener')
	})

	it('passes the modal\'s close on to its caller', async () => {
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()

		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('reports a failed restore and leaves the page alone', async () => {
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()
		mockRequest.mockRejectedValueOnce(new Error('423 Locked'))

		await wrapper.vm.restoreVersion(wrapper.vm.versions[0])
		await flushPromises()

		expect(mockShowError).toHaveBeenCalledWith('Could not restore this version')
		expect(mockEmit).not.toHaveBeenCalled()
	})

	it('shows a dash for no date, an unreadable date as sent, and a readable one in Dutch', () => {
		const wrapper = mount(VersionHistoryPanel, { props: { open: false, row } })
		const moment = 'Mon, 01 Sep 2026 10:00:00 GMT'

		expect(wrapper.vm.formatDate('')).toBe('---')
		expect(wrapper.vm.formatDate('gisteren')).toBe('gisteren')
		expect(wrapper.vm.formatDate(moment)).toBe(new Date(moment).toLocaleString('nl-NL'))
	})
})
