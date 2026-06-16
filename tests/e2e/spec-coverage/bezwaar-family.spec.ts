/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural UI coverage for the bezwaar (objection) lifecycle index
 * pages: Beroepen (appeals), Beslissingen op bezwaar (decisions on
 * objection), BAC-adviezen (advice requests) and Bezwaaradviescommissies
 * (advisory committees). Each is reached by clicking its sidebar nav entry
 * (deep-links reset the history-mode router) and asserted on its rendered
 * index surface — the distinct primary-create control and the list shell —
 * independently of whether OpenRegister has seeded data. No assertion
 * depends on row content; every test also guards against a 5xx render.
 */

import { test, expect } from '@playwright/test'
import { navTo, dismissSupportDialog, sidebarNav, trackProcestErrors } from '../helpers/nav'

/**
 * Open the collapsible "Bezwaar & Beroep" nav group so its links become
 * clickable. Bezwaaradviescommissies now lives under this domain group (it
 * was relocated out of the Settings section by
 * procest-objections-appeals-group).
 */
async function expandBezwaarBeroep(page) {
	const present = await sidebarNav(page).getByRole('button', { name: 'Bezwaar & Beroep' }).count()
	if (present) {
		await sidebarNav(page).getByRole('button', { name: 'Bezwaar & Beroep' }).click().catch(() => {})
		await page.waitForTimeout(500)
	}
}

test.describe('Beroepen (appeals) index page', () => {
	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#beroepen-index-page-renders-list-shell
	test('beroepen index renders the appeals list shell with a create control', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Beroepen')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		// Distinct create control for the appeals surface.
		await expect(page.getByRole('button', { name: /^Add Beroep|^Add Case/ })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Beslissingen op bezwaar (decisions) index page', () => {
	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#bezwaar-decisions-index-page-renders-list-shell
	test('bezwaar decisions index renders the decisions list shell', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Beslissingen op bezwaar')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('BAC-adviezen (advice requests) index page', () => {
	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#bezwaar-advice-requests-index-page-renders-list-shell
	test('bezwaar advice requests index renders the list shell', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'BAC-adviezen')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Bezwaaradviescommissies (advisory committees) page', () => {
	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#bezwaar-committees-settings-page-renders-list-shell
	test('bezwaar committees page renders its own create control', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await page.goto('/index.php/apps/procest/cases')
		await dismissSupportDialog(page)
		await expandBezwaarBeroep(page)
		await sidebarNav(page).getByRole('link', { name: 'Bezwaaradviescommissies', exact: true }).click()
		await dismissSupportDialog(page)
		// Committee-specific create control distinguishes this view from the
		// other bezwaar/beroep lists in the group.
		await expect(page.getByRole('button', { name: 'Add Bezwaaradviescommissie' }))
			.toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
