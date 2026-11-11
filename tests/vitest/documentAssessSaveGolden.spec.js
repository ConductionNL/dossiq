// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for DocumentAssessDialog's `save`
 * (src/dialogs/DocumentAssessDialog.vue): the assessments it posts, what it
 * keeps and shows for a stored, a partly refused and a failed answer, and
 * that it reads the list again.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-handler-assesses-several-documents-in-one-dialog-req-das-002
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
const mockEmit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a) }))

const { default: Dialog } = await import('../../src/dialogs/DocumentAssessDialog.vue')

/**
 * A stand-in for the dialog instance, carrying every method.
 *
 * @param {object} state Fields to set.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const vm = {
		...Dialog.methods,
		fields: null,
		needsGrounds: false,
		pickedGrounds: [{ id: 'g1' }, { id: 'g2' }],
		selected: ['d1', 'd2'],
		classification: { id: 'openbaar' },
		assessUrl: '/apps/dossiq/api/cases/{caseId}/assess',
		targetCaseId: 'case 1',
		busy: null,
		error: 'old',
		notice: 'old',
		refusals: { old: 'x' },
		stored: ['d0'],
		documents: [{ id: 'd0' }, { id: 'd1' }, { id: 'd2' }, { id: 'd3' }],
		...state,
	}
	vm.load = vi.fn(async () => {
		vm.loadedWhileBusy = vm.busy
	})
	return vm
}

describe('document assess save golden', () => {
	beforeEach(() => {
		axios.post.mockReset()
		mockEmit.mockReset()
	})

	it('posts one assessment per selected document and keeps what was stored', async () => {
		axios.post.mockResolvedValue({ data: { saved: [{ documentRef: 'd1' }] } })
		const vm = instance()
		await vm.save()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/dossiq/api/cases/case%201/assess', {
			assessments: [
				{ documentRef: 'd1', classification: 'openbaar', grounds: [] },
				{ documentRef: 'd2', classification: 'openbaar', grounds: [] },
			],
		})
		expect(vm.stored).toEqual(['d0', 'd1'])
		expect(vm.documents.map((d) => d.id)).toEqual(['d0', 'd2', 'd3'])
		expect(vm.selected).toEqual(['d2'])
		expect(vm.notice).toBe('%n document assessed.')
		expect(vm.error).toBe('')
		expect(vm.refusals).toEqual({})
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh', { source: 'document-assessment REQ-DAS-002' })
		expect(vm.load).toHaveBeenCalledTimes(1)
		expect(vm.loadedWhileBusy).toBe(true)
		expect(vm.busy).toBe(false)
	})

	it('names its own fields and sends the grounds when they are needed', async () => {
		axios.post.mockResolvedValue({ data: { saved: [{ doc: 'd2' }, { doc: 'd2' }] } })
		const vm = instance({ fields: { document: 'doc', classification: 'cls', grounds: 'grd' }, needsGrounds: true })
		await vm.save()
		expect(axios.post.mock.calls[0][1]).toEqual({
			assessments: [
				{ doc: 'd1', cls: 'openbaar', grd: ['g1', 'g2'] },
				{ doc: 'd2', cls: 'openbaar', grd: ['g1', 'g2'] },
			],
		})
		expect(vm.stored).toEqual(['d0', 'd2'])
		expect(vm.notice).toBe('%n documents assessed.')
	})

	it('shows each refusal of a partly refused answer and keeps what was stored', async () => {
		axios.post.mockRejectedValue({
			response: { data: { saved: [{ documentRef: 'd1' }], errors: [{ documentRef: 'd2', errors: { a: 'No.', b: 'Never.' } }] } },
		})
		const vm = instance()
		await vm.save()
		expect(vm.refusals).toEqual({ d2: 'No. Never.' })
		expect(vm.error).toBe('The server refused part of this assessment. What it said is shown with each document.')
		expect(vm.stored).toEqual(['d0', 'd1'])
		expect(vm.notice).toBe('%n document assessed.')
		expect(vm.load).toHaveBeenCalledTimes(1)
	})

	it.each([
		[{ response: { data: { message: 'Locked.' } } }, 'Locked.'],
		[{ response: { data: { error: 'Gone.' } } }, 'Gone.'],
		[new Error('net'), 'The assessment could not be saved.'],
	])('says what a failed save answered (%j)', async (failure, sentence) => {
		axios.post.mockRejectedValue(failure)
		const vm = instance()
		await vm.save()
		expect(vm.error).toBe(sentence)
		expect(vm.notice).toBe('')
		expect(vm.stored).toEqual(['d0'])
		expect(mockEmit).not.toHaveBeenCalled()
		expect(vm.load).toHaveBeenCalledTimes(1)
		expect(vm.busy).toBe(false)
	})

	it('stores nothing for an answer without saved rows', async () => {
		axios.post.mockResolvedValue({ data: { saved: 'x' } })
		const vm = instance()
		await vm.save()
		expect(vm.notice).toBe('')
		expect(vm.selected).toEqual(['d1', 'd2'])
	})
})
