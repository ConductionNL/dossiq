/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
export const sidebarNav = (page: Page) =>
	page.locator('[id^="app-navigation"]').first()

/**
 * Dismiss the "Support Procest" dialog if it is open. The dialog's
 * modal-mask intercepts pointer events on the navigation, so it must be
 * closed before any nav click.
 * @param page
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const supportDialog = page.locator('[data-testid-modal="cn-support-dialog"]')
	if (await supportDialog.isVisible().catch(() => false)) {
		await supportDialog
			.getByRole('button', { name: 'Close' })
			.click()
			.catch(() => {})
		await supportDialog
			.waitFor({ state: 'hidden', timeout: 5000 })
			.catch(() => {})
	}
}

/**
 * Read every sidebar link as `{ label, href }` straight out of the DOM.
 *
 * Deliberately NOT `getByRole('link', …)`: most nav leaves live inside a
 * COLLAPSED group, so they are present in the DOM but `display:none`. A
 * role/visibility-based locator cannot see them, and the old implementation
 * therefore fell through to clicking a locator that matched nothing.
 * @param page
 */
async function readNavLinks(
	page: Page,
): Promise<Array<{ label: string; href: string | null }>> {
	return await sidebarNav(page)
		.locator('a')
		.evaluateAll((els) =>
			els.map((e) => ({
				label: (e.textContent || '').trim().replace(/\s+/g, ' '),
				href: e.getAttribute('href'),
			})),
		)
}

/**
 * Navigate to a procest view by its sidebar label.
 *
 * WHY THIS DEEP-LINKS INSTEAD OF CLICKING
 * ---------------------------------------
 * This helper used to land on the app root and CLICK the sidebar entry,
 * because of a belief — stated in this file for months — that "a cold deep
 * link resets the history-mode router to the Dashboard". Measured on a CI
 * runner (2026-08-04), that is false: `/index.php/apps/procest/cases`,
 * `/my-work`, `/doorlooptijd` and `/tasks` each render their own view on a
 * direct GET.
 *
 * The click path, by contrast, was the single largest cause of CI failure.
 * The nav renders its leaves inside COLLAPSED groups ("Work queue",
 * "Reports", "Personal settings"), so `My work`, `Workflow board`,
 * `Processing time`, `Proposals`, `Objections` and `Appeals` are all
 * `display:none` on load. Playwright's `.click()` waits for actionability,
 * and this suite sets no `actionTimeout`, so each such click blocked for the
 * ENTIRE 60s test budget and then failed with a bare timeout that named an
 * element rather than the cause. 122 tests × up to 2×60s is what pushed the
 * job past the shared workflow's 45-minute cap, where it was cancelled having
 * run only 65 tests.
 *
 * Resolving the label to its `href` and navigating directly is immune to
 * collapsed groups, needs no group-expansion bookkeeping, and is faster.
 * @param page
 * @param label exact sidebar label, e.g. 'Cases', 'My work'
 */
export async function navTo(page: Page, label: string): Promise<void> {
	await page.goto('/index.php/apps/procest')
	await dismissSupportDialog(page)

	const links = await readNavLinks(page)
	// Prefer a real navigating entry; a group header renders as href="#".
	const target = links.find((l) => l.label === label && l.href && l.href !== '#')

	if (!target || !target.href) {
		// Fail FAST and by NAME. The old code swallowed this into two
		// full-length action timeouts and then silently asserted against the
		// Dashboard, so a renamed nav label surfaced as an unrelated
		// "element not found" 60s later.
		const available = links
			.filter((l) => l.href && l.href !== '#')
			.map((l) => l.label)
		throw new Error(
			`[procest e2e] navTo('${label}'): no navigating sidebar link with that exact label.\n`
				+ `Available navigating labels: ${JSON.stringify(available)}`,
		)
	}

	await page.goto(target.href)
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
	// A direct GET renders the target view — measured on a CI runner
	// (2026-08-04) for /cases, /my-work, /doorlooptijd and /tasks. The previous
	// `$router.push` dance existed only to work around a deep-link reset that
	// does not actually happen, and it silently went nowhere whenever the
	// router handle could not be reached (returning as if it had navigated).
	await page.goto(`/index.php/apps/procest${route}`)
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
		await page
			.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
			.catch(() => {})
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
 * Request URLs whose console errors are environment noise, matched against the
 * console message's LOCATION rather than its text.
 *
 * A failed subresource logs the bare text "Failed to load resource: the server
 * responded with a status of 404 (Not Found)" — the URL appears only in
 * `location()`. Filtering that text outright would hide real procest 404s, so
 * match the URL instead.
 *
 * procest probes optional cross-app capabilities on load (e.g. whether the
 * hermiq assistant is installed). On an instance that does not ship the other
 * app those probes 404 BY DESIGN, which is not a procest defect — the CI
 * instance installs only openregister alongside procest.
 */
const NON_PROCEST_URL_NOISE = [
	'/apps/hermiq/',
	'/apps/user_status/',
	'/status.php',
	'favicon',
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
		const url = m.location()?.url ?? ''
		if (url && NON_PROCEST_URL_NOISE.some((n) => url.includes(n))) return
		errors.push(text)
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && r.url().includes('/apps/procest/')) {
			errors.push(`HTTP ${r.status()} ${r.url()}`)
		}
	})
	return errors
}
