// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * VersionHistoryPanel: self-sufficient version-history modal.
 *
 * TWO CALLERS NOW (document-acts-reach-a-surface REQ-ZAK-020). The
 * `case-files` leaf opens it as an `open-modal` row action and CnFilesBrowser
 * merges the clicked node's `fileId` and `fileName` onto the props; the older
 * `props.row` path (the zaakinformatieobject row with `informatieobject`
 * inlined, merged by CnObjectListWidget, nextcloud-vue#1117) is still read,
 * second. The Documents tab that used the second path was retired on
 * 2026-09-13 and the panel had NO caller at all between then and this change.
 *
 * Handed neither prop, it refuses by name rather than rendering the empty
 * version list, because "no versions" and "no file" are different sentences.
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
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
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

/** A PROPFIND multistatus body with two version entries plus the live node. */
const PROPFIND_XML = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
	<d:response><d:href>/dav/versions/admin/versions/42/</d:href></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v1</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 01 Sep 2026 10:00:00 GMT</d:getlastmodified></d:prop></d:propstat></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v2</d:href><d:propstat><d:prop><d:getlastmodified>Tue, 02 Sep 2026 10:00:00 GMT</d:getlastmodified></d:prop></d:propstat></d:response>
</d:multistatus>`

/**
 * The same body with the two properties a real Nextcloud sends beside the
 * moment: the author, in Nextcloud's own namespace rather than DAV:, and
 * the byte count.
 */
const FULL_PROPFIND_XML = `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
	<d:response><d:href>/dav/versions/admin/versions/42/</d:href></d:response>
	<d:response><d:href>/dav/versions/admin/versions/42/v1</d:href><d:propstat><d:prop><d:getlastmodified>Mon, 01 Sep 2026 10:00:00 GMT</d:getlastmodified><d:getcontentlength>2048</d:getcontentlength><nc:version-author>bkeeper</nc:version-author></d:prop></d:propstat></d:response>
</d:multistatus>`

beforeEach(() => {
	mockRequest.mockReset()
	mockEmit.mockReset()
	mockRequest.mockResolvedValue({ data: PROPFIND_XML })
})

describe('VersionHistoryPanel', () => {
	it('reads the document off row.informatieobject, not a document prop', async () => {
		const row = {
			id: 'zio-1',
			informatieobject: { fileId: 42, status: 'draft' },
		}
		const wrapper = mount(VersionHistoryPanel, { props: { open: true, row } })
		await flushPromises()

		expect(mockRequest).toHaveBeenCalledWith(
			expect.objectContaining({
				method: 'PROPFIND',
				url: expect.stringContaining('/versions/42'),
			}),
		)
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
			props: {
				open: true,
				row: { informatieobject: { fileId: 1, status: 'draft' } },
			},
		})
		await flushPromises()
		expect(draft.vm.restoreDisabled).toBe(false)

		const final = mount(VersionHistoryPanel, {
			props: {
				open: true,
				row: { informatieobject: { fileId: 1, status: 'final' } },
			},
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

		expect(mockRequest).toHaveBeenCalledWith(
			expect.objectContaining({
				method: 'MOVE',
				url: '/dav/versions/admin/versions/42/v1',
			}),
		)
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
	})

	it('renders no modal at all when open is false', () => {
		const wrapper = mount(VersionHistoryPanel, {
			props: {
				open: false,
				row: { informatieobject: { fileId: 1, status: 'draft' } },
			},
		})
		expect(wrapper.find('.NcModal').exists()).toBe(false)
	})
})

describe('VersionHistoryPanel handed a file id', () => {
	it('reads the versions of the fileId the files browser clicked', async () => {
		// The fixture is keyed on 77 so the live-node exclusion still fires:
		// against the 42 fixture the count would read 3 and hide which file
		// the panel actually asked for.
		mockRequest.mockResolvedValue({
			data: PROPFIND_XML.replace(/versions\/42/g, 'versions/77'),
		})
		const wrapper = mount(VersionHistoryPanel, {
			props: { open: true, fileId: 77, fileName: 'besluit.pdf' },
		})
		await flushPromises()

		expect(mockRequest).toHaveBeenCalledWith(
			expect.objectContaining({
				method: 'PROPFIND',
				url: expect.stringContaining('/versions/77'),
			}),
		)
		expect(wrapper.vm.versions).toHaveLength(2)
	})

	it('prefers the fileId prop over a row that names another file', async () => {
		// Both callers can be present on an instance mid-migration. The node
		// the reader clicked is the one they meant, so the prop wins.
		const wrapper = mount(VersionHistoryPanel, {
			props: {
				open: true,
				fileId: 77,
				row: { informatieobject: { fileId: 42, status: 'draft' } },
			},
		})
		await flushPromises()

		expect(wrapper.vm.resolvedFileId).toBe(77)
	})

	it('refuses by name when it is handed neither a file id nor a row', async () => {
		const wrapper = mount(VersionHistoryPanel, {
			props: { open: true, fileName: 'besluit.pdf' },
		})
		await flushPromises()

		// The REFUSAL, not the empty list: no PROPFIND went out at all, the
		// panel says which file it could not find, and the "No previous
		// versions" sentence is NOT what the reader is shown.
		// The RENDERED sentence is asserted first and the computed second, so a
		// mutation that keeps the flag and drops the sentence still reddens
		// here rather than passing on a boolean nobody reads.
		expect(wrapper.text()).toContain('No file to read versions of')
		expect(wrapper.text()).toContain('besluit.pdf')
		expect(wrapper.text()).not.toContain('No previous versions')
		expect(mockRequest).not.toHaveBeenCalled()
		expect(wrapper.vm.hasNoFile).toBe(true)
	})

	// REQ-ZAK-020 asks the history to list each version with its moment, its
	// AUTHOR and its SIZE. Two of the three were never read: `author` was the
	// literal `''` in the parser, so the template's `|| 'Unknown'` fallback
	// rendered on every version of every document, and no size was parsed at
	// all. A field that always renders its fallback looks exactly like a
	// server that sent nothing.
	it('reads the author and the size the server sent', async () => {
		mockRequest.mockResolvedValue({ data: FULL_PROPFIND_XML })
		const wrapper = mount(VersionHistoryPanel, {
			props: { open: true, fileId: 42 },
		})
		await flushPromises()

		expect(wrapper.vm.versions[0].author).toBe('bkeeper')
		expect(wrapper.vm.versions[0].size).toBe(2048)
		// What a reader actually sees, asserted beside the parsed value: a
		// parser that reads the author into a field no template renders is
		// the same silence in a different place.
		expect(wrapper.text()).toContain('bkeeper')
		expect(wrapper.text()).toContain('2 KB')
		expect(wrapper.text()).not.toContain('Unknown')
	})

	it('says Unknown only when the server really sent no author', async () => {
		// The control for the assertion above: with the original fixture,
		// which carries neither property, the fallback is correct and the
		// meta line carries no size at all rather than `0 B`.
		const wrapper = mount(VersionHistoryPanel, {
			props: { open: true, fileId: 42 },
		})
		await flushPromises()

		expect(wrapper.vm.versions[0].size).toBeNull()
		expect(wrapper.text()).toContain('Unknown')
		expect(wrapper.text()).not.toContain('0 B')
	})

	it('scales a byte count to the unit a reader can hold', () => {
		const wrapper = mount(VersionHistoryPanel, { props: { open: false } })
		expect(wrapper.vm.formatSize(512)).toBe('512 B')
		expect(wrapper.vm.formatSize(2048)).toBe('2 KB')
		expect(wrapper.vm.formatSize(1024 * 1024 * 3.5)).toBe('3.5 MB')
		// An unsent size is not a zero-byte file.
		expect(wrapper.vm.formatSize(null)).toBe('')
		expect(wrapper.vm.formatSize(-1)).toBe('')
	})
})
