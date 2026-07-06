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

const APP_URL = '/apps/procest'
const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

test.describe('AVG verwerkingenlogging spec coverage', () => {

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#fg-opens-the-procest-verwerkingen-overview
	test('admin (FG-equivalent) opens the verwerkingen overview', async ({ page }) => {
		await page.goto(`${APP_URL}#/verwerkingen`)
		await expect(page.getByRole('heading', { name: 'Processing activities (AVG)' })).toBeVisible({ timeout: 15000 })
		// The catalogue table (seeded drafts) or the seed empty-state renders;
		// either way the scoped window is up — never a procest-side error page.
		await expect(
			page.locator('.verwerkingen-overview__table').or(page.getByText('No processing activities')),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md#inzageverzoek-export-delegates-to-the-platform
	test('inzage export entry point delegates to the OpenRegister endpoint', async ({ page }) => {
		await page.goto(`${APP_URL}#/verwerkingen`)
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
		const res = await page.request.get(`${APP_URL}/api/avg/verwerkingen`, { maxRedirects: 0 })
		expect([404, 405]).toContain(res.status())
	})
})
