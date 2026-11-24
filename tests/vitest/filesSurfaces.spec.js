// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The files browser's actions load on an object's page, from the bare page the server renders.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { addSurfaces, installFilesSurfaces, isObjectPage, loadFilesSurfaces, resetFilesSurfaces } from '../../src/services/filesSurfaces.js'

const BARE = `<!DOCTYPE html><html><head>
<link rel="stylesheet" href="/core/css/server.css?v=1">
<link rel="stylesheet" href="/apps/files_sharing/css/icons.css?v=1">
<script nonce="n" defer src="/core/l10n/en.js?v=1"></script>
<script nonce="n" defer src="/dist/files-init.js?v=1"></script>
<script nonce="n" type="module" src="/apps/text/js/text-files.mjs?v=1"></script>
</head><body>
<input type="hidden" id="initial-state-dossiq-version" value="b2xk">
<input type="hidden" id="initial-state-files-config" value="e30=">
</body></html>`

/**
 * Put a page that already carries core's stylesheet and script and dossiq's initial state.
 */
function seedPage() {
	document.head.innerHTML = '<link rel="stylesheet" href="/core/css/server.css?v=1"><script nonce="page" src="/core/l10n/en.js?v=1"></script>'
	document.body.innerHTML = '<input type="hidden" id="initial-state-dossiq-version" value="bmV3">'
}

/**
 * Let every added script element report that it loaded.
 */
function loadAddedScripts() {
	for (const script of document.head.querySelectorAll('script[src]')) {
		script.dispatchEvent(new Event('load'))
	}
}

describe('addSurfaces', () => {
	beforeEach(seedPage)

	it('adds the missing initial state, stylesheets and scripts and skips what the page has', async () => {
		const done = addSurfaces(BARE, document)
		loadAddedScripts()

		expect(await done).toBe(2)
		expect(document.getElementById('initial-state-files-config').value).toBe('e30=')
		expect(document.getElementById('initial-state-dossiq-version').value).toBe('bmV3')
		expect([...document.querySelectorAll('link[rel="stylesheet"]')].map((l) => l.getAttribute('href')))
			.toEqual(['/core/css/server.css?v=1', '/apps/files_sharing/css/icons.css?v=1'])
		const scripts = [...document.head.querySelectorAll('script[src]')]
		expect(scripts.map((s) => s.getAttribute('src'))).toEqual(['/core/l10n/en.js?v=1', '/dist/files-init.js?v=1', '/apps/text/js/text-files.mjs?v=1'])
	})

	it('keeps the server order, the module type and the page nonce on the added scripts', () => {
		addSurfaces(BARE, document)
		const [, init, text] = document.head.querySelectorAll('script[src]')

		expect(init.async).toBe(false)
		expect(text.async).toBe(false)
		expect(text.type).toBe('module')
		expect(init.nonce).toBe('page')
	})
})

describe('loadFilesSurfaces', () => {
	beforeEach(() => {
		seedPage()
		resetFilesSurfaces()
	})

	it('fetches the bare page once however often it is asked', async () => {
		const fetchPage = vi.fn(() => Promise.resolve('<html><head></head><body></body></html>'))

		await Promise.all([loadFilesSurfaces(fetchPage), loadFilesSurfaces(fetchPage)])
		await loadFilesSurfaces(fetchPage)

		expect(fetchPage).toHaveBeenCalledTimes(1)
	})

	it('tries again after a failed fetch', async () => {
		const fetchPage = vi.fn()
			.mockReturnValueOnce(Promise.reject(new Error('offline')))
			.mockReturnValueOnce(Promise.resolve('<html><head></head><body></body></html>'))

		expect(await loadFilesSurfaces(fetchPage)).toBe(0)
		await loadFilesSurfaces(fetchPage)

		expect(fetchPage).toHaveBeenCalledTimes(2)
	})
})

describe('installFilesSurfaces', () => {
	let hooks
	const router = { afterEach: (fn) => hooks.push(fn) }

	beforeEach(() => {
		hooks = []
		vi.useFakeTimers()
	})
	afterEach(() => vi.useRealTimers())

	it('loads nothing on a list and loads once on the first object page', () => {
		const load = vi.fn(() => Promise.resolve(0))
		installFilesSurfaces(router, load)

		hooks[0]({ matched: [{ path: '/cases' }] })
		vi.runAllTimers()
		expect(load).not.toHaveBeenCalled()

		hooks[0]({ matched: [{ path: '/cases/:id' }] })
		hooks[0]({ matched: [{ path: '/contacts/:id' }] })
		vi.runAllTimers()
		expect(load).toHaveBeenCalledTimes(1)
	})

	it('calls a route with a parameter an object page', () => {
		expect(isObjectPage({ matched: [{ path: '/cases/:id' }] })).toBe(true)
		expect(isObjectPage({ matched: [{ path: '/postvak' }] })).toBe(false)
		expect(isObjectPage(undefined)).toBe(false)
	})
})
