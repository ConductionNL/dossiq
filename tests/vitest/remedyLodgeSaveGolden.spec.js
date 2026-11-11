// @vitest-environment jsdom
/**
 * Golden test for lodging a remedy: what is asked, kept, announced and said,
 * recorded before save was split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

const calls = []
const replies = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(async (url, body) => {
			calls.push(['post', url, body])
			const reply = replies.shift()
			if (reply instanceof Error) {
				throw reply
			}
			return reply
		}),
	},
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => calls.push(['emit', name, payload]), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (url) => '/index.php' + url }))

const { default: RemedyLodgeDialog } = await import('../../src/dialogs/RemedyLodgeDialog.vue')

/**
 * Lodge through the dialog's own method, on a stand-in for the dialog.
 *
 * @param {object} state The dialog's state.
 * @param {object|Error} reply What the server answers.
 * @return {Promise<object>} What happened.
 */
async function lodge(state, reply) {
	calls.length = 0
	replies.push(reply)
	const dialog = {
		busy: false,
		error: 'old',
		lodged: null,
		lodgeUrl: '/apps/dossiq/api/cases/{caseId}/objections',
		targetCaseId: 'case 1',
		receivedDate: new Date(2026, 10, 9, 23, 30),
		channel: { id: 'post' },
		grounds: ' Onjuist ',
		requestedRelief: ' Herzien ',
		...state,
	}
	await RemedyLodgeDialog.methods.save.call(dialog)
	replies.length = 0
	return { calls: [...calls], busy: dialog.busy, error: dialog.error, lodged: dialog.lodged }
}

describe('lodging a remedy', () => {
	it('asks, keeps, announces and refuses as before', async () => {
		const failing = (data) => Object.assign(new Error('refused'), { response: { data } })
		expect({
			lodged: await lodge({}, { data: { caseId: 'case-2' } }),
			lodgedWithoutAnswer: await lodge({ lodgeUrl: '/apps/dossiq/api/cases/{caseId}/appeals' }, {}),
			refusedWithMessage: await lodge({}, failing({ message: 'Too late', error: 'late' })),
			refusedWithError: await lodge({}, failing({ error: 'late' })),
			failed: await lodge({}, new Error('offline')),
		}).toMatchSnapshot()
	})
})
