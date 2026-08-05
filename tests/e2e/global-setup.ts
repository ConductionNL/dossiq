/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/user.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from the canonical journeydoc template in hydra/templates/journeydoc/.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'
import { STORAGE_STATE } from './helpers/auth'
import { BASE_URL } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'procest-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/procest`.
 * On a fresh CI VM the shared quality.yml workflow runs `npm ci` +
 * `npx playwright install` but never `npm run build`, so without the
 * bundle the rendered page loads a 404 script tag and the Vue app
 * never mounts — every selector wait then times out.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// eslint-disable-next-line no-console
	console.log(`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
				+ 'Make sure the docker container is running and reachable.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

async function globalSetup(config: FullConfig): Promise<void> {
	// Whatever the active config resolved, else the single shared resolver.
	// Deliberately no `?? 'http://localhost:8080'` tail: off CI that literal is
	// the SHARED dev container, and this setup logs in and writes storage state
	// against it. See tests/e2e/base-url.ts — it throws instead.
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined) ?? BASE_URL
	const user = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// `domcontentloaded` (not the default `load`) so first-paint themed-asset
	// compilation on a cold instance doesn't blow the 30s navigation budget;
	// the form inputs we need are in the initial HTML. Retry once on a spike.
	try {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded', timeout: 60_000 })
	} catch {
		await page.goto('/index.php/login', { waitUntil: 'domcontentloaded', timeout: 60_000 })
	}
	await page.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	// The themed NC submit button sometimes swallows a plain .click() (the
	// click lands but no navigation is scheduled). Submit the form directly so
	// the POST always fires; fall back to the button click if no form is found.
	const submitted = await page.evaluate(() => {
		const form = document.querySelector('form[action*="login"]') || document.querySelector('form')
		if (form && typeof (form as HTMLFormElement).requestSubmit === 'function') {
			(form as HTMLFormElement).requestSubmit()
			return true
		}
		return false
	})
	if (submitted === false) {
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
	}
	// Nextcloud bounces to /apps/dashboard/ on success.
	try {
		await page.waitForURL('**/apps/dashboard/**', { timeout: 30_000 })
	} catch {
		// Some NC versions redirect elsewhere; fall back to checking the URL.
	}
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
			+ 'Check ADMIN_USER / ADMIN_PASSWORD (defaults admin/admin).',
		)
	}

	// Suppress the procest product walkthrough (ADR-043) for automated runs: on
	// first visit it mounts a modal spotlight tour (`.cn-walkthrough`) whose full
	// dim layer intercepts pointer events and blocks every sidebar click. Its
	// "seen" marker is browser-local (`cn-walkthrough-seen:<appId>` in
	// localStorage), so a fresh Playwright context always re-triggers it. Seed the
	// marker into the persisted storageState with a high sentinel version — every
	// tour step's `sinceVersion` sorts below it, so the tour composes to an empty
	// step set (see useWalkthrough compareSemver gate) and never shows.
	try {
		await page.goto('/apps/procest/', { waitUntil: 'domcontentloaded', timeout: 60_000 })
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:procest', '999.0.0')
			} catch (e) {
				// localStorage unavailable — tour dismissal falls back to helper clicks.
			}
		})
	} catch {
		// App origin unreachable here is non-fatal; specs still run, tours dismiss via helper.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}

export default globalSetup
