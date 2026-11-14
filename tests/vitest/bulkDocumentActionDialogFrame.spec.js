// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BulkDocumentActionDialog's frame: the confirm label each mode shows, the
 * two ways the dialog closes, and the case search before anything is typed
 * or when it fails.
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
	mockError.mockReset()
})

describe('BulkDocumentActionDialog frame', () => {
	it.each([
		['zip', 'Download'],
		['copy-to-case', 'Copy'],
		['merge-pdf', 'Merge'],
		['mark-final', 'Apply'],
		['confidentiality', 'Apply'],
	])('labels the confirm button for %s as %s', (mode, label) => {
		const wrapper = mount(BulkDocumentActionDialog, { props: { mode, selectedIds: ['join-1'], caseId: 'case-1' } })

		// The stubbed NcDialog renders its default slot only, so the #actions buttons are read off the component.
		expect(wrapper.vm.confirmLabel).toBe(label)
	})

	it('closes from its Cancel handler and from the dialog\'s own close', () => {
		const wrapper = mount(BulkDocumentActionDialog, { props: { mode: 'zip', selectedIds: ['join-1'], caseId: 'case-1' } })

		wrapper.vm.onClose()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('clears the case options for an empty search without asking the server', async () => {
		const wrapper = mount(BulkDocumentActionDialog, { props: { mode: 'copy-to-case', selectedIds: ['join-1'], caseId: 'case-1' } })
		wrapper.vm.caseOptions = [{ id: 'case-9', label: 'old' }]

		await wrapper.vm.searchCases('   ')

		expect(mockGet).not.toHaveBeenCalled()
		expect(wrapper.vm.caseOptions).toEqual([])
	})

	it('clears the case options when the search fails, and encodes what was typed', async () => {
		mockGet.mockRejectedValueOnce(new Error('500'))
		const wrapper = mount(BulkDocumentActionDialog, { props: { mode: 'copy-to-case', selectedIds: ['join-1'], caseId: 'case-1' } })
		wrapper.vm.caseOptions = [{ id: 'case-9', label: 'old' }]

		await wrapper.vm.searchCases(' a&b ')
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith('/apps/openregister/api/objects/dossiq/case?_search=a%26b&_limit=20')
		expect(wrapper.vm.caseOptions).toEqual([])
	})
})
