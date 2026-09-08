/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the e2e suite obtains a browser session.
 *
 * `captureStorageState` is the login that `global-setup.ts` performs once for
 * the admin. It lives here rather than there because it is no longer the only
 * caller: a spec whose subject is scoped to the CURRENT USER cannot assert
 * anything stable while it runs as the admin, whose queue also holds the demo
 * caseload. `dashboard-tiles.spec.ts` provisions its own account and captures
 * its session with this same function, so the ACCOUNT is the only difference
 * between the two sessions — not a second, subtly different login that has to
 * be kept in step with this one by hand.
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import * as fs from 'fs'
import path from 'path'

export const STORAGE_STATE = path.join(__dirname, '..', '.auth', 'user.json')

/**
 * Where a named account's captured session is written.
 *
 * @param uid The account's Nextcloud user id.
 * @return Absolute path under `tests/e2e/.auth/`, which `.gitignore` covers.
 */
export function storageStatePath(uid: string): string {
	return path.join(__dirname, '..', '.auth', `${uid}.json`)
}

/**
 * Create a Nextcloud account if it is not already there.
 *
 * Over the OCS provisioning API rather than `occ`, deliberately. `occ` is
 * reached through a command PREFIX (`docker exec -u www-data <name> php occ`),
 * and `occ user:add --password-from-env` reads `OC_PASS` from the environment
 * of the process that runs occ — inside the container, where an env var set
 * out here never arrives. Provisioning is plain HTTP and needs no such hole.
 *
 * Idempotent: OCS answers `102` when the account already exists, which is the
 * outcome every run after the first wants.
 *
 * @param api      Request context authenticated as an ADMIN.
 * @param token    CSRF request-token — a session-bearing POST needs it even
 *                 with `OCS-APIRequest`, which only short-circuits the check
 *                 for a request that carries no session cookie at all.
 * @param uid      The account to create.
 * @param password Its password. Must satisfy the instance's password policy.
 */
export async function ensureUser(
	api: APIRequestContext,
	token: string,
	uid: string,
	password: string,
): Promise<void> {
	const res = await api.post('/ocs/v2.php/cloud/users?format=json', {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
		},
		form: { userid: uid, password, displayName: uid },
	})
	const body: any = await res.json().catch(() => ({}))
	const status = Number(body?.ocs?.meta?.statuscode ?? -1)

	// The OCS STATUSCODE decides, not the HTTP status. "User already exists" is
	// OCS 102 and arrives with HTTP 400, so reading `res.ok()` first turns the
	// idempotent case — the one every run after the first takes — into a hard
	// failure on the second run against the same instance.
	//
	// 100/200 = created, 102 = already exists. Anything else is real, and is
	// reported with the message OCS gave, which names the cause (a password
	// policy, a disabled provisioning API, a non-admin session).
	if (status === 100 || status === 200 || status === 102) {
		return
	}
	throw new Error(
		`Could not provision the e2e account "${uid}": HTTP ${res.status()}, `
			+ `OCS ${status} ${String(body?.ocs?.meta?.message ?? '')}`,
	)
}

/**
 * Log `user` in through the web login form and persist the session.
 *
 * Moved here verbatim from `global-setup.ts`, quirks and all — every one of
 * them is load-bearing and is annotated where it sits.
 *
 * @param browser A launched browser.
 * @param options baseURL, credentials and where to write the session.
 * @return The path the session was written to.
 */
export async function captureStorageState(
	browser: Browser,
	options: {
		baseURL: string
		user: string
		password: string
		statePath: string
	},
): Promise<string> {
	const { baseURL, user, password, statePath } = options
	fs.mkdirSync(path.dirname(statePath), { recursive: true })

	// ⚠️ An EXPLICIT empty session, not merely an omitted one. Called from
	// global setup this makes no difference — that browser is launched raw and
	// has no options to inherit. Called from a SPEC it is the whole thing:
	// Playwright's test-scoped `browser` merges the config's `use` into every
	// `newContext()`, so an omitted `storageState` silently becomes the admin's
	// session, `/index.php/login` redirects straight back out to the dashboard,
	// and the login form this waits for never renders — a 30s timeout that
	// names `input[name="user"]` rather than the session it inherited.
	const context = await browser.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	try {
		// `domcontentloaded` (not the default `load`) so first-paint themed-asset
		// compilation on a cold instance doesn't blow the 30s navigation budget;
		// the form inputs we need are in the initial HTML. Retry once on a spike.
		try {
			await page.goto('/index.php/login', {
				waitUntil: 'domcontentloaded',
				timeout: 60_000,
			})
		} catch {
			await page.goto('/index.php/login', {
				waitUntil: 'domcontentloaded',
				timeout: 60_000,
			})
		}
		await page
			.locator('input[name="user"]')
			.waitFor({ state: 'visible', timeout: 30_000 })
		await page.locator('input[name="user"]').fill(user)
		await page.locator('input[name="password"]').fill(password)
		// The themed NC submit button sometimes swallows a plain .click() (the
		// click lands but no navigation is scheduled). Submit the form directly so
		// the POST always fires; fall back to the button click if no form is found.
		const submitted = await page.evaluate(() => {
			const form =
				document.querySelector('form[action*="login"]')
				|| document.querySelector('form')
			if (
				form
				&& typeof (form as HTMLFormElement).requestSubmit === 'function'
			) {
				;(form as HTMLFormElement).requestSubmit()
				return true
			}
			return false
		})
		if (submitted === false) {
			await page
				.locator('button[type="submit"], input[type="submit"]')
				.first()
				.click()
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
				`Login as "${user}" appears to have failed — still on ${currentUrl}.`,
			)
		}

		await seedOverlayDismissals(page)
		await disableFirstRunWizard(page)
		await context.storageState({ path: statePath })
		return statePath
	} finally {
		await context.close()
	}
}

/**
 * Turn off Nextcloud's OWN first-run wizard for this account.
 *
 * Not a dossiq overlay and not browser-local: `firstrunwizard` is a shipped
 * Nextcloud app, and its "show" flag is per-USER server state, so seeding
 * localStorage does nothing for it and a fresh account gets it however clean
 * the profile is. It renders as `#firstrunwizard.modal-mask--opaque`, and an
 * opaque modal mask intercepts every pointer event on the app behind it — the
 * failure it produces is a click that retries until the action budget is gone
 * and then names the ROW it could not reach, never the modal over it.
 *
 * `DELETE /apps/firstrunwizard/wizard` is the app's only route and is what its
 * own close button calls. A 404 means the app is not installed on this
 * instance, which is a perfectly good outcome; anything else is reported,
 * because a wizard that silently stayed armed is the whole problem.
 *
 * @param page A page already authenticated as the account being captured.
 */
async function disableFirstRunWizard(page: Page): Promise<void> {
	const status = await page.evaluate(async () => {
		const token =
			(window as any).OC?.requestToken
			?? document.head
				.querySelector('meta[name="requesttoken"]')
				?.getAttribute('content')
			?? ''
		const res = await fetch('/index.php/apps/firstrunwizard/wizard', {
			method: 'DELETE',
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		})
		return res.status
	})
	if (status !== 404 && (status < 200 || status >= 300)) {
		throw new Error(
			`Could not disable Nextcloud's first-run wizard: DELETE `
				+ `/apps/firstrunwizard/wizard -> ${status}. Left armed, its opaque `
				+ 'modal mask swallows every click on the app for this account.',
		)
	}
}

/**
 * Suppress the two browser-local overlays that cover the app on a fresh
 * profile.
 *
 * The product walkthrough (ADR-043) mounts a modal spotlight tour
 * (`.cn-walkthrough`) on first visit, whose full dim layer intercepts pointer
 * events and blocks every sidebar click. Its "seen" marker is browser-local
 * (`cn-walkthrough-seen:<appId>` in localStorage), so a fresh Playwright
 * context always re-triggers it. Seeding the marker with a high sentinel
 * version sorts every tour step's `sinceVersion` below it, so the tour composes
 * to an empty step set (see useWalkthrough's compareSemver gate).
 *
 * The NON-GATING first-time-setup wizard (ADR-042) is the same problem with a
 * different overlay: its modal-mask subtree intercepts every click on the app
 * behind it, and specs that click the sidebar without dismissing anything then
 * time out suite-wide. Its dismissal key is per manifest `setup.version`, so a
 * generous range is seeded and a version bump cannot silently re-arm it.
 *
 * ⚠️ Both keys are per BROWSER PROFILE, so they are per captured session. A
 * second account gets its own empty profile and needs its own seeding; that is
 * the reason this is part of `captureStorageState` rather than a one-off in
 * global setup.
 *
 * @param page A page already authenticated as the account being captured.
 */
async function seedOverlayDismissals(page: Page): Promise<void> {
	try {
		await page.goto('/apps/dossiq/', {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:dossiq', '999.0.0')
				for (let v = 0; v <= 20; v++) {
					window.localStorage.setItem(
						`cn-setup-wizard-dismissed:dossiq:${v}`,
						'1',
					)
				}
			} catch {
				// localStorage unavailable — tour dismissal falls back to helper clicks.
			}
		})
	} catch {
		// App origin unreachable here is non-fatal; specs still run, tours dismiss via helper.
	}
}

export async function login(page: Page, user?: string, password?: string) {
	const username = user ?? process.env.ADMIN_USER ?? 'admin'
	const pass = password ?? process.env.ADMIN_PASSWORD ?? 'admin'

	await page.goto('/index.php/login')
	await page.fill('input[name="user"]', username)
	await page.fill('input[name="password"]', pass)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForURL('**/apps/**', { timeout: 30000 })
}
