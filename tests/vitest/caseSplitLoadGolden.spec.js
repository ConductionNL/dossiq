// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for CaseSplitDialog's `load`
 * (src/dialogs/CaseSplitDialog.vue): the rule read first, the children read
 * only for what the rule allows, and the state after a failure.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const { default: Dialog } = await import('../../src/dialogs/CaseSplitDialog.vue')

/**
 * A stand-in for the dialog instance, carrying every method.
 *
 * @param {string} targetCaseId The case.
 * @return {object} The instance.
 */
function instance(targetCaseId = 'case 1') {
	return { ...Dialog.methods, targetCaseId, loading: null, error: 'old', allowed: ['old'], documents: ['old'], parties: ['old'] }
}

/**
 * Answer reads by URL.
 *
 * @param {object} answers The data per URL suffix; an Error is thrown.
 * @return {void}
 */
function answering(answers) {
	axios.get.mockImplementation(async (url) => {
		const key = Object.keys(answers).find((suffix) => url.endsWith(suffix))
		if (answers[key] instanceof Error || answers[key]?.response) {
			throw answers[key]
		}
		return { data: answers[key] }
	})
}

describe('case split load golden', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads nothing without a case', async () => {
		const vm = instance('')
		await vm.load()
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('old')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the rule, then the documents and parties it allows', async () => {
		answering({
			'/split': { allowed: ['documents', 'parties'] },
			'/caseDocument': { results: [{ id: 'd1', title: 'Plan', documentType: 'pdf' }, { id: 7 }] },
			'/role': { results: [{ id: 'r1', roleType: 'Aanvrager', displayName: 'Anna' }] },
		})
		const vm = instance()
		await vm.load()
		expect(axios.get.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/dossiq/api/case/case%201/split',
			'/index.php/apps/openregister/api/objects/dossiq/caseDocument',
			'/index.php/apps/openregister/api/objects/dossiq/role',
		])
		expect(axios.get.mock.calls[1][1]).toEqual({ params: { case: 'case 1', _limit: 100 } })
		expect(vm.allowed).toEqual(['documents', 'parties'])
		expect(vm.documents).toEqual([{ id: 'd1', label: 'Plan · pdf' }, { id: '7', label: '7' }])
		expect(vm.parties).toEqual([{ id: 'r1', label: 'Aanvrager · Anna' }])
		expect(vm.error).toBe('')
		expect(vm.loading).toBe(false)
	})

	it('reads only what the rule allows', async () => {
		answering({ '/split': { allowed: 'x' } })
		const vm = instance()
		await vm.load()
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(vm.allowed).toEqual([])
		expect(vm.documents).toEqual([])
		expect(vm.parties).toEqual([])
	})

	it.each([
		[{ response: { data: { error: 'Locked.' } } }, 'Locked.'],
		[new Error('net'), 'What this case holds could not be read, so nothing was split.'],
	])('empties the lists and says why when a read fails (%j)', async (failure, sentence) => {
		answering({ '/split': { allowed: ['parties'] }, '/role': failure })
		const vm = instance()
		await vm.load()
		expect(vm.allowed).toEqual([])
		expect(vm.documents).toEqual([])
		expect(vm.parties).toEqual([])
		expect(vm.error).toBe(sentence)
		expect(vm.loading).toBe(false)
	})
})
