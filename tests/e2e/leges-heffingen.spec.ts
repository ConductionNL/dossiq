/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * UI coverage for the leges-heffingen feature (#67).
 *
 * Drives the real "Legesverordeningen" admin page (manifest page
 * `LegesVerordeningen` → `LegesVerordeningenAdmin.vue`, registered in
 * src/registry.js and reachable from the sidebar entry "Legesverordeningen").
 *
 * Playwright = UI only. These tests navigate the real DOM and interact with
 * the Vue components (sidebar nav, page header, import button, import
 * dialog). The leges REST endpoints (calculate / import-verordening /
 * list verordeningen, plus the 400/500 wrappers) are exercised by Newman in
 * data/procest-leges.postman_collection.json — NOT here.
 *
 * Navigation note (see helpers/nav.ts): a direct deep-link goto resets the
 * history-mode router to the Dashboard, so we land on a resolving route and
 * click the sidebar entry client-side. The "Support Procest" dialog can
 * auto-open over the chrome and intercept pointer events, so it is dismissed
 * before any interaction.
 */

import { test, expect } from '@playwright/test'
import { dismissSupportDialog, sidebarNav } from './helpers/nav'

/**
 * Land on the app, expand the collapsible "Settings" nav group (the leges
 * admin leaf now lives under it after the config-to-settings IA change), and
 * click the "Legesverordeningen" sidebar entry to reach the leges admin view
 * (LegesVerordeningenAdmin.vue).
 */
async function gotoLegesAdmin(page: import('@playwright/test').Page): Promise<void> {
	await page.goto('/index.php/apps/procest/cases')
	await dismissSupportDialog(page)
	const settingsBtn = sidebarNav(page).getByRole('button', { name: 'Settings' })
	if (await settingsBtn.count()) {
		await settingsBtn.click().catch(() => {})
		await page.waitForTimeout(500)
	}
	await sidebarNav(page).getByRole('link', { name: 'Legesverordeningen', exact: true }).click()
	await dismissSupportDialog(page)
	await expect(page).toHaveURL(/\/leges\/verordeningen/)
}

test.describe('Leges — Legesverordeningen admin page', () => {

	// @e2e openspec/changes/leges-heffingen/specs.md#import-interface-en-validatie
	test('renders heading and import control', async ({ page }) => {
		await gotoLegesAdmin(page)
		// LegesVerordeningenAdmin.vue header + primary action.
		await expect(page.getByRole('heading', { name: 'Legesverordeningen' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: 'Verordening importeren' })).toBeVisible()
	})

	// @e2e openspec/changes/leges-heffingen/specs.md#conceptvastgesteld-workflow
	test('shows the verordeningen list shell (no load error)', async ({ page }) => {
		await gotoLegesAdmin(page)
		// The list area renders. With no seeded verordeningen this is an empty
		// state; the important honest assertion is that the
		// "Kon verordeningen niet laden" load-error banner is NOT shown — i.e.
		// the leges schemas are configured and the list endpoint responded 200.
		await expect(page.getByRole('heading', { name: 'Legesverordeningen' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('Kon verordeningen niet laden')).toHaveCount(0)
	})

	// @e2e openspec/changes/leges-heffingen/specs.md#import-interface-en-validatie
	test('opens the import dialog from the admin page', async ({ page }) => {
		await gotoLegesAdmin(page)
		// Dismiss the support dialog again in case it re-opened, so the click
		// lands on the real button rather than the modal mask.
		await dismissSupportDialog(page)
		await page.getByRole('button', { name: 'Verordening importeren' }).click()
		// LegesVerordeningImportDialog.vue mounts as an NcModal. Assert the
		// dialog chrome is present (heading text contains "import" /
		// "verordening") so we know the modal component rendered.
		const dialog = page.locator('.modal-container, [role="dialog"]').filter({
			hasText: /import|verordening/i,
		}).first()
		await expect(dialog).toBeVisible({ timeout: 10000 })
	})
})
