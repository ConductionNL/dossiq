/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the avg-verwerkingenlogging spec
 * (thin consumer). Procest only renders a scoped window on OpenRegister's
 * processing-activity register; the engine scenarios (read logging,
 * attribution, fallback flagging) execute inside OR and carry @e2e
 * excludes in the spec. These tests cover the procest-owned surface:
 * the FG/admin view, the export entry point, denial for unprivileged
 * callers, and the absence of procest-side log endpoints.
 */

import { test, expect, request } from '@playwright/test'
import { BASE_URL } from '../base-url'
import { navToRoute } from '../helpers/nav'

const APP_URL = '/apps/procest'
// Single source of truth — see tests/e2e/base-url.ts. The old
// `process.env.NEXTCLOUD_URL || 'http://localhost:8080'` silently targeted the
// SHARED dev container off CI.
const BASE = BASE_URL

test.describe('AVG verwerkingenlogging spec coverage', () => {

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#fg-opens-the-procest-verwerkingen-overview
	test('admin (FG-equivalent) opens the verwerkingen overview', async ({ page }) => {
		// The app is history-mode, NOT hash-mode: `/apps/procest#/verwerkingen`
		// loads the app root and leaves the hash unrouted, so the overview
		// never renders. `/index.php/apps/procest/verwerkingen` renders it —
		// measured on a CI runner (2026-08-04).
		await navToRoute(page, '/verwerkingen')
		await expect(page.getByRole('heading', { name: 'Processing activities (AVG)' })).toBeVisible({ timeout: 15000 })
		// The catalogue table (seeded drafts) or the seed empty-state renders;
		// either way the scoped window is up — never a procest-side error page.
		await expect(
			page.locator('.verwerkingen-overview__table').or(page.getByText('No processing activities')),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#inzageverzoek-export-delegates-to-the-platform
	test('inzage export entry point delegates to the OpenRegister endpoint', async ({ page }) => {
		// The app is history-mode, NOT hash-mode: `/apps/procest#/verwerkingen`
		// loads the app root and leaves the hash unrouted, so the overview
		// never renders. `/index.php/apps/procest/verwerkingen` renders it —
		// measured on a CI runner (2026-08-04).
		await navToRoute(page, '/verwerkingen')
		await page.getByRole('button', { name: 'Data subject access export' }).click()
		await expect(page.getByRole('heading', { name: 'Data subject access export' })).toBeVisible()

		// The export MUST be produced by OR (OR-PA-7): assert the produce
		// action calls OpenRegister's betrokkene endpoint, not a procest route.
		const [orRequest] = await Promise.all([
			page.waitForRequest(req => req.url().includes('/apps/openregister/api/avg/verwerkingen/betrokkene')),
			(async () => {
				await page.getByLabel('Subject identifier value').fill('999990011')
				await page.getByRole('button', { name: 'Produce extract' }).click()
			})(),
		])
		expect(orRequest.url()).toContain('/apps/openregister/api/avg/verwerkingen/betrokkene')
	})

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#non-fg-users-cannot-reach-the-surface
	test('unauthenticated callers are denied on the delegated OR endpoints', async () => {
		const ctx = await request.newContext({ baseURL: BASE, storageState: undefined })
		const activities = await ctx.get('/apps/openregister/api/avg/verwerkingsactiviteiten', { maxRedirects: 0 })
		expect(activities.status()).not.toBe(200)
		const log = await ctx.get('/apps/openregister/api/avg/verwerkingen', { maxRedirects: 0 })
		expect(log.status()).not.toBe(200)
		await ctx.dispose()
	})

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#no-procest-log-endpoints-exist
	test('procest exposes no processing-log endpoints of its own', async ({ page }) => {
		// The procest route table must not answer AVG log paths — the VNG
		// Logging Verwerkingen API is OpenRegister's (OR-PA-9).
		// MUST use the /index.php prefix. Without it the request never reaches
		// Nextcloud's router — it is served the app shell HTML with status 200,
		// which made this assertion fail while looking like procest DID expose
		// the endpoint. (Had the expectation been inverted it would have
		// "passed" while proving nothing at all.)
		const res = await page.request.get(`/index.php${APP_URL}/api/avg/verwerkingen`, { maxRedirects: 0 })
		expect([404, 405]).toContain(res.status())
	})
})
