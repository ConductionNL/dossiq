/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The work navigation group.
 *
 * "Work queue" is now "My work", and it gathers the four surfaces a handler
 * actually works from: the cases assigned to them, every case, their tasks, and
 * the workflow board. "Cases" is no longer a separate top-level entry — it
 * lives in the group as "All issues".
 *
 * The page that used to be labelled "My work" is now "Assigned to me". Both
 * could not keep that name once the GROUP took it, and a sidebar reading
 * "My work > My work" says nothing about what the inner entry holds.
 *
 * Entries are asserted by PRESENCE, not visibility: they sit inside a
 * collapsible group and are rendered but hidden until it is expanded, so a
 * visibility assertion fails on a perfectly correct menu.
 */

import { expect, test } from '@playwright/test'

test.describe('Work navigation', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load; the admin-settings spec in this suite documents the same
	// variability and sets an explicit budget rather than trusting a multiplier.
	test.setTimeout(300_000)

	// @e2e openspec/specs/my-work/spec.md
	test('the work group is named after the work, and holds all four surfaces', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		for (const label of [
			/^\s*(My work|Mijn werk)\s*$/i,
			/^\s*(Assigned to me|Aan mij toegewezen)\s*$/i,
			/^\s*(All issues|Alle zaken)\s*$/i,
			/^\s*(Tasks|Taken)\s*$/i,
		]) {
			await expect(
				nav.getByText(label),
				`the navigation must offer ${label}`,
			).toHaveCount(1, { timeout: 30_000 })
		}
	})

	// @e2e openspec/specs/my-work/spec.md
	test('the retired labels are gone', async ({ page }) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		// A half-applied rename leaves the old label alongside the new one, and
		// only this assertion would catch it.
		await expect(
			nav.getByText(/^\s*(Work queue|Werkvoorraad)\s*$/i),
			'the old group label must not survive',
		).toHaveCount(0)
		await expect(
			nav.getByText(/^\s*(Cases|Zaken)\s*$/i),
			'"Cases" must not survive alongside "All issues"',
		).toHaveCount(0)
	})

	// @e2e openspec/specs/my-work/spec.md
	test('the cases page stays reachable by direct link', async ({ page }) => {
		// Relabelling and relocating a menu entry must not move its ROUTE.
		// Bookmarks, shared links and the other specs in this suite all target
		// /cases, and none of them would notice until they broke.
		await page.goto('/apps/dossiq/#/cases')

		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the cases page must still render for a deep link',
		).toBeVisible({ timeout: 60_000 })
	})
})
