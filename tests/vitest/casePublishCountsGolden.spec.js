// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for CasePublishDialog's `loadCounts`
 * (src/dialogs/CasePublishDialog.vue): when it reads, the request it makes,
 * the count per classification, and when it says it cannot.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const { default: Dialog } = await import('../../src/dialogs/CasePublishDialog.vue')

/**
 * A stand-in for the dialog instance.
 *
 * @param {object|null} assessments Where the assessments live.
 * @param {string} targetCaseId The case.
 * @return {object} The instance.
 */
function instance(assessments, targetCaseId = 'case 1') {
	return { ...Dialog.methods, assessments, targetCaseId, tally: 'old', countsError: 'old' }
}

describe('case publish counts golden', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it.each([
		[null, 'case 1'],
		[{ register: 'r' }, 'case 1'],
		[{ schema: 's' }, 'case 1'],
		[{ register: 'r', schema: 's' }, ''],
	])('reads nothing without a register, schema and case (%j, %j)', async (assessments, caseId) => {
		const vm = instance(assessments, caseId)
		await vm.loadCounts()
		expect(axios.get).not.toHaveBeenCalled()
		expect(vm.tally).toBe('old')
		expect(vm.countsError).toBe('old')
	})

	it('counts the documents per classification', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ classification: 'openbaar' }, { classification: 'openbaar' }, { classification: 3 }, {}, null] } })
		const vm = instance({ register: 'reg 1', schema: 'doc/s' })
		await vm.loadCounts()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/reg%201/doc%2Fs', { params: { case: 'case 1', _limit: 500 } })
		expect(vm.tally).toEqual({ openbaar: 2, 3: 1, '': 2 })
		expect(vm.countsError).toBe('old')
	})

	it('reads its own case and classification fields, and no results as none', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ verdict: 'x' }] } })
		const vm = instance({ register: 'r', schema: 's', caseField: 'zaak', classificationField: 'verdict' })
		await vm.loadCounts()
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { zaak: 'case 1', _limit: 500 } })
		expect(vm.tally).toEqual({ x: 1 })

		axios.get.mockResolvedValue({ data: null })
		await vm.loadCounts()
		expect(vm.tally).toEqual({})
	})

	it('says the counts could not be read', async () => {
		axios.get.mockRejectedValue(new Error('net'))
		const vm = instance({ register: 'r', schema: 's' })
		await vm.loadCounts()
		expect(vm.countsError).toBe('The number of documents per assessment could not be read.')
		expect(vm.tally).toBe('old')
	})
})
