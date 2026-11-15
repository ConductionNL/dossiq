// @vitest-environment jsdom
/**
 * Golden test for recording a Woo search plan: what is asked, kept, announced
 * and said, recorded before save was split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

const calls = []
const replies = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		put: vi.fn(async (url, body) => {
			calls.push(['put', url, body])
			const reply = replies.shift()
			if (reply instanceof Error) {
				throw reply
			}
			return reply
		}),
	},
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => calls.push(['emit', name, payload]), subscribe: vi.fn(), unsubscribe: vi.fn() }))

const { default: SearchPlanDialog } = await import('../../src/dialogs/SearchPlanDialog.vue')

/**
 * Save through the dialog's own method, on a stand-in for the dialog.
 *
 * @param {object} state The dialog's state.
 * @param {object|Error} reply What the server answers.
 * @return {Promise<object>} What happened.
 */
async function save(state, reply) {
	calls.length = 0
	replies.push(reply)
	const dialog = {
		complete: true,
		busy: false,
		error: 'old',
		custodians: [{ name: ' Jan ', function: ' Jurist ' }, { name: ' ', function: 'x' }],
		systems: ['files'],
		periodFrom: '2026-01-01',
		periodTo: '2026-06-30',
		terms: ' kap ',
		startedFrom: '',
		url: (path) => '/case' + path,
		fill: (plan) => calls.push(['fill', plan]),
		...state,
	}
	await SearchPlanDialog.methods.save.call(dialog)
	replies.length = 0
	return { calls: [...calls], busy: dialog.busy, error: dialog.error, startedFrom: dialog.startedFrom }
}

describe('recording a search plan', () => {
	it('asks, keeps, announces and refuses as before', async () => {
		expect({
			incomplete: await save({ complete: false }, { data: {} }),
			busy: await save({ busy: true }, { data: {} }),
			recorded: await save({}, { data: { plan: { id: 'p1' } } }),
			fromTemplate: await save({ startedFrom: 'tpl-1' }, { data: {} }),
			refused: await save({}, Object.assign(new Error('422'), { response: { data: { message: 'No custodian' } } })),
			failed: await save({ startedFrom: 'tpl-2' }, new Error('offline')),
		}).toMatchSnapshot()
	})
})
