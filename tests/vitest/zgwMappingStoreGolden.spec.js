/**
 * Golden test for the ZGW mapping store: the request each action makes, the
 * state it leaves and what it answers, recorded before they were split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { useZgwMappingStore } = await import('../../src/store/modules/zgwMapping.js')

/**
 * A fetch that answers one response, recording what it was asked.
 *
 * @param {object} response What `fetch` resolves to, or an Error it rejects with.
 * @return {Array<object>} The requests, as they are made.
 */
function answering(response) {
	const asked = []
	globalThis.fetch = vi.fn(async (url, init) => {
		asked.push({ url, init })
		if (response instanceof Error) {
			throw response
		}
		return response
	})
	return asked
}

describe('ZGW mapping store', () => {
	let logged
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.OC = { requestToken: 'token-1' }
		logged = vi.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		logged.mockRestore()
	})

	it('fetches, saves and resets as before', async () => {
		const ok = (body) => ({ ok: true, json: async () => body })
		const refused = { ok: false, statusText: 'Forbidden' }
		const cases = [
			['fetchMappings', [], ok({ mappings: { zaak: { a: 1 } } })],
			['fetchMappings', [], ok({})],
			['fetchMappings', [], refused],
			['saveMapping', ['zaak', { b: 2 }], ok({ mapping: { b: 2 } })],
			['saveMapping', ['zaak', { b: 2 }], refused],
			['resetMapping', ['status'], ok({ mapping: { c: 3 } })],
			['resetMapping', ['status'], ok({})],
			['resetMapping', ['status'], new Error('offline')],
		]
		const outcomes = []
		for (const [action, args, response] of cases) {
			const store = useZgwMappingStore()
			store.$reset()
			const asked = answering(response)
			const answer = await store[action](...args)
			outcomes.push({ action, answer, asked, state: { mappings: store.mappings, loading: store.loading, error: store.error } })
		}
		expect({ outcomes, logged: logged.mock.calls.map((call) => [call[0], call[1]?.message]) }).toMatchSnapshot()
	})
})
