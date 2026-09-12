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
 * @spec openspec/changes/object-list-widget-grouping-select-facet/specs/cn-workspace-context-widgets/spec.md#requirement-cnobjectlistwidget-supports-multi-select-and-bulk-actions
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockPost = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'inputLabel', 'label', 'options', 'reduce', 'clearable', 'disabled', 'canClose', 'name', 'type'],
		emits: ['update:modelValue', 'closing'],
		render() {
			return h('div', { class: name }, this.$slots.default?.() ?? this.$slots.actions?.() ?? [])
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
	mockEmit.mockReset()
	mockPost.mockResolvedValue({ data: { results: [{ success: true }, { success: true }] } })
	// jsdom has no createObjectURL/revokeObjectURL by default.
	window.URL.createObjectURL = vi.fn(() => 'blob:mock')
	window.URL.revokeObjectURL = vi.fn()
})

describe('BulkDocumentActionDialog — mark-final', () => {
	it('POSTs the selected ids with status final', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'mark-final', selectedIds: ['doc-1', 'doc-2'] },
		})

		await wrapper.vm.onConfirm()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith(
			expect.stringContaining('bulk/status'),
			{ ids: ['doc-1', 'doc-2'], status: 'final' },
		)
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
	})
})

describe('BulkDocumentActionDialog — confidentiality', () => {
	it('POSTs the chosen level as metadata.vertrouwelijkheidaanduiding', async () => {
		const wrapper = mount(BulkDocumentActionDialog, {
			props: { mode: 'confidentiality', selectedIds: ['doc-1'] },
		})
		await wrapper.setData({ level: 'geheim' })

		await wrapper.vm.onConfirm()

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
			props: { mode: 'zip', selectedIds: ['doc-1', 'doc-2'], caseId: 'case-9' },
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
