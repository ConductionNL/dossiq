// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Giving a selection of cases to another handler: the rehearsal sent, and the refusals shown.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const preview = vi.fn()

vi.mock('../../src/services/bulkJobApi.js', async (importOriginal) => {
	const real = await importOriginal()
	return { ACTION_REASSIGN: real.ACTION_REASSIGN, readRefusal: real.readRefusal, previewBulkJob: (...args) => preview(...args) }
})
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: plain('NcButton'),
	NcDialog: plain('NcDialog'),
	NcNoteCard: plain('NcNoteCard'),
	NcTextArea: plain('NcTextArea'),
	NcTextField: plain('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnBulkJobProgress: plain('CnBulkJobProgress'), CnSelectionScope: plain('CnSelectionScope') }))

const { default: ReassignSelectionDialog } = await import('../../src/dialogs/ReassignSelectionDialog.vue')

/**
 * Mount the dialog over a selection.
 *
 * @return {object} The wrapper.
 */
function dialog() {
	return mount(ReassignSelectionDialog, {
		props: { open: true, selectedIds: [4, 'c-5'], matchingTotal: 40, filters: { status: 'open' } },
	})
}

describe('ReassignSelectionDialog', () => {
	beforeEach(() => preview.mockReset())

	it('can only rehearse with a handler and a reason, and not while busy', () => {
		const w = dialog()
		expect(w.vm.canRehearse).toBe(false)
		w.vm.handler = 'marieke'
		expect(w.vm.canRehearse).toBe(false)
		w.vm.justification = '   '
		expect(w.vm.canRehearse).toBe(false)
		w.vm.justification = 'Vertrek collega'
		expect(w.vm.canRehearse).toBe(true)
		w.vm.busy = true
		expect(w.vm.canRehearse).toBe(false)
		expect(w.vm.title).toBe('Give the cases to another handler')
	})

	it('rehearses the reassignment of the selected ids, trimmed', async () => {
		preview.mockResolvedValue({ id: 7, state: 'previewed' })
		const w = dialog()
		w.vm.handler = ' marieke '
		w.vm.justification = ' Vertrek collega '

		await w.vm.rehearse()

		expect(preview).toHaveBeenCalledWith({
			action: 'dossiq:reassign-cases',
			parameters: { toUser: 'marieke', reason: 'Vertrek collega' },
			selection: { ids: ['4', 'c-5'] },
			justification: 'Vertrek collega',
		})
		expect(w.vm.job).toEqual({ id: 7, state: 'previewed' })
		expect(w.vm.busy).toBe(false)
		expect(w.vm.error).toBe('')
	})

	it('rehearses over the whole result as a query when that scope is chosen', async () => {
		preview.mockResolvedValue({ id: 8 })
		const w = dialog()
		w.vm.handler = 'marieke'
		w.vm.justification = 'Vertrek'
		w.vm.scope = 'result'

		await w.vm.rehearse()

		expect(preview.mock.calls[0][0].selection).toEqual({ query: { status: 'open' } })
	})

	it('explains a missing justification, passes other refusals on, and falls back', async () => {
		const w = dialog()
		w.vm.handler = 'marieke'
		w.vm.justification = 'x'

		preview.mockImplementationOnce(() => Promise.reject({ response: { data: { reason: 'justification-required', error: 'ignored' } } }))
		await w.vm.rehearse()
		expect(w.vm.error).toBe('Say why these cases are moving. The reason is kept with the act.')

		preview.mockImplementationOnce(() => Promise.reject({ response: { data: { error: 'Marieke is not a handler here' } } }))
		await w.vm.rehearse()
		expect(w.vm.error).toBe('Marieke is not a handler here')

		preview.mockImplementationOnce(() => Promise.reject(new Error('network')))
		await w.vm.rehearse()
		expect(w.vm.error).toBe('The cases could not be moved.')
		expect(w.vm.job).toBeNull()
		expect(w.vm.busy).toBe(false)
	})
})
