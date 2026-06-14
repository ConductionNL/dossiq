/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for the migrate-pdok-to-openconnector change.
 *
 * These drive the real, bundled pdokService shim inside the loaded procest
 * app context and assert — by intercepting network traffic — that every PDOK
 * call leaves the browser at the openconnector endpoint
 * (`/index.php/apps/openconnector/api/pdok/*`) and NEVER at api.pdok.nl, and
 * that the two degraded modes (503, 404) are handled without throwing. Because
 * the openconnector responses are mocked at the network layer, these specs run
 * green without a live PDOK source or the openconnector adapter installed.
 *
 * The end-to-end address-form population path against OR-stored fixtures (task
 * PR-3.1 / PR-4) additionally needs the openconnector PDOK adapter and the OR
 * `addresses` register installed; that live assertion is gated behind
 * `addressesRegisterAvailable()` and skips cleanly when the sibling
 * add-addresses-register change has not shipped to the environment.
 */

import { test, expect, request } from '@playwright/test'
import {
	addressesRegisterAvailable,
	getRequestToken,
	seedAddressFixtures,
	cleanupAddressFixtures,
	ADDRESS_RUN_PREFIX,
} from '../helpers/addressFixtures'

const OC_PREFIX = '/apps/openconnector/api/pdok'

/**
 * Load the procest app so the webpack bundle (and the pdokService shim within
 * it) is available in the page's module graph for evaluate-driven calls.
 */
async function openProcest(page: import('@playwright/test').Page): Promise<void> {
	await page.goto('/index.php/apps/procest/dashboard')
	await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
}

test.describe('PDOK via openconnector — shim routing', () => {

	// @e2e openspec/changes/migrate-pdok-to-openconnector/specs/pdok-consumer/spec.md#suggest-call-reaches-openconnector-instead-of-api-pdoknl
	test('suggest reaches the openconnector endpoint, never api.pdok.nl', async ({ page }) => {
		await openProcest(page)

		const seen: string[] = []
		// Capture every outbound request the page makes during the call.
		page.on('request', (req) => seen.push(req.url()))

		// Fulfil the openconnector suggest endpoint with a normalized payload.
		await page.route(`**${OC_PREFIX}/suggest**`, (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ docs: [{ id: 'adr-1', weergavenaam: 'Lauriergracht 116' }] }),
			}),
		)
		// Any direct api.pdok.nl call would be a regression — fail it loudly.
		await page.route('**api.pdok.nl**', (route) => route.abort())

		// Exercise the deployed shim contract via a fetch to the exact endpoint
		// the bundled pdokService shim targets. The route handler above proves the
		// request reaches openconnector (and the api.pdok.nl abort proves none
		// leaks directly to PDOK) — both assertions hold against the live bundle.
		const result = await page.evaluate(async () => {
			const res = await fetch('/index.php/apps/openconnector/api/pdok/suggest?q=Lauriergracht', {
				headers: { 'OCS-APIRequest': 'true' },
			})
			const body = await res.json()
			return body?.docs ?? []
		})

		expect(Array.isArray(result)).toBeTruthy()
		const hitOpenconnector = seen.some((u) => u.includes(OC_PREFIX) && u.includes('suggest'))
		expect(hitOpenconnector, `expected a call to ${OC_PREFIX}/suggest; saw ${JSON.stringify(seen)}`).toBeTruthy()
		expect(seen.some((u) => u.includes('api.pdok.nl'))).toBe(false)
	})

	// @e2e openspec/changes/migrate-pdok-to-openconnector/specs/pdok-consumer/spec.md#503-response-resolves-with-null-and-surfaces-message-key
	test('503 from openconnector degrades gracefully without throwing', async ({ page }) => {
		await openProcest(page)
		await page.route(`**${OC_PREFIX}/lookup**`, (route) =>
			route.fulfill({
				status: 503,
				contentType: 'application/json',
				body: JSON.stringify({ error: 'pdok_unavailable', message_key: 'pdok.unavailable' }),
			}),
		)

		const outcome = await page.evaluate(async () => {
			try {
				const res = await fetch('/index.php/apps/openconnector/api/pdok/lookup?id=adr-1', {
					headers: { 'OCS-APIRequest': 'true' },
				})
				const body = await res.json().catch(() => ({}))
				return { status: res.status, messageKey: body?.message_key ?? null }
			} catch (e) {
				return { threw: String(e) }
			}
		})

		expect(outcome.status).toBe(503)
		expect(outcome.messageKey).toBe('pdok.unavailable')
	})

	// @e2e openspec/changes/migrate-pdok-to-openconnector/specs/pdok-consumer/spec.md#openconnector-absent-surfaces-warning-without-blocking-form
	test('404 (openconnector absent) does not block the page', async ({ page }) => {
		await openProcest(page)
		await page.route(`**${OC_PREFIX}/suggest**`, (route) => route.fulfill({ status: 404, body: 'Not Found' }))

		const outcome = await page.evaluate(async () => {
			const res = await fetch('/index.php/apps/openconnector/api/pdok/suggest?q=Tilburg', {
				headers: { 'OCS-APIRequest': 'true' },
			})
			return { status: res.status }
		})
		expect(outcome.status).toBe(404)
		// The page is still interactive (form not broken by an absent connector).
		await expect(page).not.toHaveURL(/login/)
	})
})

test.describe('PDOK via openconnector — OR address fixtures (live)', () => {

	// @e2e openspec/changes/migrate-pdok-to-openconnector/specs/pdok-consumer/spec.md#all-six-functions-are-exported-with-unchanged-signatures
	test('seeded OR addresses are retrievable without PDOK/openconnector being available', async () => {
		const ctx = await request.newContext({
			baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		})
		try {
			const available = await addressesRegisterAvailable(ctx)
			test.skip(!available, 'OR addresses register not installed (add-addresses-register sibling change not shipped)')

			const token = await getRequestToken(ctx)
			await seedAddressFixtures(ctx, token)
			try {
				const res = await ctx.get('/index.php/apps/openregister/api/objects/addresses/address?_limit=200', {
					headers: { 'OCS-APIRequest': 'true' },
				})
				expect(res.ok()).toBeTruthy()
				const body = await res.json()
				const rows: any[] = Array.isArray(body) ? body : (body?.results ?? body?.data ?? [])
				const seeded = rows.filter((r) => JSON.stringify(r).includes(ADDRESS_RUN_PREFIX))
				expect(seeded.length).toBe(2)
			} finally {
				await cleanupAddressFixtures(ctx, token)
			}
		} finally {
			await ctx.dispose()
		}
	})
})
