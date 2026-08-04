/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TEMPORARY positive control for the E2E gate.
 *
 * A job that has only ever been observed passing is indistinguishable from a
 * job that CANNOT fail. This spec deliberately fails so the "E2E Tests
 * (Playwright)" check can be observed going RED and naming the offending
 * test. It is removed in the next commit, after that observation.
 */

import { test, expect } from '@playwright/test'

test('POSITIVE CONTROL — this test is expected to FAIL and turn the job red', async ({ page }) => {
	await page.goto('/index.php/apps/procest')
	// The procest sidebar exists; assert a label that certainly does not, so
	// the failure is unambiguous and attributable to this spec alone.
	await expect(
		page.locator('[id^="app-navigation"]').first()
			.getByRole('link', { name: 'ZZ_SENTINEL_THIS_NAV_ITEM_DOES_NOT_EXIST', exact: true }),
	).toBeVisible({ timeout: 5000 })
})
