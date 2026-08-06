/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for task-management spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/procest/<route> (not /index.php/apps/procest/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import { test, expect } from '@playwright/test'

test.describe('Task Management spec coverage', () => {

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('global task list page renders with add button and empty state', async ({ page }) => {
		await page.goto('/index.php/apps/procest/tasks')
		// CnIndexPage renders with Add Task button and "No items found" empty state
		await expect(page.getByRole('button', { name: 'Add Task' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByText('No items found')).toBeVisible({ timeout: 10000 })
		// Should not show broken state
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

})
