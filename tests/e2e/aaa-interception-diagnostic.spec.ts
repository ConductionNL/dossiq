/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY DIAGNOSTIC — NOT A REGRESSION TEST. Delete before merge.
 *
 * Measures, on a CI runner, exactly which Playwright request-interception
 * forms work against this Nextcloud, because the pdok-via-openconnector specs
 * fail with `net::ERR_FAILED` and a trace that contains NO `Route.fulfill`
 * call at all — i.e. the handler never ran.
 */

import { test, expect } from '@playwright/test'

const URL_SUGGEST = '/index.php/apps/openconnector/api/pdok/suggest?q=Tilburg'

async function open(page: import('@playwright/test').Page): Promise<void> {
	await page.goto('/index.php/apps/procest/dashboard')
	// eslint-disable-next-line no-console
	console.log('[DIAG] page.url() after goto =', page.url())
}

function instrument(page: import('@playwright/test').Page): void {
	page.on('requestfailed', (r) => {
		// eslint-disable-next-line no-console
		console.log('[DIAG][requestfailed]', r.url(), JSON.stringify(r.failure()))
	})
}

async function probe(page: import('@playwright/test').Page, url: string): Promise<unknown> {
	return page.evaluate(async (u) => {
		try {
			const res = await fetch(u, { headers: { 'OCS-APIRequest': 'true' } })
			return { status: res.status }
		} catch (e) {
			return { threw: String(e) }
		}
	}, url)
}

test.describe('DIAG interception', () => {

	test('D0 no route registered at all', async ({ page }) => {
		instrument(page)
		await open(page)
		// eslint-disable-next-line no-console
		console.log('[DIAG][D0] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)))
		// eslint-disable-next-line no-console
		console.log('[DIAG][D0] status.php =', JSON.stringify(await probe(page, '/status.php')))
		expect(true).toBe(true)
	})

	test('D1 original glob', async ({ page }) => {
		instrument(page)
		await open(page)
		let hit = 0
		await page.route('**/apps/openconnector/api/pdok/suggest**', (route) => {
			hit++
			return route.fulfill({ status: 404, body: 'Not Found' })
		})
		// eslint-disable-next-line no-console
		console.log('[DIAG][D1] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'handlerHits =', hit)
		// A request that does NOT match the glob, while interception is on.
		// eslint-disable-next-line no-console
		console.log('[DIAG][D1] status.php (unmatched, interception on) =', JSON.stringify(await probe(page, '/status.php')))
		expect(true).toBe(true)
	})

	test('D2 catch-all glob', async ({ page }) => {
		instrument(page)
		await open(page)
		const seen: string[] = []
		await page.route('**', (route) => {
			seen.push(route.request().url())
			if (route.request().url().includes('pdok')) {
				return route.fulfill({ status: 404, body: 'Not Found' })
			}
			return route.continue()
		})
		// eslint-disable-next-line no-console
		console.log('[DIAG][D2] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'seenCount =', seen.length, 'seen =', JSON.stringify(seen.slice(-5)))
		expect(true).toBe(true)
	})

	test('D3 regex matcher', async ({ page }) => {
		instrument(page)
		await open(page)
		let hit = 0
		await page.route(/pdok/, (route) => {
			hit++
			return route.fulfill({ status: 404, body: 'Not Found' })
		})
		// eslint-disable-next-line no-console
		console.log('[DIAG][D3] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'handlerHits =', hit)
		expect(true).toBe(true)
	})

	test('D4 predicate matcher', async ({ page }) => {
		instrument(page)
		await open(page)
		let hit = 0
		await page.route((u) => u.pathname.includes('/api/pdok/'), (route) => {
			hit++
			return route.fulfill({ status: 404, body: 'Not Found' })
		})
		// eslint-disable-next-line no-console
		console.log('[DIAG][D4] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'handlerHits =', hit)
		expect(true).toBe(true)
	})

	test('D5 route registered BEFORE navigation', async ({ page }) => {
		instrument(page)
		let hit = 0
		await page.route('**/apps/openconnector/api/pdok/suggest**', (route) => {
			hit++
			return route.fulfill({ status: 404, body: 'Not Found' })
		})
		await open(page)
		// eslint-disable-next-line no-console
		console.log('[DIAG][D5] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'handlerHits =', hit)
		expect(true).toBe(true)
	})

	test('D6 about:blank page (no Nextcloud SPA)', async ({ page }) => {
		instrument(page)
		let hit = 0
		await page.goto('/status.php')
		await page.route('**/apps/openconnector/api/pdok/suggest**', (route) => {
			hit++
			return route.fulfill({ status: 404, body: 'Not Found' })
		})
		// eslint-disable-next-line no-console
		console.log('[DIAG][D6] suggest =', JSON.stringify(await probe(page, URL_SUGGEST)), 'handlerHits =', hit)
		expect(true).toBe(true)
	})

	test('D7 APIRequestContext (no browser) hits the real server', async ({ request }) => {
		const res = await request.get(URL_SUGGEST, { headers: { 'OCS-APIRequest': 'true' }, failOnStatusCode: false })
		// eslint-disable-next-line no-console
		console.log('[DIAG][D7] real server status =', res.status(), 'ct =', res.headers()['content-type'])
		expect(true).toBe(true)
	})
})
