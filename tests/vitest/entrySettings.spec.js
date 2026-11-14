/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings entry, the shared pinia instance and the public path:
 * what each sets up when it is loaded.
 */
import { describe, expect, it, vi } from 'vitest'

const calls = vi.hoisted(() => ({ translations: 0, app: null, root: null }))

vi.mock('@conduction/nextcloud-vue', () => ({ registerTranslations: () => { calls.translations++ } }))
vi.mock('@conduction/nextcloud-vue/css/index.css', () => ({}))
vi.mock('@nextcloud/router', () => ({ generateFilePath: (app, type, file) => `/custom_apps/${app}/${type}/${file}` }))
vi.mock('../../src/views/settings/AdminRoot.vue', () => ({ default: { name: 'AdminRoot' } }))
vi.mock('vue', async (importOriginal) => ({
	...(await importOriginal()),
	createApp: (root) => {
		calls.root = root
		calls.app = { use: vi.fn(), mount: vi.fn(), config: { globalProperties: {} } }
		return calls.app
	},
}))

// webpack defines this free variable; in an ES module it must exist before it is assigned.
globalThis.__webpack_public_path__ = ''

describe('settings entry', () => {
	it('mounts the admin root with pinia and the global translation helpers', async () => {
		globalThis.t = (app, text) => text
		globalThis.n = (app, one, other, count) => (count === 1 ? one : other)
		const pinia = (await import('../../src/pinia.js')).default

		await import('../../src/settings.js')

		expect(calls.translations).toBe(1)
		expect(calls.root).toEqual({ name: 'AdminRoot' })
		expect(calls.app.use).toHaveBeenCalledWith(pinia)
		expect(calls.app.config.globalProperties.t).toBe(globalThis.t)
		expect(calls.app.config.globalProperties.n).toBe(globalThis.n)
		expect(calls.app.mount).toHaveBeenCalledWith('#dossiq-settings')
	})

	it('serves the bundle chunks from the app js directory', async () => {
		await import('../../src/publicPath.js')

		expect(globalThis.__webpack_public_path__).toBe('/custom_apps/dossiq/js/')
	})

	it('shares one pinia instance', async () => {
		const first = (await import('../../src/pinia.js')).default

		expect(typeof first.use).toBe('function')
		expect((await import('../../src/pinia.js')).default).toBe(first)
	})
})
