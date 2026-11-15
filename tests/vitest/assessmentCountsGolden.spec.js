// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for AssessmentDecisionDialog's `loadCounts`
 * (src/dialogs/AssessmentDecisionDialog.vue): the documents per assessment
 * and per refusal ground, the read it makes, and when it says it cannot.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import AssessmentDecisionDialog from '../../src/dialogs/AssessmentDecisionDialog.vue'

const { loadCounts } = AssessmentDecisionDialog.methods

/**
 * A stand-in for the dialog instance.
 *
 * @param {object|null} assessments Where the assessments live.
 * @return {object} The instance.
 */
function instance(assessments) {
	return { assessments, targetCaseId: 'case 1', countsError: 'old', tally: null, groundTally: null }
}

describe('assessment counts', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it.each([
		[null],
		[{ register: 'r' }],
		[{ schema: 's' }],
	])('says the counts are not shown without a register and schema (%j)', async (assessments) => {
		const vm = instance(assessments)
		await loadCounts.call(vm)
		expect(vm.countsError).toBe('The number of documents per assessment is not shown here.')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('counts the documents per assessment and per refusal ground', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ classification: 'public', weigeringsgronden: [{ code: '5.1.2e' }, '5.1.1c'] },
					{ classification: 'public', weigeringsgronden: [{ code: '5.1.2e' }, { code: '' }, null] },
					{ classification: 'refused', weigeringsgronden: 'not a list' },
					{},
					null,
				],
			},
		})
		const vm = instance({ register: 'reg 1', schema: 'doc/s' })
		await loadCounts.call(vm)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/reg%201/doc%2Fs',
			{ params: { case: 'case 1', _limit: 500 } },
		)
		expect(vm.tally).toEqual({ public: 2, refused: 1, '': 2 })
		expect(vm.groundTally).toEqual({ '5.1.2e': 2, '5.1.1c': 1 })
		expect(vm.countsError).toBe('')
	})

	it('reads the declared classification and case fields', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ beoordeling: 'deels' }] } })
		const vm = instance({ register: 'r', schema: 's', classificationField: 'beoordeling', caseField: 'zaak' })
		await loadCounts.call(vm)
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { zaak: 'case 1', _limit: 500 } })
		expect(vm.tally).toEqual({ deels: 1 })
		expect(vm.groundTally).toEqual({})
	})

	it('counts nothing for an answer without results', async () => {
		axios.get.mockResolvedValue({ data: null })
		const vm = instance({ register: 'r', schema: 's' })
		await loadCounts.call(vm)
		expect(vm.tally).toEqual({})
		expect(vm.groundTally).toEqual({})
	})

	it('says the counts could not be read when the read fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const vm = instance({ register: 'r', schema: 's' })
		await loadCounts.call(vm)
		expect(vm.countsError).toBe('The number of documents per assessment could not be read.')
		expect(vm.tally).toBeNull()
	})
})
