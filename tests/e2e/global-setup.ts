/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
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

import type { FullConfig } from '@playwright/test'

import { chromium, request } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'
import { captureStorageState, STORAGE_STATE } from './helpers/auth.ts'
import { getRequestToken, sweepFixtureResidue } from './helpers/fixtures.ts'
import { assertOccReachable } from './helpers/occ.ts'
import { residueMinAgeMs, sweepsAllResidue } from './helpers/residue.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'dossiq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/dossiq`.
 * On a fresh CI VM the shared quality.yml workflow runs `npm ci` +
 * `npx playwright install` but never `npm run build`, so without the
 * bundle the rendered page loads a 404 script tag and the Vue app
 * never mounts — every selector wait then times out.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}

	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
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

/**
 * Confirm the suite can run `occ` on the instance under test, BEFORE any spec
 * runs.
 *
 * The suite is otherwise pure HTTP, but `dossiq/case` declares
 * `x-openregister-archival` and OpenRegister refuses an archival record on every
 * HTTP delete route it serves (openregister#3428). The only sanctioned removal
 * is `occ openregister:objects:purge --force --apply`, so a rig where occ is out
 * of reach is a rig where teardown cannot remove a single case.
 *
 * Deliberately fatal, and deliberately here. Left to teardown it would surface
 * as a survivor list half an hour in, after the run had already seeded the data
 * it could not clear; this way it is one step failure naming exactly what to
 * set. The probe also proves the deployed OpenRegister actually HAS the command,
 * which is the other half of the same question.
 */
async function ensureOccReachable(): Promise<void> {
	const invocation = await assertOccReachable()
	console.log(`[playwright globalSetup] occ reachable via ${invocation}`)
}

async function globalSetup(config: FullConfig): Promise<void> {
	// Whatever the active config resolved, else the single shared resolver.
	// Deliberately no `?? 'http://localhost:8080'` tail: off CI that literal is
	// the SHARED dev container, and this setup logs in and writes storage state
	// against it. See tests/e2e/base-url.ts — it throws instead.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? BASE_URL
	const user = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
	const password =
		process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	await ensureOccReachable()

	// The login itself, and the two overlay dismissals that go with it, live in
	// `helpers/auth.ts#captureStorageState`. They were inline here until
	// `dashboard-tiles.spec.ts` needed a SECOND session — its `my-work` widget
	// filters on `assignee: @me`, so as the admin it can never be asserted
	// against the demo caseload the admin also owns. Two copies of a login this
	// full of load-bearing quirks would only stay in step until the first one
	// was fixed alone.
	const browser = await chromium.launch()
	try {
		await captureStorageState(browser, {
			baseURL,
			user,
			password,
			statePath: STORAGE_STATE,
		})
	} finally {
		await browser.close()
	}

	await clearFixtureResidue(baseURL)
}

/**
 * Delete every object an earlier fixture run left on this instance.
 *
 * CI builds a throwaway Nextcloud per run, so this is a no-op there. A
 * developer rig is the case it exists for: the suite seeds cases, caseTypes,
 * statusTypes and workflowTemplates, and until now a run that was interrupted
 * before its teardown — or one whose cases the archival schema refused to
 * delete — left them behind. Eleven runs on one rig accumulated 68 cases, 33 of
 * them fixture leftovers, and the sixth soft-deleted statusType they still
 * pointed at is what made `spec-coverage/ui-pages.spec.ts:55` fail on a second
 * run for a reason no change had introduced.
 *
 * It removes only residue OLDER than any running suite's fixtures can be (see
 * `sweepFixtureResidue` and `helpers/residue.ts`). Several sessions run this
 * suite against the same shared developer instance, and a family-wide sweep
 * here used to delete another session's fixtures mid-run.
 *
 * Failure here is reported, not thrown: a residue sweep that cannot reach the
 * API should not stop the suite from running and saying so itself.
 *
 * @param baseURL The resolved Nextcloud base URL.
 */
async function clearFixtureResidue(baseURL: string): Promise<void> {
	const api = await request.newContext({
		baseURL,
		storageState: STORAGE_STATE,
	})
	try {
		const token = await getRequestToken(api)
		console.log(
			sweepsAllResidue()
				? '[playwright globalSetup] residue sweep: ALL fixture residue, trash included (DOSSIQ_E2E_SWEEP_ALL_RESIDUE is set)'
				: `[playwright globalSetup] residue sweep: fixture residue older than ${residueMinAgeMs() / 60_000} minutes; newer rows may be another run's and are left alone`,
		)
		const survivors = await sweepFixtureResidue(api, token)
		if (survivors.length > 0) {
			console.warn(
				'[playwright globalSetup] fixture residue that could NOT be removed: '
					+ survivors.join(', '),
			)
		}
	} catch (error) {
		console.warn(
			`[playwright globalSetup] fixture residue sweep skipped: ${String(error)}`,
		)
	} finally {
		await api.dispose()
	}
}

export default globalSetup
