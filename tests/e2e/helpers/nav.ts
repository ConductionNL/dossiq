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

/**
 * The app's sidebar navigation container.
 * @param page
 */
export const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

/**
 * Dismiss the "Support Procest" dialog if it is open. The dialog's
 * modal-mask intercepts pointer events on the navigation, so it must be
 * closed before any nav click.
 * @param page
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
 *
 * After the procest-nav-dedup-and-grouping pass several leaves moved under
 * collapsible groups (e.g. the "Cases" GROUP header is a non-navigating
 * `href="#"` toggle whose list leaf is "All cases"). If the requested label
 * resolves to such a group toggle, expand it and click the same-group leaf.
 * @param page
 * @param label
 */
/**
 * Stale top-level labels that became collapsible GROUP headers in the
 * nav-dedup-and-grouping pass, mapped to their actual navigating list leaf.
 * Lets older specs keep calling `navTo(page, 'Cases')` and still land on the
 * case list rather than just toggling the (non-navigating) group header.
 */
const GROUP_LEAF_ALIAS: Record<string, string> = {
	Cases: 'All cases',
}

export async function navTo(page: Page, label: string): Promise<void> {
	// Land on the app ROOT (resolves to the Dashboard reliably). A cold deep
	// link to a sub-route like /cases resets the history-mode router back to
	// the Dashboard, leaving the target view unrendered — so always reach the
	// target by a client-side sidebar click from a resolved root.
	await page.goto('/index.php/apps/procest')
	await dismissSupportDialog(page)
	const effectiveLabel = GROUP_LEAF_ALIAS[label] ?? label
	const candidates = sidebarNav(page).getByRole('link', { name: effectiveLabel, exact: true })
	const count = await candidates.count()
	// A label can match both a collapsible GROUP header (href="#") and a
	// same-named navigating leaf (e.g. the "Subsidies" group + "Subsidies"
	// list). Prefer the real navigating link; otherwise expand the group.
	let chosen = candidates.first()
	for (let i = 0; i < count; i++) {
		const c = candidates.nth(i)
		const h = await c.getAttribute('href').catch(() => null)
		if (h && h !== '#') { chosen = c; break }
	}
	const href = await chosen.getAttribute('href').catch(() => null)
	if (href === '#' || href === null) {
		// Group toggle (or no direct link) — expand it so its leaves render.
		await chosen.click().catch(() => {})
		await dismissSupportDialog(page)
	} else {
		await chosen.click()
	}
	await dismissSupportDialog(page)
}

/**
 * Navigate to a procest route that has no sidebar nav entry (e.g. the global
 * Tasks list, which the nav-dedup pass dropped as a top-level leaf). A direct
 * deep-link resets the history-mode router to the Dashboard, so land on a
 * resolving route first and then push the target route client-side via a
 * sidebar link is not possible — instead navigate from within the loaded SPA.
 * @param page
 * @param route in-app vue-router path, e.g. '/tasks'
 */
export async function navToRoute(page: Page, route: string): Promise<void> {
	await page.goto('/index.php/apps/procest')
	await dismissSupportDialog(page)
	// Drive vue-router directly. A bare deep-link `goto` resets the
	// history-mode router to the Dashboard, and pushState/popstate does not
	// re-run vue-router's guards — `$router.push` is the only reliable
	// client-side navigation for a route that has no sidebar link.
	await page.evaluate((r) => {
		const els = document.querySelectorAll('*')
		for (const el of els) {
			// @ts-expect-error Vue 2 attaches the instance as __vue__
			const vm = el.__vue__
			if (vm && vm.$router) { vm.$router.push(r); return }
		}
	}, route)
	await page.waitForTimeout(800)
	await dismissSupportDialog(page)
}

/**
 * The procest admin settings page (`/settings/admin/procest`) renders its many
 * sections progressively — the lower ones (Case Email — Shared Mailbox,
 * KCC-werkplek Integration, …) only mount once scrolled near. Scroll to the
 * bottom in steps so every section's heading + fields are in the DOM before a
 * test asserts on them, then return to the top.
 * @param page
 */
export async function loadAllAdminSections(page: Page): Promise<void> {
	// The admin page mounts its lower sections lazily as the viewport scrolls
	// near them, so drive real Playwright scrolls to the document bottom in a
	// few steps. The page does expensive layout on each scroll; the calling
	// tests use test.slow() so the per-test budget covers it.
	for (let i = 0; i < 4; i++) {
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {})
		await page.waitForTimeout(500)
	}
	await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {})
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
	'user status', // core: Failed to load user status
	'/apps/user_status/',
	'Failed to load resource: the server responded with a status of 500', // generic, un-attributed
	// The dev/test instance serves a strict CSP (script-src 'nonce-…') with no
	// explicit worker-src, so the browser blocks registering procest's
	// service-worker.js. This is a CSP-hardening artifact of the test rig, not
	// an app-logic error — the page renders fine without the SW. (The URL is
	// procest-scoped so it is not caught by the generic filters above.)
	'service-worker.js',
	'worker-src',
	'violates the following Content Security Policy',
]

/**
 * Attach console-error + 5xx listeners and return a live array of
 * procest-origin errors. Filters out known Nextcloud-core / environment
 * noise so a test fails only on errors the app itself is responsible for.
 * Read the returned array AFTER the page has settled.
 * @param page
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
