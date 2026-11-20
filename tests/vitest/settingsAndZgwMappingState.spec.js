/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the settings and ZGW mapping stores show their readers after a load
 * or a failure: the server's config, admin flag and OpenRegister flag, the
 * mappings, whether a request is running and why one failed.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { useSettingsStore } = await import('../../src/store/modules/settings.js')
const { useZgwMappingStore } = await import('../../src/store/modules/zgwMapping.js')

/**
 * Answer every request with the given body, or hold it until released.
 *
 * @param {object} body The JSON body.
 * @param {boolean} ok Whether the response is OK.
 * @return {Function} Resolves the held request.
 */
function answer(body, ok = true) {
	let release
	const held = new Promise((resolve) => {
		release = resolve
	})
	globalThis.fetch = vi.fn(async () => {
		await held
		return { ok, status: ok ? 200 : 500, json: async () => body }
	})
	return release
}

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'token-1' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

describe('settings store state', () => {
	it('shows a running load, then the server config, admin flag and OpenRegister flag apart', async () => {
		const store = useSettingsStore()
		const release = answer({ config: { register: 'dossiq' }, isAdmin: true, openRegisters: false })

		const loading = store.fetchSettings()
		expect(store.isLoading).toBe(true)
		release()
		await loading

		expect(store.isLoading).toBe(false)
		expect(store.isInitialized).toBe(true)
		expect(store.getConfig).toEqual({ register: 'dossiq' })
		expect(store.getIsAdmin).toBe(true)
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.getError).toBeNull()
	})

	it('shows why a load failed and stays uninitialised', async () => {
		const store = useSettingsStore()
		answer({ message: 'nope' }, false)()

		await store.fetchSettings()

		expect(store.isInitialized).toBe(false)
		expect(store.getIsAdmin).toBe(false)
		expect(store.getError).toEqual(expect.any(String))
		expect(store.getError).not.toBe('')
		expect(store.isLoading).toBe(false)
	})
})

describe('ZGW mapping store state', () => {
	it('shows a running load, then the mappings', async () => {
		const store = useZgwMappingStore()
		const release = answer({ mappings: { zaak: { schema: 'case' } } })

		const loading = store.fetchMappings()
		expect(store.isLoading).toBe(true)
		release()
		await loading

		expect(store.isLoading).toBe(false)
		expect(store.getMappings).toEqual({ zaak: { schema: 'case' } })
		expect(store.getError).toBeNull()
	})

	it('shows why a load failed and keeps no mappings', async () => {
		const store = useZgwMappingStore()
		answer({}, false)()

		await store.fetchMappings()

		expect(store.getMappings).toEqual({})
		expect(store.getError).toEqual(expect.any(String))
		expect(store.isLoading).toBe(false)
	})
})
