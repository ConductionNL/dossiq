/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for admin-settings spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Nextcloud admin settings are served at /settings/admin/dossiq.
 * The AdminRoot.vue component renders inside Nextcloud's settings framework.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import {
	captureStorageState,
	STORAGE_STATE,
	storageStatePath,
} from '../helpers/auth.ts'
import { deleteObject, getRequestToken } from '../helpers/fixtures.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

/** A settings page every signed-in account may read, used as the live-session control. */
const PERSONAL_SETTINGS_URL = '/settings/user'

/**
 * The regular, non-admin account the access-control scenario is about.
 *
 * `ci-seed.sh` owns it, logs `created the non-admin user e2euser`, and fails
 * the seed if the account holds admin group membership. Nothing here
 * provisions it: provisioning is a password-confirmation protected action and
 * this file runs too late in the project for that window.
 */
const PLAIN_USER = process.env.E2E_USER_NAME || 'e2euser'

/** Its password. */
const PLAIN_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

/** Admin request context, for the stored-state assertions below. */
let api: APIRequestContext
/** Its CSRF request-token. */
let token = ''
/** Every caseType this file wrote, so teardown can remove each one. */
const seededCaseTypes: string[] = []

test.describe('Admin Settings spec coverage', () => {
	// The Nextcloud admin settings page mounts AdminRoot.vue's fourteen
	// CnSettingsSections, each of which queries OpenRegister on mount. Under
	// the CI `php -S` server that load is both slow and highly variable —
	// measured between ~7s and 3.2m across runs — which made these tests fail
	// intermittently with a bare "Test timeout exceeded" while their identical
	// siblings passed. test.slow()'s tripled 180s was itself overrun once, so
	// set the budget explicitly rather than relying on the multiplier.
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		// Named, not inherited: `browser.newContext()` merges the project's
		// `use`, so an omitted storageState resolves to whatever the default
		// happens to be — which is how a "non-admin cannot" test comes to run as
		// the admin.
		api = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		for (const id of seededCaseTypes) {
			await deleteObject(api, token, 'caseType', id).catch(() => {})
		}
		await api?.dispose()
	})

	// @e2e openspec/specs/admin-settings/spec.md#admin-settings-page-is-accessible
	test('admin settings page is accessible to admin users', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		// Nextcloud admin settings should render — not redirect to login
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })
		// The AdminRoot.vue component renders "Case Type Management" section heading
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
	})

	/**
	 * The scenario names a REGULAR, SIGNED-IN user and the status 403.
	 *
	 * It used to be probed with `storageState: undefined`, which is an
	 * ANONYMOUS caller, asserting `not.toBe(200)`. Nextcloud refuses an
	 * anonymous caller at the session check, before any admin check runs, so
	 * that test was green on a build where every logged-in account could read
	 * the admin settings — the case the scenario is entirely about. It was the
	 * single worst citation in the repo for that reason.
	 *
	 * Three things make the rewrite decisive:
	 *
	 *  1. The identity is NAMED and asserted before the probe. A refusal is
	 *     worth what you know about who was refused.
	 *  2. A live-session control (`/settings/user` -> 200) separates "this
	 *     account was turned away from the admin section" from "this context
	 *     has no session at all", which the status code alone cannot.
	 *  3. The status asserted is 403 exactly, the one the requirement names.
	 *     Nextcloud reaches it through `NotAdminException`, which carries
	 *     `Http::STATUS_FORBIDDEN`, when `getAllowedAdminSettings()` answers
	 *     empty for the section.
	 */
	// @e2e openspec/specs/admin-settings/spec.md#regular-users-cannot-access-admin-settings
	test('a signed-in non-admin is refused the admin settings endpoint with 403', async ({
		browser,
		playwright,
		baseURL,
	}) => {
		const plainState = storageStatePath(PLAIN_USER)
		await captureStorageState(browser, {
			baseURL: String(baseURL ?? BASE_URL),
			user: PLAIN_USER,
			password: PLAIN_PASS,
			statePath: plainState,
		})
		const ctx = await playwright.request.newContext({
			baseURL: baseURL ?? BASE_URL,
			storageState: plainState,
		})

		try {
			// 1. Name the identity.
			const whoami = await ctx.get('/ocs/v2.php/cloud/user?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(
				whoami.ok(),
				`the ordinary session must answer whoami, got ${whoami.status()}`,
			).toBeTruthy()
			const me: any = (await whoami.json())?.ocs?.data
			expect(String(me?.id ?? '')).toBe(PLAIN_USER)
			if (Array.isArray(me?.groups) === true) {
				expect(
					me.groups,
					`${PLAIN_USER} must hold no admin membership, or it cannot stand in `
						+ 'for a regular user',
				).not.toContain('admin')
			}

			// 2. The control: this session IS live and IS allowed somewhere.
			const personal = await ctx.get(PERSONAL_SETTINGS_URL, {
				maxRedirects: 0,
				headers: { Accept: 'text/html' },
			})
			expect(
				personal.status(),
				'the regular account must be able to read its own settings, or the '
					+ 'refusal below is about a dead session rather than about admin rights',
			).toBe(200)

			// 3. The requirement.
			const res = await ctx.get(ADMIN_SETTINGS_URL, {
				maxRedirects: 0,
				headers: { Accept: 'text/html' },
			})
			expect(
				res.status(),
				`direct URL access to ${ADMIN_SETTINGS_URL} by ${PLAIN_USER} must be `
					+ '403; anything else means a regular account can reach the Dossiq '
					+ 'admin settings',
			).toBe(403)
		} finally {
			await ctx.dispose()
		}
	})

	/**
	 * The anonymous probe, kept because it is cheap and because losing it would
	 * be a regression. It deliberately carries NO `@e2e` anchor: the scenario
	 * above is about a signed-in regular user, and an anonymous 401 says
	 * nothing about one.
	 */
	test('an anonymous caller does not receive the admin settings page', async () => {
		const ctx = await request.newContext({
			// Single source of truth — see tests/e2e/base-url.ts.
			baseURL: BASE_URL,
			storageState: undefined,
		})
		const res = await ctx.get(ADMIN_SETTINGS_URL, { maxRedirects: 0 })
		expect(res.status()).not.toBe(200)
		await ctx.dispose()
	})
})
