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

/**
 * Console / network errors that originate from Nextcloud core or the test
 * environment — NOT from the procest app — and must not fail a procest
 * coverage test. The dev instance emits a 500 on the core user-status
 * endpoint on every page load (`core: Failed to load user status`), which
 * surfaces as an un-attributed "Failed to load resource" console error.
 */
const NON_PROCEST_NOISE = [
	'favicon',
	'status.php',
	'Download the Vue Devtools',
	'Download the React',
	'user status',           // core: Failed to load user status
	'/apps/user_status/',
	'Failed to load resource: the server responded with a status of 500', // generic, un-attributed
]

/**
 * Attach console-error + 5xx listeners and return a live array of
 * procest-origin errors. Filters out known Nextcloud-core / environment
 * noise so a test fails only on errors the app itself is responsible for.
 * Read the returned array AFTER the page has settled.
 */
export function trackProcestErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const text = m.text()
		if (NON_PROCEST_NOISE.some((n) => text.includes(n))) return
		errors.push(text)
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && r.url().includes('/apps/procest/')) {
			errors.push(`HTTP ${r.status()} ${r.url()}`)
		}
	})
	return errors
}
