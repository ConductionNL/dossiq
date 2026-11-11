// @vitest-environment jsdom
/**
 * Golden test for saving a triage rule: what is asked, what is emitted and what
 * is said, recorded before save was split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

const calls = []
const answers = []
const answer = async (verb, url, body) => {
	calls.push([verb, url, body])
	const next = answers.shift()
	if (next instanceof Error) {
		throw next
	}
	return next
}
vi.mock('@nextcloud/axios', () => ({
	default: { put: (url, body) => answer('put', url, body), post: (url, body) => answer('post', url, body) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (url) => '/index.php' + url }))

const { default: TriageRuleDialog } = await import('../../src/dialogs/TriageRuleDialog.vue')

/**
 * Save through the dialog's own method, on a stand-in for the dialog.
 *
 * @param {object} state The dialog's state.
 * @param {object|Error} reply What the server answers.
 * @return {Promise<object>} What happened.
 */
async function save(state, reply) {
	calls.length = 0
	answers.push(reply)
	const dialog = {
		missing: '',
		saving: false,
		error: 'old',
		caseId: 'case 1',
		rule: null,
		name: ' Spam ',
		effect: 'junk',
		filled: [{ field: 'sender', value: 'x' }],
		$emit: (name, payload) => calls.push(['emit', name, payload]),
		...state,
	}
	await TriageRuleDialog.methods.save.call(dialog)
	answers.length = 0
	return { calls: [...calls], saving: dialog.saving, error: dialog.error }
}

describe('saving a triage rule', () => {
	it('asks, emits and refuses as before', async () => {
		expect({
			missing: await save({ missing: 'name' }, { data: {} }),
			busy: await save({ saving: true }, { data: {} }),
			created: await save({}, { data: { id: 'r1' } }),
			updated: await save({ rule: { id: 'r/2' } }, { data: { id: 'r/2' } }),
			ruleWithoutId: await save({ rule: {} }, { data: { id: 'r3' } }),
			refused: await save({}, Object.assign(new Error('422'), { response: { data: { message: 'Name taken' } } })),
			failed: await save({ rule: { id: 'r4' } }, new Error('offline')),
		}).toMatchSnapshot()
	})
})
