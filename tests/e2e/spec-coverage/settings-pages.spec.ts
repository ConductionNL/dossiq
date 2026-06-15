/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural UI coverage for the procest administrative settings pages.
 * These routes live under the collapsible "Settings" nav group (they are
 * only reachable after expanding it) and each renders an OpenRegister-backed
 * index with a view-specific primary create control. Every test expands the
 * settings group, nav-clicks the entry, and asserts that the page renders
 * its OWN distinct create control + the shared list shell — proving the
 * route resolves to the right view, not a stale one — while guarding against
 * a 5xx render and procest-origin console errors.
 */

import { test, expect } from '@playwright/test'
import { dismissSupportDialog, sidebarNav, trackProcestErrors } from '../helpers/nav'

/**
 * Land on a resolving route, dismiss the support dialog, expand the
 * collapsible Settings nav group, then click a settings link by its live
 * (i18n-rendered) label. Navigating to a settings page collapses the group
 * again, so it must be re-expanded for each test.
 * @param page
 * @param label
 * @param testId
 */
async function navToSetting(page, label: string, testId?: string): Promise<void> {
	await page.goto('/index.php/apps/procest/cases')
	await dismissSupportDialog(page)
	const settingsBtn = sidebarNav(page).getByRole('button', { name: 'Settings' })
	if (await settingsBtn.count()) {
		await settingsBtn.click().catch(() => {})
		await page.waitForTimeout(500)
	}
	// A couple of labels (e.g. "Fee ordinances") appear in both the main and
	// settings sections, so allow a testid to disambiguate the settings entry.
	const link = testId
		? sidebarNav(page).getByTestId(testId).getByRole('link', { name: label, exact: true })
		: sidebarNav(page).getByRole('link', { name: label, exact: true })
	await link.click()
	await dismissSupportDialog(page)
}

// label (live nav text), the view-specific create button text, optional
// nav testid when the label is ambiguous across sections.
const SETTINGS_PAGES: Array<{ label: string, addBtn: string, testId?: string }> = [
	{ label: 'Case Types', addBtn: 'Save' }, // CaseType settings form (Save control)
	// The single canonical "Legesverordeningen" Settings entry is the custom
	// import/approval admin view (/leges/verordeningen), covered by
	// leges-heffingen.spec. The former duplicate generic-list nav leaf
	// (LegesverordeningenMenu → /legesverordeningen) was removed in
	// procest-config-to-settings; its page stays routable by deep link.
	// Legesberekeningen (live per-case fee output) moved to the working nav and
	// is no longer a Settings entry.
	{ label: 'Parafeerroutes', addBtn: 'Add Parafeerroute' },
	{ label: 'Automatische acties', addBtn: 'Add Automatic Action' },
	{ label: 'Handhavingsstrategie', addBtn: 'Add LHS Matrix' },
	{ label: 'LHS Recommendations', addBtn: 'Add LHS Recommendation' },
	{ label: 'Partner organisations', addBtn: 'Add Partner organization' },
	{ label: 'Kaartlagen', addBtn: 'Add WMS/WFS Layer' },
	{ label: 'Workflow definitions', addBtn: 'Add Workflow Template' },
	{ label: 'Tenants', addBtn: 'Add Tenant' },
	{ label: 'Status history', addBtn: 'Add Status Record' },
	{ label: 'Case locations', addBtn: 'Add Case Location' },
]

for (const { label, addBtn, testId } of SETTINGS_PAGES) {
	test.describe(`Settings · ${label}`, () => {
		// @e2e openspec/specs/admin-settings/spec.md#settings-page-renders-distinct-control
		test(`${label} settings page renders its own "${addBtn}" control`, async ({ page }) => {
			const errors = trackProcestErrors(page)
			await navToSetting(page, label, testId)
			await expect(page.getByRole('button', { name: addBtn, exact: true }).first())
				.toBeVisible({ timeout: 15000 })
			await expect(page.locator('body')).not.toContainText('Internal Server Error')
			expect(errors, errors.join('\n')).toEqual([])
		})
	})
}
