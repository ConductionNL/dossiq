/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared navigation helpers for Procest e2e tests.
 *
 * The procest app ships a "Support Procest" dialog (`cn-support-dialog`)
 * that can auto-open over the app and whose modal-mask subtree intercepts
 * pointer events on the sidebar navigation — breaking nav clicks. Always
 * dismiss it before interacting with the app chrome.
 *
 * Navigation note: a direct deep-link `page.goto('/apps/procest/<route>')`
 * resets the Vue history-mode router to the Dashboard, so the deep-linked
 * route never renders its own view. Land on a route that resolves, then
 * click the sidebar nav entry (client-side) to reach the target view.
 */

import { Page } from '@playwright/test'

/** The app's sidebar navigation container. */
export const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

/**
 * Dismiss the "Support Procest" dialog if it is open. The dialog's
 * modal-mask intercepts pointer events on the navigation, so it must be
 * closed before any nav click.
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const supportDialog = page.locator('[data-testid-modal="cn-support-dialog"]')
	if (await supportDialog.isVisible().catch(() => false)) {
		await supportDialog.getByRole('button', { name: 'Close' }).click().catch(() => {})
		await supportDialog.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {})
	}
}

/**
 * Land on the app (a route that resolves), dismiss the support dialog, then
 * click a sidebar nav entry by its visible label to reach the target view.
 */
export async function navTo(page: Page, label: string): Promise<void> {
	await page.goto('/index.php/apps/procest/cases')
	await dismissSupportDialog(page)
	await sidebarNav(page).getByRole('link', { name: label, exact: true }).click()
	await dismissSupportDialog(page)
}
