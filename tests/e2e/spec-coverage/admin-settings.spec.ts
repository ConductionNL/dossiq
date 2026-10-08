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
import {
	createObject,
	deleteObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	showObject,
} from '../helpers/fixtures.ts'

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

	/**
	 * 🔴 THIS TEST DECLINED THE SCENARIO IT CITED, IN ITS OWN COMMENT.
	 *
	 * `Empty case type list` is about ONE state: a register holding no case
	 * types. Its THENs are an empty-state message and the add control beside
	 * it. The old body asserted the section heading and the add control, and
	 * said why: "the list body is data-dependent", so it asserted "the
	 * data-independent chrome" instead. Both assertions render identically over
	 * a list of forty case types, which is what this instance normally has, so
	 * the state the scenario is entirely about was the one thing never on
	 * screen while the citation claimed it was covered.
	 *
	 * THE GIVEN IS ESTABLISHED RATHER THAN HOPED FOR. The register is not
	 * emptied — that would destroy an instance for every other spec in the run,
	 * and `case.caseType` points at these objects. The collection read the list
	 * makes is answered with an empty page instead, the same technique
	 * `gis-integration.spec.ts` uses to make the map's count a claim. The
	 * schema segment is read off the app's own config rather than guessed:
	 * `store.js` registers `caseType` under `config.case_type_schema` and falls
	 * back to the slug only when that is blank, so a hard-coded URL would match
	 * nothing on half the instances and leave the real list rendering.
	 *
	 * AND THE INTERCEPT IS COUNTED. A route that matched nothing leaves the
	 * real, populated list on screen; on a rig whose register happens to be
	 * empty every assertion below would then pass without this test having
	 * established anything. The hit count is asserted, so "the fixture did not
	 * apply" can never read as "the requirement holds".
	 *
	 * ONE CLAUSE IS NOT ASSERTED, and saying which is part of the citation. The
	 * scenario's third line is a SHOULD: "the system SHOULD provide guidance
	 * (e.g. 'Create your first case type to start managing cases')". No such
	 * sentence ships. `CaseTypeList` passes no `emptyText`, so the message is
	 * `CnIndexPage`'s default, which names the absence without saying what to
	 * do about it. Writing the assertion first and the copy afterwards would be
	 * the tail wagging the dog; the gap is recorded here and in the spec's own
	 * wording, which already marks it as the weakest of the three.
	 *
	 * ✅ MUTATION CHECK RUN 2026-09-12, with `tests/e2e/helpers/mutate-bundle.ts`.
	 * The served bundle was rewritten on its way to the browser, so no PHP, no
	 * disk and no other session's instance moved. The break, and what it
	 * produced:
	 *
	 *   chunk   dossiq-shared-nc-vue.js
	 *   find    /emptyText:\{type:String,default:"No items found"\}/
	 *   replace 'emptyText:{type:String,default:""}'
	 *   page    an empty `<div class="cn-index-page__empty">` with its icon and
	 *           no sentence
	 *   red on  "the empty state must carry a message a reader can act on",
	 *           Expected: not "", Received: ""
	 *
	 * 🔴 AND THE TWO ASSERTIONS THIS REPLACED STAYED GREEN UNDER THAT SAME
	 * BREAK. The mutation touches the empty-state copy and nothing else, so the
	 * Case Type Management heading and the add control the old body asserted
	 * both still render; the heading assertion ran and passed on that very run,
	 * twenty lines above the failure.
	 *
	 * ✅ AND THE FIXTURE GUARD FIRED FOR REAL, on the first run of this test:
	 * the settings ids turned out to live under a `config` key rather than at
	 * the top level, the route matched nothing, the real forty-row list
	 * rendered, and the run stopped on "the case-type collection read must have
	 * been answered with an empty page, or nothing below establishes the
	 * scenario" instead of reporting a green that meant nothing.
	 */
	// @e2e openspec/specs/admin-settings/spec.md#empty-case-type-list
	test('with no case types the list says so, and still offers the add control', async ({
		page,
	}) => {
		// Where the app looks for case types on THIS instance.
		const cfgRes = await api.get('/index.php/apps/dossiq/api/settings', {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		})
		expect(
			cfgRes.ok(),
			`reading the dossiq settings -> ${cfgRes.status()}`,
		).toBe(true)
		// ⚠️ THE IDS ARE UNDER `config`, not at the top level. The endpoint
		// answers `{success, openRegisters, isAdmin, config: {register, …}}`,
		// and reading `cfg.register` gave `undefined` on every instance: the
		// fallbacks below then produced `/objects/dossiq/caseType`, the route
		// matched nothing, and the real forty-row list rendered. The hit count
		// asserted further down is what turned that into a failure naming the
		// fixture instead of a green naming nothing.
		const config = (await cfgRes.json())?.config ?? {}
		const register = String(config.register || 'dossiq')
		// The same fallback `src/store/store.js` applies, for the same reason:
		// the numeric schema id can be blank on a fresh register, and the
		// object API resolves the canonical slug too.
		const schema = String(config.case_type_schema || 'caseType')
		const collection = `/apps/openregister/api/objects/${register}/${schema}`

		let intercepted = 0
		await page.route(
			(url) => url.pathname.endsWith(collection),
			(route) => {
				if (route.request().method() !== 'GET') {
					return route.fallback()
				}
				intercepted++
				return route.fulfill({ json: { results: [], total: 0 } })
			},
		)

		await page.goto(ADMIN_SETTINGS_URL)
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })

		// Scope every assertion below to the case-type section. The admin page
		// mounts fourteen sections, several of them CnIndexPages of their own,
		// and an empty state belonging to any other one would satisfy an
		// unscoped locator.
		const section = page.locator('.case-type-admin')
		await expect(section).toBeVisible({ timeout: 30_000 })

		// THE GIVEN, read back: the list is empty because the intercept
		// answered it, and not because the page failed to ask.
		expect(
			intercepted,
			'the case-type collection read must have been answered with an empty '
				+ 'page, or nothing below establishes the scenario',
		).toBeGreaterThan(0)
		await expect(
			section.locator('[data-testid="cn-object-row"]'),
			'the list must hold no case types',
		).toHaveCount(0)

		// THEN an empty-state message. `CnIndexPage` renders it in
		// `.cn-index-page__empty`, and the text is what a reader is told;
		// asserting the container alone would pass over an empty box.
		const empty = section.locator('.cn-index-page__empty')
		await expect(
			empty,
			'a register with no case types must say so, not render a blank list',
		).toBeVisible({ timeout: 30_000 })
		await expect(
			empty,
			'the empty state must carry a message a reader can act on',
		).not.toHaveText('')

		// AND the add control, in the same state. Prominent is not a thing a
		// test can read, but absent is: the scenario exists because an empty
		// list with no way out of it is a dead end.
		await expect(
			section.getByRole('button', { name: /Add (Item|Case Type)/ }).first(),
			'the add control must stay on screen when the list is empty',
		).toBeVisible({ timeout: 10000 })
	})

	/**
	 * The scenario has two THENs, and this test now has one assertion for each.
	 *
	 * It used to be an unconditional `test.fixme`, so the body never ran and
	 * nothing about the create surface or the draft default could redden it.
	 * The FIXME's subject was the SAVE control, which #719 says never surfaces
	 * inside even a tripled budget — and which this scenario does not mention.
	 * So the click assertion stops at the detail view the scenario actually
	 * names, and the `isDraft = true` default is asserted where it is decided:
	 * on the STORED object.
	 *
	 * Unparking is safe by measurement, not by argument: the old
	 * FIXME(#719) said this form never surfaces its Save control and
	 * overran even a tripled 180s budget. Run unparked on CI run
	 * 34578033755 it passed first time, so whatever hung has been fixed
	 * (measured by #2480, which unparked the same test independently).
	 *
	 * That default is dossiq's own, declared on `caseType.isDraft` in
	 * `lib/Settings/dossiq_register.json`. Flip it to `false` there, re-import
	 * the register, and this test must go red — a case type that publishes
	 * itself on creation is exactly what the clause exists to prevent, since
	 * `case.caseType` carries `x-relation-filter: {isDraft: false}` and a
	 * published type is immediately offered in the New case picker.
	 */
	// @e2e openspec/specs/admin-settings/spec.md#add-a-new-case-type
	test('adding a case type opens the creation view and stores a draft by default', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
		const addBtn = page
			.getByRole('button', { name: /Add (Item|Case Type)/ })
			.first()
		await expect(addBtn).toBeVisible({ timeout: 10000 })
		await addBtn.click()
		// After clicking Add, CaseTypeAdmin switches to the detail view, which
		// is the "creation form or new case type detail view" the scenario asks
		// for. Its Save control is FIXME(#719) and is not part of the scenario.
		await expect(
			page.getByRole('heading', { name: 'New Case Type' }),
		).toBeVisible({ timeout: 30000 })

		// AND the new case type MUST have `isDraft = true` by default. Asserted
		// on what was stored, not on what the form displayed: a checkbox
		// rendered ticked over a record that saved `false` is the failure this
		// clause is about.
		const created = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} draft default probe`,
			identifier: `${RUN_PREFIX.toLowerCase()}-draft-default`,
			description:
				'Created without isDraft, so the schema default is the only thing that can set it.',
		})
		const id = objectId(created)
		expect(id, 'the probe case type must have an id').not.toBe('')
		seededCaseTypes.push(id)

		const stored = await showObject(api, 'caseType', id)
		expect(
			stored?.isDraft,
			'a case type created without an explicit isDraft must be stored as a '
				+ 'DRAFT; a published default would put it straight into the New case picker',
		).toBe(true)
	})
})
