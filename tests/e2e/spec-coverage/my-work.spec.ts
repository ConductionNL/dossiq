/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for my-work spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/procest/<route> (not /index.php/apps/procest/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import { test, expect } from '@playwright/test'
import { dismissSupportDialog } from '../helpers/nav'

test.describe('My Work spec coverage', () => {

	// @e2e openspec/specs/my-work/spec.md#filter-tab-layout
	test('filter tabs are visible (All, Cases, Tasks)', async ({ page }) => {
		await page.goto('/apps/procest/my-work')
		// h2 heading shows "My Work (N)" — match the main content heading
		await expect(page.getByRole('heading', { name: /My Work/ }).first()).toBeVisible({ timeout: 10000 })
		// Filter tabs must be present regardless of item count: All (0), Cases (0), Tasks (0)
		await expect(page.getByRole('tab', { name: /All/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Cases/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Tasks/ })).toBeVisible()
	})

	// @e2e openspec/specs/my-work/spec.md#no-assigned-items
	test('shows empty state when no items are assigned to the user', async ({ page }) => {
		await page.goto('/apps/procest/my-work')
		// MyWork.vue shows NcEmptyContent with "No items assigned to you"
		// when the user has no cases or tasks assigned.
		await expect(
			page.getByText('No items assigned to you'),
		).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/specs/my-work/spec.md#empty-after-filtering
	test('filter tabs remain clickable with zero items', async ({ page }) => {
		await page.goto('/apps/procest/my-work')
		// The "Support Procest" dialog auto-opens and its modal-mask intercepts
		// pointer events, blocking the tab click. Dismiss it first.
		await dismissSupportDialog(page)
		await expect(page.getByRole('tab', { name: /Cases/ })).toBeVisible({ timeout: 10000 })
		// Clicking a tab with 0 items should not error — the empty state should persist
		await page.getByRole('tab', { name: /Cases/ }).click()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		// The tab is still visible after clicking
		await expect(page.getByRole('tab', { name: /Cases/ })).toBeVisible()
	})

})
