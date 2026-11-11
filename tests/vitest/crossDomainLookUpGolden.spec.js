// @vitest-environment jsdom
/**
 * Golden test for the cross-domain lookup dialog's lookUp: what it asks, what it
 * keeps and what it says, for a person and for a household.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

const calls = []
const answers = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(async (url, body) => {
			calls.push(['post', url, body])
			const answer = answers.shift()
			if (answer instanceof Error) {
				throw answer
			}
			return answer
		}),
	},
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => calls.push(['emit', name]), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (url, params = {}) => url.replace('{caseId}', params.caseId) }))
vi.mock('../../src/utils/savedMail.js', () => ({ caseIdFromLocation: () => 'case-9' }))

const { default: CrossDomainLookupDialog } = await import('../../src/dialogs/CrossDomainLookupDialog.vue')

/**
 * Look up through the dialog's own method, on a stand-in for the dialog.
 *
 * @param {object} state The dialog's state.
 * @param {object|Error} answer What the server answers.
 * @return {Promise<object>} What happened.
 */
async function lookUp(state, answer) {
	calls.length = 0
	answers.push(answer)
	const dialog = { canLookUp: true, busy: false, error: 'old', answered: true, found: ['old'], household: false, bsn: ' 123456782 ', domain: 'zorg', ground: 'wmo', ...state }
	await CrossDomainLookupDialog.methods.lookUp.call(dialog)
	answers.length = 0
	return { calls: [...calls], busy: dialog.busy, error: dialog.error, answered: dialog.answered, found: dialog.found }
}

describe('the cross-domain lookup', () => {
	it('asks, keeps and refuses as before', async () => {
		const refused = Object.assign(new Error('403'), { response: { data: { message: 'Choose a ground first.' } } })
		expect({
			cannot: await lookUp({ canLookUp: false }, { data: { found: [{ id: 'x' }] } }),
			person: await lookUp({}, { data: { found: [{ id: 'p1' }] } }),
			personNoList: await lookUp({}, { data: { found: 'p1' } }),
			household: await lookUp({ household: true }, { data: { results: [{ id: 'h1' }] } }),
			householdNoList: await lookUp({ household: true }, { data: {} }),
			refused: await lookUp({}, refused),
			failed: await lookUp({ household: true }, new Error('offline')),
		}).toMatchSnapshot()
	})
})
