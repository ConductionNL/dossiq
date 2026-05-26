/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for doorlooptijd-dashboard spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/procest/<route> (not /index.php/apps/procest/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import { test, expect } from '@playwright/test'

test.describe('Doorlooptijd Dashboard spec coverage', () => {

	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#no-cases-exist
	test('shows empty state when no case data is available', async ({ page }) => {
		await page.goto('/apps/procest/doorlooptijd')
		// DoorlooptijdDashboard.vue renders "No case data available for processing time analysis."
		// when showNoCasesState is true (no cases in the system).
		await expect(
			page.getByText('No case data available for processing time analysis.'),
		).toBeVisible({ timeout: 15000 })
		// No broken charts or error states should be visible
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

})
