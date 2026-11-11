/**
 * Golden test for the settings store's fetch and save: the request each makes,
 * the state it leaves and what it answers, recorded before they were split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { useSettingsStore } = await import('../../src/store/modules/settings.js')

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

/**
 * The store's state as a plain object.
 *
 * @param {object} store The settings store.
 * @return {object} The state.
 */
function stateOf(store) {
	return {
		config: store.config,
		openRegisters: store.openRegisters,
		isAdmin: store.isAdmin,
		loading: store.loading,
		error: store.error,
		initialized: store.initialized,
	}
}

describe('settings store', () => {
	let logged
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.OC = { requestToken: 'token-1' }
		logged = vi.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => {
		logged.mockRestore()
	})

	it('fetches and saves as before', async () => {
		const outcomes = []
		const cases = [
			['fetch', { ok: true, json: async () => ({ config: { a: 1 }, openRegisters: true, isAdmin: true }) }],
			['fetch', { ok: true, json: async () => ({ b: 2 }) }],
			['fetch', { ok: false, statusText: 'Forbidden' }],
			['fetch', new Error('offline')],
			['save', { ok: true, json: async () => ({ config: { c: 3 } }) }],
			['save', { ok: true, json: async () => ({ d: 4 }) }],
			['save', { ok: false, statusText: 'Bad Request' }],
		]
		for (const [action, response] of cases) {
			const store = useSettingsStore()
			store.$reset()
			const asked = answering(response)
			const answer = action === 'fetch' ? await store.fetchSettings() : await store.saveSettings({ register: 'dossiq' })
			outcomes.push({ action, answer, asked, state: stateOf(store) })
		}
		expect({ outcomes, logged: logged.mock.calls.map((call) => [call[0], call[1]?.message]) }).toMatchSnapshot()
	})
})
