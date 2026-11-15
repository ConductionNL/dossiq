/**
 * Golden test for createCaseWithDuplicateCheck: what is asked, what is saved and
 * what is thrown, for every path, recorded before it was split into steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

const calls = []
const scenario = { matches: [], checked: true, show: false, decision: null }
const store = { saveObject: vi.fn(async (type, candidate) => { calls.push(['save', type, { ...candidate }]); return { id: 'saved', type } }) }

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one, more) => more }))
vi.mock('@conduction/nextcloud-vue', () => ({
	candidateWithoutBlanks: (candidate) => ({ ...candidate, stripped: true }),
	duplicateAffordances: (input) => { calls.push(['affordances', input]); return { show: scenario.show } },
	openDuplicateWarning: async (props) => { calls.push(['warning', props.matches, props.records, props.policy, props.mayOverride]); return scenario.decision },
	useObjectStore: () => store,
}))
vi.mock('../../src/services/duplicateCheckApi.js', () => ({
	checkForDuplicates: async (candidate) => { calls.push(['check', candidate]); return { matches: scenario.matches, checked: scenario.checked } },
	fetchMatchedCases: async (uuids) => { calls.push(['cases', uuids]); return uuids.map((uuid) => ({ uuid })) },
}))
vi.mock('../../src/services/initiatorSearch.js', () => ({
	initiatorChoice: async (source, row) => { calls.push(['initiator', source, row]); return row.party ? { requester: row.party, initiatorDisplayName: row.name } : { requester: '', initiatorDisplayName: row.name } },
}))
vi.mock('../../src/services/intakeTriageApi.js', () => ({
	fetchIntakeRequirements: async (caseTypeId) => { calls.push(['policy', caseTypeId]); return { duplicatePolicy: 'warn', mayOverrideDuplicates: true } },
}))

const { createCaseWithDuplicateCheck } = await import('../../src/services/createCaseWithDuplicateCheck.js')

/**
 * Run one create and record what it did.
 *
 * @param {object} payload The form's values.
 * @param {object} context The library's context.
 * @return {Promise<object>} What happened.
 */
async function run(payload, context) {
	calls.length = 0
	try {
		return { answer: await createCaseWithDuplicateCheck(payload, context), calls: [...calls] }
	} catch (error) {
		return { thrown: error.message, calls: [...calls] }
	}
}

describe('createCaseWithDuplicateCheck', () => {
	it('asks, saves and refuses as before', async () => {
		const outcomes = []
		Object.assign(scenario, { matches: [], show: false })
		outcomes.push(await run({ title: 'A', caseType: 'ct-1' }, { type: 'zaak' }))
		outcomes.push(await run({ title: 'A', caseType: 'ct-1', requesterChoice: { source: 'contacts', row: { party: 'p-1', name: 'Jan' } } }, undefined))
		outcomes.push(await run({ title: 'A', requesterChoice: { source: 'contacts', row: { name: 'Zonder partij' } } }, {}))
		Object.assign(scenario, { matches: [{ uuid: 'c-1' }, { uuid: 'c-2' }], show: true, decision: null })
		outcomes.push(await run({ title: 'B', caseType: 'ct-2' }, { type: 'case' }))
		Object.assign(scenario, { decision: { reason: 'Andere aanvrager', over: ['c-1'] } })
		outcomes.push(await run({ title: 'B', caseType: 'ct-2' }, { type: 'case' }))
		Object.assign(scenario, { decision: { reason: '', over: [] } })
		outcomes.push(await run({ title: 'B', caseType: 'ct-2' }, null))
		expect(outcomes).toMatchSnapshot()
	})
})
