// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for BulkDocumentActionDialog's `runBulk`
 * (src/modals/BulkDocumentActionDialog.vue): the request, every sentence it
 * shows, the page refresh, and the busy flag.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import axios from '@nextcloud/axios'
import { translate } from '@nextcloud/l10n'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (u) => u }))
const mockError = vi.fn()
const mockSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError: (...a) => mockError(...a), showSuccess: (...a) => mockSuccess(...a) }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

const { default: Dialog } = await import('../../src/modals/BulkDocumentActionDialog.vue')

/**
 * A stand-in for the dialog instance.
 *
 * @param {Array<string>|Error} ids What resolveDocumentIds answers; an Error is thrown.
 * @param {string} fileName The file the dialog was opened on, or ''.
 * @return {object} The instance.
 */
function instance(ids, fileName = '') {
	const vm = {
		...Dialog.methods,
		t: translate,
		fileName,
		busy: null,
		error: 'old',
		results: ['old'],
		resolveDocumentIds: vi.fn(async () => {
			vm.busyWhileResolving = vm.busy
			if (ids instanceof Error) {
				throw ids
			}
			return ids
		}),
	}
	Object.defineProperty(vm, 'succeededCount', { get: () => Dialog.computed.succeededCount.call(vm) })
	return vm
}

describe('bulk document run golden', () => {
	beforeEach(() => {
		axios.post.mockReset()
		mockError.mockReset()
		mockSuccess.mockReset()
		mockEmit.mockReset()
	})

	it('posts the ids with the mode fields and says how many changed', async () => {
		axios.post.mockResolvedValue({ data: { results: [{ success: true }, { success: false }, {}] } })
		const vm = instance(['i1', 'i2'])
		await vm.runBulk('/apps/dossiq/api/bulk/status', { status: 'final' })
		expect(axios.post).toHaveBeenCalledWith('/apps/dossiq/api/bulk/status', { ids: ['i1', 'i2'], status: 'final' })
		expect(mockSuccess).toHaveBeenCalledWith('2 of 3 document(s) updated')
		expect(mockError).not.toHaveBeenCalled()
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh')
		expect(vm.error).toBe('')
		expect(vm.busyWhileResolving).toBe(true)
		expect(vm.busy).toBe(false)
	})

	it('says nothing changed when every item failed, and still refreshes', async () => {
		axios.post.mockResolvedValue({ data: { results: [{ success: false }] } })
		const vm = instance(['i1'])
		await vm.runBulk('/p', {})
		expect(vm.error).toBe('Bulk action changed nothing')
		expect(mockError).toHaveBeenCalledWith('Bulk action changed nothing')
		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockEmit).toHaveBeenCalledTimes(1)
	})

	it('reads an answer without results as none, and says 0 of 0', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const vm = instance(['i1'])
		await vm.runBulk('/p', {})
		expect(vm.results).toEqual([])
		expect(mockSuccess).toHaveBeenCalledWith('0 of 0 document(s) updated')
	})

	it.each([
		['', 'No document record was found, so nothing was changed'],
		['plan.pdf', 'No document record was found for plan.pdf, so nothing was changed'],
	])('refuses to run on no documents (%j)', async (fileName, sentence) => {
		const vm = instance([], fileName)
		await vm.runBulk('/p', {})
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.error).toBe(sentence)
		expect(mockError).toHaveBeenCalledWith(sentence)
		expect(mockEmit).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})

	it.each([[new Error('ids')], ['post']])('says the run failed when a step throws (%s)', async (where) => {
		if (where === 'post') {
			axios.post.mockRejectedValue(new Error('net'))
		}
		const vm = instance(where === 'post' ? ['i1'] : where)
		await vm.runBulk('/p', {})
		expect(vm.error).toBe('Bulk action failed')
		expect(mockError).toHaveBeenCalledWith('Bulk action failed')
		expect(mockEmit).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})
})
