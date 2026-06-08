/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * UI coverage for the zaakportaal-mijngemeente feature (#68) — the
 * citizen-facing "Mijn gemeente" portal.
 *
 * Drives the real portal views (registered in src/registry.js, reachable
 * from the sidebar entries "Mijn gemeente" and "Notificaties"):
 *   - src/views/portaal/MijnZaken.vue          ("My cases" overview)
 *   - src/views/portaal/MijnNotificaties.vue   (notification preferences)
 *
 * Playwright = UI only. These tests navigate the real DOM and interact with
 * the Vue components (sidebar nav, case overview empty state, notification
 * preference channel/event switches, save control). The portal REST
 * endpoints (/api/portaal/cases, /requests, /messages, /objections,
 * /notification-preferences, plus their 400/404 negatives) are exercised by
 * Newman in data/procest-zaakportaal.postman_collection.json — NOT here.
 *
 * Navigation note (see helpers/nav.ts): direct deep-link goto resets the
 * history-mode router to the Dashboard, so land on a resolving route and
 * click the sidebar entry client-side; dismiss the auto-opening "Support
 * Procest" dialog before interacting.
 */

import { test, expect, type Page } from '@playwright/test'
import { dismissSupportDialog, sidebarNav } from './helpers/nav'

/** Land on the app and click a portal sidebar entry by its visible label. */
async function gotoPortal(page: Page, label: string, urlRe: RegExp): Promise<void> {
	await page.goto('/index.php/apps/procest/cases')
	await dismissSupportDialog(page)
	await sidebarNav(page).getByRole('link', { name: label, exact: true }).click()
	await dismissSupportDialog(page)
	await expect(page).toHaveURL(urlRe)
}

test.describe('Zaakportaal — Mijn gemeente (case overview)', () => {

	// @e2e openspec/changes/zaakportaal-mijngemeente/specs/zaakportaal-mijngemeente/spec.md#case-overview-filtered-by-bsn-or-kvk
	test('renders the My cases overview', async ({ page }) => {
		await gotoPortal(page, 'Mijn gemeente', /\/portaal\/mijn-zaken/)
		// MijnZaken.vue header. With no cases addressable to the logged-in
		// citizen this renders the empty state — an honest, real-DOM assertion
		// that the view mounted and its case-fetch resolved (no error banner).
		await expect(page.getByRole('heading', { name: 'My cases' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('You currently have no active cases.')).toBeVisible()
	})
})

test.describe('Zaakportaal — Notification preferences', () => {

	// @e2e openspec/changes/zaakportaal-mijngemeente/specs/zaakportaal-mijngemeente/spec.md#notification-preferences-management
	test('renders channels, events and save control', async ({ page }) => {
		await gotoPortal(page, 'Notificaties', /\/portaal\/notificaties/)
		await expect(page.getByRole('heading', { name: 'Notification preferences' })).toBeVisible({ timeout: 15000 })

		// Channel section (MijnNotificaties.vue): email / Berichtenbox / SMS.
		await expect(page.getByText('Channels')).toBeVisible()
		await expect(page.getByText('Receive email notifications')).toBeVisible()
		await expect(page.getByText(/Berichtenbox/)).toBeVisible()

		// Event section + persist control.
		await expect(page.getByText('Events')).toBeVisible()
		await expect(page.getByText('Status change')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save preferences' })).toBeVisible()
	})

	// @e2e openspec/changes/zaakportaal-mijngemeente/specs/zaakportaal-mijngemeente/spec.md#notification-preferences-management
	test('the statutory Berichtenbox channel cannot be disabled', async ({ page }) => {
		await gotoPortal(page, 'Notificaties', /\/portaal\/notificaties/)
		await expect(page.getByRole('heading', { name: 'Notification preferences' })).toBeVisible({ timeout: 15000 })

		// The Berichtenbox switch is statutory and rendered disabled. Locate the
		// NcCheckboxRadioSwitch row by its label text and assert it carries the
		// disabled affordance (the input is disabled / aria-disabled).
		const berichtenboxRow = page.locator('.checkbox-radio-switch', {
			hasText: /Berichtenbox/,
		}).first()
		await expect(berichtenboxRow).toBeVisible()
		const disabledInput = berichtenboxRow.locator('input[disabled], input[aria-disabled="true"]')
		await expect(disabledInput).toHaveCount(1)
	})

	// @e2e openspec/changes/zaakportaal-mijngemeente/specs/zaakportaal-mijngemeente/spec.md#notification-preferences-management
	test('an event preference switch can be toggled in the UI', async ({ page }) => {
		await gotoPortal(page, 'Notificaties', /\/portaal\/notificaties/)
		await expect(page.getByRole('heading', { name: 'Notification preferences' })).toBeVisible({ timeout: 15000 })

		// Toggle the "Status change" event switch and assert the bound checkbox
		// state actually flips — proving the Vue v-model wiring is live, not a
		// static render.
		const statusRow = page.locator('.checkbox-radio-switch', {
			hasText: 'Status change',
		}).first()
		const input = statusRow.locator('input[type="checkbox"]').first()
		const before = await input.isChecked()
		await statusRow.locator('label, .checkbox-radio-switch__label, span').first().click()
		await expect(input).toBeChecked({ checked: !before })
	})
})
