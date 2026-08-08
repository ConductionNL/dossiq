/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * TEMPORARY SENTINEL — positive control for the E2E job.
 * Boots the real app through the same navigation the dossier specs use and
 * then asserts something that must be false. If the E2E job reports success
 * with this file present, the job is not actually reporting failures and its
 * green means nothing. REMOVED in the next commit.
 */
import { test, expect } from '@playwright/test'

test('SENTINEL: this must fail — proves the E2E job reports failures', async ({ page }) => {
	const response = await page.goto('/index.php/apps/procest/cases')
	expect(response, 'sentinel: app must be reachable').not.toBeNull()
	// The app renders; assert a string that cannot be present.
	await expect(page.locator('body')).toContainText('SENTINEL-STRING-THAT-CANNOT-EXIST-9f3a2b', { timeout: 8000 })
})
