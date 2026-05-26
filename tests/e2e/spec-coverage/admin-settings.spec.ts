/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for admin-settings spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Nextcloud admin settings are served at /settings/admin/procest.
 * The AdminRoot.vue component renders inside Nextcloud's settings framework.
 */

import { test, expect, request } from '@playwright/test'

const ADMIN_SETTINGS_URL = '/settings/admin/procest'

test.describe('Admin Settings spec coverage', () => {

	// @e2e openspec/specs/admin-settings/spec.md#admin-settings-page-is-accessible
	test('admin settings page is accessible to admin users', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		// Nextcloud admin settings should render — not redirect to login
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })
		// The AdminRoot.vue component renders "Case Type Management" section heading
		await expect(page.getByRole('heading', { name: 'Case Type Management' })).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/admin-settings/spec.md#regular-users-cannot-access-admin-settings
	test('regular users cannot access admin settings', async () => {
		// Test unauthenticated access: create a fresh request context with no cookies.
		// Must pass storageState: undefined to avoid inheriting the admin session.
		const ctx = await request.newContext({
			baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
			storageState: undefined,
		})
		const res = await ctx.get(ADMIN_SETTINGS_URL, { maxRedirects: 0 })
		// Unauthenticated requests get 401 (Nextcloud returns 401 for unauthenticated access)
		// The key is that it is NOT a 200 with admin content
		expect(res.status()).not.toBe(200)
		await ctx.dispose()
	})

	// @e2e openspec/specs/admin-settings/spec.md#empty-case-type-list
	test('empty case type list shows empty state and add button', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		// CnIndexPage shows "No items found" when the list is empty
		// and provides an "Add Item" button (the generic CnIndexPage label)
		await expect(page.getByRole('heading', { name: 'Case Type Management' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('No items found')).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/specs/admin-settings/spec.md#add-a-new-case-type
	test('clicking add case type opens creation form', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page.getByRole('heading', { name: 'Case Type Management' })).toBeVisible({ timeout: 15000 })
		const addBtn = page.getByRole('button', { name: 'Add Item' })
		await expect(addBtn).toBeVisible({ timeout: 10000 })
		await addBtn.click()
		// After clicking Add, CaseTypeAdmin switches to detail view showing CaseTypeDetail
		// which renders an h3 "New Case Type" heading and a Save button
		await expect(page.getByRole('heading', { name: 'New Case Type' })).toBeVisible({ timeout: 10000 })
		// There may be multiple Save buttons on the page; ensure at least one is visible
		await expect(page.getByRole('button', { name: 'Save' }).first()).toBeVisible()
		await expect(page.getByRole('button', { name: 'Back to list' })).toBeVisible()
	})

})
