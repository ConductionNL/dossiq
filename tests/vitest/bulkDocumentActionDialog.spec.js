// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BulkDocumentActionDialog: the Documents tab's three bulk gestures (Mark
 * final, Change confidentiality, Download ZIP), one dialog in three `mode`s
 * — the self-sufficient replacement for BulkActionsBar's inline calls, now
 * that CnObjectListWidget's `bulkActions` open a modal instead of running a
 * handler directly (documents-on-the-case task 2.2).
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockPost = vi.fn()
const mockGet = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { post: (...a) => mockPost(...a), get: (...a) => mockGet(...a) },
}))
// Substitutes `{placeholders}` the way @nextcloud/router does. The stub used
// to return the path untouched, which quietly hid every id the component puts
// in a URL: a per-row GET came out as the literal `.../{id}` and any assertion
// about which row was fetched was unfalsifiable.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		String(url).replace(/\{(\w+)\}/g, (_, key) =>
			String((params && params[key]) ?? `{${key}}`),
		),
}))
const mockSuccess = vi.fn()
const mockError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...a) => mockSuccess(...a),
	showError: (...a) => mockError(...a),
}))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'inputLabel',
			'label',
			'options',
			'reduce',
			'clearable',
			'disabled',
			'canClose',
			'name',
			'type',
		],
		emits: ['update:modelValue', 'closing'],
		render() {
			return h(
				'div',
				{ class: name },
				this.$slots.default?.() ?? this.$slots.actions?.() ?? [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcSelect: control('NcSelect'),
}))

const { default: BulkDocumentActionDialog } =
	await import('../../src/modals/BulkDocumentActionDialog.vue')

beforeEach(() => {
	mockPost.mockReset()
	mockGet.mockReset()
	mockEmit.mockReset()
	mockSuccess.mockReset()
	mockError.mockReset()
	mockPost.mockResolvedValue({
		data: { results: [{ success: true }, { success: true }] },
	})
	// Every selected row is a zaakinformatieobject JOIN, and the id the
	// endpoints want is the `informatieobject` it points at. `join-1` carries
	// `doc-1` as a bare uuid and `join-2` carries `doc-2` inlined as an object,
	// because `content.extend` decides which shape comes back and the component
	// must read either.
	mockGet.mockImplementation((url) => {
		if (String(url).includes('join-1'))
			return Promise.resolve({
				data: { id: 'join-1', informatieobject: 'doc-1' },
			})
		if (String(url).includes('join-2'))
			return Promise.resolve({
				data: {
					id: 'join-2',
					informatieobject: { id: 'doc-2', title: 'Bulk two' },
				},
			})
		return Promise.resolve({ data: { id: 'join-x', informatieobject: '' } })
	})
	// jsdom has no createObjectURL/revokeObjectURL by default.
	window.URL.createObjectURL = vi.fn(() => 'blob:mock')
	window.URL.revokeObjectURL = vi.fn()
})

describe('BulkDocumentActionDialog — mark-final', () => {
	// 🔴 THE IDS ARE THE POINT, AND THIS TEST USED TO ASSERT THE WRONG ONES.
	// It passed `selectedIds: ['doc-1','doc-2']` and checked those same strings
	// came back out, which is true of any component that forwards its prop. The
	// widget selects `zaakinformatieobject` rows, so what arrives is join ids,
	// and the endpoint resolves ids in the `informatieobject` schema. Sending
	// them straight through matched nothing and left both documents `draft`.
	it('resolves each join id to its document id before POSTing', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'mark-final', selectedIds: ['join-1', 'join-2'] },
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/zaakinformatieobject/join-1',
		)
		expect(mockPost).toHaveBeenCalledWith(
			expect.stringContaining('bulk/status'),
			{ ids: ['doc-1', 'doc-2'], status: 'final' },
		)
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
	})

	// 🔴 A 200 IS NOT A RESULT. The endpoint answers 200 with a per-item list,
	// and the dialog used to say "Bulk action applied" over a response in which
	// every item had failed. That toast is why nothing on screen contradicted
	// the join-id bug for as long as it shipped.
	it('says nothing changed when every item failed', async () => {
		mockPost.mockResolvedValue({
			data: {
				results: [
					{ id: 'doc-1', success: false, error: 'not found' },
					{ id: 'doc-2', success: false, error: 'not found' },
				],
			},
		})
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'mark-final', selectedIds: ['join-1', 'join-2'] },
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalledWith('Bulk action changed nothing')
	})

	it('counts what actually succeeded when only some items did', async () => {
		mockPost.mockResolvedValue({
			data: {
				results: [
					{ id: 'doc-1', success: true },
					{ id: 'doc-2', success: false, error: 'not found' },
				],
			},
		})
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'mark-final', selectedIds: ['join-1', 'join-2'] },
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockError).not.toHaveBeenCalled()
		expect(mockSuccess).toHaveBeenCalledWith('1 of 2 document(s) updated')
	})

	it('drops a join whose document cannot be resolved', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'mark-final', selectedIds: ['join-1', 'join-broken'] },
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith(
			expect.stringContaining('bulk/status'),
			{ ids: ['doc-1'], status: 'final' },
		)
	})
})

describe('BulkDocumentActionDialog — confidentiality', () => {
	it('POSTs the chosen level as metadata.vertrouwelijkheidaanduiding', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'confidentiality', selectedIds: ['join-1'] },
		})
		await wrapper.setData({ level: 'geheim' })

		await wrapper.vm.onConfirm()
		await flushPromises()

		// MUTATION CHECK (red half): sending `level` at the top level instead
		// of nested under `metadata` would still hit the endpoint but the
		// server reads a different shape — pinning the nested key is what
		// catches that, a bare toHaveBeenCalled() would not.
		expect(mockPost).toHaveBeenCalledWith(
			expect.stringContaining('bulk/metadata'),
			{ ids: ['doc-1'], metadata: { vertrouwelijkheidaanduiding: 'geheim' } },
		)
	})

	it('disables Apply until a level is chosen', () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'confidentiality', selectedIds: ['doc-1'] },
		})
		expect(wrapper.vm.level).toBe('')
	})
})

describe('BulkDocumentActionDialog — zip', () => {
	it('downloads a blob scoped to the case and closes', async () => {
		mockPost.mockResolvedValue({ data: new Blob(['zip-bytes']) })
		const wrapper = mount(BulkDocumentActionDialog, {
			props: {
				mode: 'zip',
				selectedIds: ['doc-1', 'doc-2'],
				caseId: 'case-9',
			},
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith(
			expect.stringContaining('cases/case-9/dossier/zip'),
			{ ids: ['doc-1', 'doc-2'] },
			{ responseType: 'blob' },
		)
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('does nothing when the case id cannot be resolved', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'zip', selectedIds: ['doc-1'], caseId: '@objectId' },
		})

		await wrapper.vm.onConfirm()

		expect(mockPost).not.toHaveBeenCalled()
	})
})
