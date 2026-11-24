/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The files browser's actions arrive once an object's page opens, not with every page.
 *
 * The Files app and its plugins register their file actions and New menu
 * entries on the Files app's additional-scripts load event. Those scripts weigh
 * megabytes and took about a second of the main thread on every dossiq page,
 * the lists included, which show no files. So the page leaves them out, and
 * when the router reaches an object's page (a route with a `:` parameter: the
 * pages that carry the files tab) this fetches `/apps/dossiq/files-surfaces`
 * once, a bare page the server renders with that event dispatched, and adds
 * what it names that this page lacks: the initial state first (scripts read
 * it while they run), then the stylesheets, then the scripts in their order.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let loading = null

/**
 * Add to `doc` the initial state, stylesheets and scripts of `html` that `doc` does not have yet.
 *
 * Scripts are added with `async = false`, so they run in the order the server
 * wrote them, module scripts included; a script whose `src` is already on the
 * page is skipped, so Nextcloud's own and every app's global scripts run once.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @param {string} html The bare page the server rendered.
 * @param {Document} doc The page to add to.
 * @return {Promise<number>} The number of scripts added, once each has loaded or failed.
 */
export function addSurfaces(html, doc = document) {
	const parsed = new DOMParser().parseFromString(html, 'text/html')

	for (const input of parsed.querySelectorAll('input[type="hidden"][id^="initial-state-"]')) {
		if (!doc.getElementById(input.id)) {
			doc.body.appendChild(doc.importNode(input, true))
		}
	}

	const hrefs = new Set([...doc.querySelectorAll('link[rel="stylesheet"]')].map((link) => link.getAttribute('href')))
	for (const link of parsed.querySelectorAll('link[rel="stylesheet"][href]')) {
		if (!hrefs.has(link.getAttribute('href'))) {
			const el = doc.createElement('link')
			el.rel = 'stylesheet'
			el.href = link.getAttribute('href')
			doc.head.appendChild(el)
		}
	}

	const srcs = new Set([...doc.querySelectorAll('script[src]')].map((script) => script.getAttribute('src')))
	const nonce = doc.querySelector('script[nonce]')?.nonce || ''
	const loads = []
	for (const script of parsed.querySelectorAll('script[src]')) {
		const src = script.getAttribute('src')
		if (srcs.has(src)) {
			continue
		}
		srcs.add(src)
		const el = doc.createElement('script')
		if (script.getAttribute('type')) {
			el.type = script.getAttribute('type')
		}
		if (nonce) {
			el.nonce = nonce
		}
		el.async = false
		el.src = src
		loads.push(new Promise((resolve) => {
			el.addEventListener('load', resolve, { once: true })
			el.addEventListener('error', resolve, { once: true })
		}))
		doc.head.appendChild(el)
	}
	return Promise.all(loads).then(() => loads.length)
}

/**
 * Fetch the files browser's surfaces once and add them to the page.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @param {function(): Promise<string>} [fetchPage] Returns the bare page's HTML.
 * @return {Promise<number>} The number of scripts added.
 */
export function loadFilesSurfaces(fetchPage = () => axios.get(generateUrl('/apps/dossiq/files-surfaces'), { responseType: 'text' }).then((r) => r.data)) {
	if (loading === null) {
		loading = fetchPage()
			.then((html) => addSurfaces(html))
			.catch(() => {
				loading = null
				return 0
			})
	}
	return loading
}

/**
 * Whether a route is an object's page: one of its records has a `:` parameter.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @param {object} route A vue-router route.
 * @return {boolean} True for an object's page.
 */
export function isObjectPage(route) {
	return (route?.matched || []).some((record) => String(record.path || '').includes(':'))
}

/**
 * Load the files browser's surfaces when the router first reaches an object's page.
 *
 * Deferred to an idle moment after the navigation, so the page paints first.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @param {object} router The app's router.
 * @param {function(): Promise<number>} [load] The loader, for tests.
 * @return {void}
 */
export function installFilesSurfaces(router, load = loadFilesSurfaces) {
	let started = false
	router.afterEach((to) => {
		if (started || !isObjectPage(to)) {
			return
		}
		started = true
		const run = () => load()
		if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
			window.requestIdleCallback(run, { timeout: 2000 })
		} else {
			setTimeout(run, 0)
		}
	})
}

/**
 * Forget a finished load, for tests.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 * @return {void}
 */
export function resetFilesSurfaces() {
	loading = null
}
