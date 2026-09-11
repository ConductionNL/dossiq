/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page: every connection Dossiq has to a system outside it,
 * on one page, with a status the app can back.
 *
 * WHAT THIS SPEC IS GUARDING, because none of it fails loudly on its own.
 * The page's whole value is that it does not overstate. A seed row that
 * claimed Configured, a card whose Open settings link points at an anchor no
 * admin section carries, or a probe that ran and wrote nothing all leave the
 * page rendering perfectly while telling the reader something untrue. So the
 * assertions here are about the CONTENT of the claim, not about whether a
 * table drew.
 *
 * Locale: nothing forces the language of the E2E instance, so nothing is
 * asserted on an English label. Status is read back from the saved object over
 * the API, and the page itself is addressed by route, by row identity (the
 * connection's own title, which is seeded here and not translated) and by the
 * href of the settings link.
 *
 * The Required apps section (REQ-ADMIN-021) has no test that can pass yet and
 * is `test.fixme` with the reason: the installed @conduction/nextcloud-vue
 * cannot host a list on a `type: "settings"` page, so the page ships as an
 * index and has nowhere to mount CnLeafDependencySettings.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { captureStorageState, storageStatePath } from './helpers/auth.ts'
import { getRequestToken, listObjects, updateObject } from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/** The connection keys the seed ships, in the order row A34 lists them. */
const SEEDED_KEYS = [
	'zgw',
	'stuf',
	'kcc',
	'dmn',
	'mailbox',
	'store',
	'financial',
	'brp',
	'kvk',
	'pdok',
	'berichtenbox',
	'templates',
]

/**
 * The one connection nothing calls.
 *
 * BRP and KvK were both listed here with the message "Specified, not built
 * yet", and that sentence was false for both. Each ships a dormant Log adapter,
 * a real HTTP adapter and a DI registrar bound from ExternalRegisterRegistrar.
 * The difference is the caller: ConflictOfInterestService injects the BRP
 * adapter for the belangenconflict check, and nothing injects the KvK one, so
 * configuring KvK changes nothing an admin can see. That, and not "not built",
 * is what Not available means on this row.
 */
const UNAVAILABLE_KEYS = ['kvk']

/** Built, called, and dormant until its tier key is set. */
const DORMANT_KEYS = ['brp']

/**
 * The two seams that ship a mock adapter.
 *
 * These are the rows the page did not have and most needed. Both seams WORK:
 * the compose dialog opens, the send succeeds, an id comes back. Neither
 * reaches anything outside this instance. A row that read Configured or Not
 * available would be the same lie the page was built to remove, one layer
 * down, so the invariant asserted below is that they read as neither.
 */
const SIMULATED_KEYS = ['berichtenbox', 'templates']

/** The ordinary user `ci-seed.sh` creates. */
const PLAIN_USER = process.env.E2E_USER_NAME || 'e2euser'
const PLAIN_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

let api: APIRequestContext
let token: string

/**
 * The seeded integration rows, keyed by connection key.
 *
 * @param request The authenticated request context.
 */
async function integrationsByKey(
	request: APIRequestContext,
): Promise<Record<string, any>> {
	const rows = await listObjects(request, 'dossiqIntegration')
	const byKey: Record<string, any> = {}
	for (const row of rows) {
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page and wait for its table.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: any) {
	await page.goto('/apps/dossiq/settings/integrations')
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await api.dispose()
	})

	test('lists the twelve connections in the seeded order', async ({ page }) => {
		const byKey = await integrationsByKey(api)
		expect(Object.keys(byKey).sort()).toEqual([...SEEDED_KEYS].sort())

		const ordered = SEEDED_KEYS.map((k) => Number(byKey[k].order))
		expect([...ordered].sort((a, b) => a - b)).toEqual(ordered)

		await openIntegrations(page)
		for (const key of SEEDED_KEYS) {
			await expect(
				page.getByRole('row', { name: new RegExp(byKey[key].title, 'i') }),
			).toBeVisible({ timeout: 30_000 })
		}
	})

	test('offers a settings link that lands on the section', async ({ page }) => {
		const byKey = await integrationsByKey(api)
		expect(byKey.stuf.settingsUrl).toBe('/settings/admin/dossiq#section-stuf')

		await openIntegrations(page)
		const link = page.locator('a[href="/settings/admin/dossiq#section-stuf"]')
		await expect(link).toBeVisible({ timeout: 30_000 })

		// The cell renderer sets `target="_blank"`, so the settings page opens
		// in a NEW TAB and this one never navigates. Asserting on `page` after
		// the click therefore waits out the whole budget while the link works
		// perfectly. Take the popup instead, which is also what the reader
		// gets: the list they were reading stays where it was.
		const [settings] = await Promise.all([
			page.waitForEvent('popup'),
			link.click(),
		])
		await expect(settings).toHaveURL(/\/settings\/admin\/dossiq#section-stuf$/)
		await expect(settings.locator('#section-stuf')).toBeVisible({
			timeout: 30_000,
		})
		await settings.close()
	})

	test('offers no settings link where there is no section to open', async ({
		page,
	}) => {
		const byKey = await integrationsByKey(api)
		for (const key of [...UNAVAILABLE_KEYS, ...DORMANT_KEYS]) {
			expect(byKey[key].statusMessage).not.toMatch(/not built/i)
			// The seed writes `""` and the register does not store an empty
			// string, so the row reads back with no such key at all. Both
			// spellings say the same thing, and the assertion that matters is
			// the one below: the affordance is absent from the page.
			expect(byKey[key].settingsUrl || '').toBe('')
		}

		await openIntegrations(page)
		for (const key of [...UNAVAILABLE_KEYS, ...DORMANT_KEYS]) {
			const row = page.getByRole('row', {
				name: new RegExp(byKey[key].title, 'i'),
			})
			await expect(row).toBeVisible({ timeout: 30_000 })
			// The whole point: the affordance is ABSENT, not disabled.
			await expect(
				row.locator('a[href^="/settings/admin/dossiq#"]'),
			).toHaveCount(0)
		}
	})

	test('claims nothing it has not checked', async () => {
		const byKey = await integrationsByKey(api)
		for (const key of SEEDED_KEYS) {
			const row = byKey[key]
			if (row.status === 'configured') {
				// Another spec in the same run may legitimately have saved a
				// section. The invariant that must hold either way is that a
				// Configured row carries the evidence for it.
				expect(String(row.checkedAt || '')).not.toBe('')
				continue
			}
			if (UNAVAILABLE_KEYS.includes(key)) {
				expect(row.status).toBe('unavailable')
				continue
			}
			if (DORMANT_KEYS.includes(key)) {
				expect(row.status).toBe('unconfigured')
				expect(row.statusMessage).toMatch(/integration\.brp\.mode/)
				continue
			}
			if (SIMULATED_KEYS.includes(key)) {
				expect(row.status).toBe('simulated')
				continue
			}
			expect(row.status).toBe('unconfigured')
		}
	})

	test('shows the failed mailbox test as an error, with a fresh timestamp', async () => {
		const before = new Date()

		const res = await api.post(
			'/index.php/apps/dossiq/api/settings/email/test-imap',
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: {},
			},
		)
		expect(res.ok(), `test-imap -> ${res.status()}`).toBeTruthy()
		const outcome = await res.json()

		const mailbox = (await integrationsByKey(api)).mailbox
		// The probe writes what it found, whichever way it went: an instance
		// with no IMAP host saved is Not configured, one with an unreachable
		// host is Error. Both are claims backed by the probe; neither is the
		// seed.
		expect(['error', 'unconfigured', 'configured']).toContain(mailbox.status)
		if (outcome.ok === false && outcome.error === 'connection_failed') {
			expect(mailbox.status).toBe('error')
			expect(String(mailbox.statusMessage || '')).not.toBe('')
		}
		expect(new Date(mailbox.checkedAt).getTime()).toBeGreaterThanOrEqual(
			before.getTime() - 60_000,
		)
	})

	test('marks the KCC section configured once it is saved', async () => {
		const res = await api.post('/index.php/apps/dossiq/api/settings', {
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: { identification_method: 'both' },
		})
		expect(res.ok(), `settings save -> ${res.status()}`).toBeTruthy()

		const kcc = (await integrationsByKey(api)).kcc
		expect(kcc.status).toBe('configured')
		expect(String(kcc.checkedAt || '')).not.toBe('')
	})

	/**
	 * THE ONE TEST IN THIS FILE THAT IS NOT ABOUT THE PAGE'S CLAIMS.
	 *
	 * It was written with `browser.newContext({ httpCredentials })` and neither
	 * half of that worked, in opposite directions:
	 *
	 *  1. Playwright's `browser` FIXTURE patches `newContext()` to merge the
	 *     project's `use` options, so `use.storageState` — the ADMIN session
	 *     written by global-setup — came along uninvited. The context reported
	 *     `OC.getCurrentUser().uid === 'admin'` and `OC.isUserAdmin() === true`,
	 *     and the failure screenshot said "Avatar of admin" in the header. The
	 *     test asserted an admin cannot see an admin page, so it could only
	 *     ever fail — and it never ran, because this project is skipped whole
	 *     while its dependency is red.
	 *  2. Clearing the storage state does not rescue basic auth: Nextcloud's
	 *     web entry point does not authenticate an `Authorization: Basic`
	 *     header, it redirects to `/login`. A context with credentials and no
	 *     cookie lands on the login page as ANONYMOUS — where the nav has no
	 *     entries and the route has no rows, so both assertions below pass for
	 *     a reason that has nothing to do with permissions.
	 *
	 * #2305 fixed that identity half, and it is what the setup below does:
	 * `captureStorageState` is the sanctioned second session, and the account
	 * comes from `ci-seed.sh` rather than from an API call, because
	 * provisioning one is password-confirmation protected and this spec runs
	 * too late in the project for that window. Given a genuine non-admin, the
	 * link-count
	 * assertion below does catch the leak — measured, not assumed: run against
	 * an unguarded build it reports `Received: 7`.
	 *
	 * The DESTINATION assertion is here for a different reason, and it is not
	 * that the count is too weak today. It is that "no admin-settings links" is
	 * also what a page that never rendered looks like — a bundle that 404s, a
	 * JS error during mount, a route that silently 500s. Any of those would
	 * satisfy the count while telling us nothing about the guard, and this is
	 * the one test in the suite whose whole job is to be believed. Asserting
	 * where the router actually LANDED distinguishes "the guard turned this
	 * account away" from "nothing rendered", which the count cannot.
	 */
	test('is not reachable by a user who is not an admin', async ({
		browser,
		baseURL,
	}) => {
		// LOG IN, do not send credentials. Basic auth does not authenticate
		// Nextcloud's HTML route here: with the admin jar cleared and
		// `httpCredentials` set, the page came back with no session at all
		// (`OC.getCurrentUser` absent). And with the jar NOT cleared it came back
		// as `uid=admin isAdmin=true`, which is how this test spent its life
		// asserting the admin's view under a name promising the opposite.
		//
		// `captureStorageState` is the sanctioned second session, the one
		// `dashboard-tiles.spec.ts` uses for the same reason, and its own
		// docblock carries the warning this test walked into: an OMITTED
		// `storageState` in a spec silently becomes the admin's.
		// NOT `ensureUser`. Provisioning a user is a password-confirmation
		// protected action, and this spec runs late enough in the
		// `chromium-instance-state` project that the admin session is outside the
		// window: it answered `HTTP 403, OCS 403 Password confirmation is
		// required` and failed the test before it reached a single assertion. I
		// added that call as belt-and-braces and it was the only thing that broke.
		//
		// `ci-seed.sh` owns this account and logs `created the non-admin user
		// e2euser`. If it ever stops, the identity assertion below says so by
		// name rather than leaving a login to fail obscurely.
		const plainState = storageStatePath(PLAIN_USER)
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: PLAIN_USER,
			password: PLAIN_PASS,
			statePath: plainState,
		})

		const context = await browser.newContext({
			baseURL,
			storageState: plainState,
		})
		const page = await context.newPage()

		await page.goto('/apps/dossiq')
		await dismissSupportDialog(page)

		// NAME THE USER IN THE FAILURE. This assertion has failed on
		// `development` while every link in the permission chain reads correct:
		// the menu entry declares `permission: "admin"`, CnAppNav's
		// `visibleItems` applies `passesPermission` before `settingsItems`
		// filters on `section === "settings"`, and `App.vue` answers
		// `['user']` for a non-admin, never `[]`. So either the chain is not
		// what it reads as, or this context is not the user it asks for, and
		// the failure as written cannot tell those apart.
		//
		// `httpCredentials` on a fresh context is an assumption about how
		// Nextcloud authenticates an HTML route, not a measurement. Reading
		// the identity the PAGE settled on turns the next red into an answer.
		const whoami = await page.evaluate(() => ({
			uid:
				(window as any).OC?.getCurrentUser?.()?.uid
				?? '(no OC.getCurrentUser)',
			isAdmin:
				typeof (window as any).OC?.isUserAdmin === 'function'
					? (window as any).OC.isUserAdmin()
					: '(no OC.isUserAdmin)',
		}))

		// ASSERT THE IDENTITY FIRST. Without this the test still passes or
		// fails on whatever user it happens to get, which is exactly how it came
		// to assert the admin's view under a name that promised the opposite.
		expect(
			whoami,
			'this test must act as the non-admin, not as whoever the shared '
				+ 'storage state logged in',
		).toEqual({ uid: PLAIN_USER, isAdmin: false })

		// The gear foldout does not carry the entry.
		await expect(
			page.locator('.app-navigation a[href$="/settings/integrations"]'),
			`the page rendered as uid=${whoami.uid} isAdmin=${whoami.isAdmin}; `
				+ `it should be the non-admin ${PLAIN_USER}`,
		).toHaveCount(0)

		// And the route does not render the page even when typed in directly.
		// This is the half nothing enforced: the manifest declares
		// `permission: "admin"` on the PAGE, `CnAppNav` only ever read the
		// declaration on the MENU entry, and the router built by `main.js`
		// dropped the field — so this route answered an ordinary account with
		// eleven integration rows and seven links into `/settings/admin/dossiq`.
		await page.goto('/apps/dossiq/settings/integrations')
		await dismissSupportDialog(page)
		// The guard redirects to the dashboard. Assert the DESTINATION, not
		// only the absence of a link: a page that never rendered has no links
		// either, so the count alone cannot tell a working guard from a broken
		// bundle. See the docblock.
		await expect(page).not.toHaveURL(/\/settings\/integrations$/)
		await expect(page.locator('a[href^="/settings/admin/dossiq#"]')).toHaveCount(
			0,
		)
		await expect(
			page.getByRole('row', { name: /ZGW APIs/i }),
			'no integration row survives the redirect',
		).toHaveCount(0)

		await context.close()
	})

	/**
	 * THE DATA HALF OF THE SAME GUARD, and the reason the test above does not
	 * cover it.
	 *
	 * The test above proves the ROUTER turns an ordinary account away. It
	 * cannot prove anything about the rows, because the rows are not the
	 * page's to withhold: they live in OpenRegister and the page fetches them
	 * over `GET /apps/openregister/api/objects/dossiq/dossiqIntegration`.
	 * A client-side guard cannot narrow a server-side list, so for as long as
	 * that endpoint answered, "not reachable" was true of the page and false
	 * of the data. Measured before the fix, as an account in no groups at all:
	 * HTTP 200 and TEN rows — every connection this instance has, whether it
	 * is configured, and the admin-settings anchor that configures it.
	 *
	 * WHAT MAKES THIS TEST ABLE TO FAIL, which is the only property that makes
	 * it worth having. "An ordinary account sees no rows" is also what an
	 * anonymous session sees, what an empty register looks like, and what a
	 * broken route returns. All three would satisfy the assertion while
	 * proving nothing about permissions, so all three are excluded here:
	 *
	 *  1. AN EMPTY REGISTER or a broken route is excluded by reading the same
	 *     endpoint as the admin FIRST and requiring rows to come back. If that
	 *     read is empty the test fails on the control, naming the cause,
	 *     rather than passing on the denial.
	 *  2. AN ANONYMOUS SESSION is excluded by asking the request context who
	 *     it is. This is the trap the router-guard test above walked into from
	 *     the other side: clearing the admin jar does not authenticate basic
	 *     auth, it just produces an anonymous context, and an anonymous
	 *     context reads this endpoint as zero rows for reasons that have
	 *     nothing to do with the schema. `/ocs/v2.php/cloud/user` is asked on
	 *     THE CONTEXT THAT MAKES THE READ, not on a browser page that happens
	 *     to share a name with it.
	 *  3. THE ADMIN'S OWN SESSION is excluded by the same question, because an
	 *     omitted `storageState` silently becomes the admin's — `use`
	 *     in playwright.config.ts sets it — and an admin bypasses OpenRegister
	 *     RBAC outright, so the denial would never be exercised.
	 *
	 * The OCS call carries `OCS-APIRequest: true` because without that header
	 * Nextcloud answers 412 and the identity check would fail for a reason
	 * that is not the identity.
	 */
	test('answers an ordinary account no integration rows over the API', async ({
		browser,
		playwright,
		baseURL,
	}) => {
		// CONTROL FIRST. Everything below reads a denial out of an empty list,
		// so the list has to be non-empty for someone before that means
		// anything. `api` is the admin context this file's other tests use.
		const adminRows = await listObjects(api, 'dossiqIntegration')
		expect(
			adminRows.length,
			'the control failed, not the guard: the admin sees no integration '
				+ 'rows either, so this instance has an empty register or a broken '
				+ 'route and the denial below would prove nothing',
		).toBeGreaterThan(0)

		const plainState = storageStatePath(PLAIN_USER)
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: PLAIN_USER,
			password: PLAIN_PASS,
			statePath: plainState,
		})

		// A REQUEST context, not a page: the read under test is an API call,
		// and a write made in a page is not visible to a read made through a
		// request context. Ask the context that does the work.
		const plainApi = await playwright.request.newContext({
			baseURL,
			storageState: plainState,
		})

		try {
			const whoRes = await plainApi.get('/ocs/v2.php/cloud/user?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
			})
			expect(
				whoRes.status(),
				'the identity probe itself failed; without it a zero-row result '
					+ 'below cannot be told apart from an anonymous session',
			).toBe(200)
			const uid = (await whoRes.json())?.ocs?.data?.id

			// ASSERT THE IDENTITY BEFORE THE PERMISSION. A denial measured on
			// the wrong account is not a measurement.
			expect(
				uid,
				`this read must be made as the ordinary account ${PLAIN_USER}; `
					+ 'anonymous or admin both produce a passing row count for '
					+ 'reasons that have nothing to do with the schema',
			).toBe(PLAIN_USER)

			// THE DENIED CASE. Not `listObjects()` — that helper asserts `ok()`
			// and unwraps, and the shape of the refusal is part of what is
			// being asserted: OpenRegister narrows a list in SQL, so a denial
			// here is HTTP 200 with an empty result set, not a 403.
			const res = await plainApi.get(
				'/index.php/apps/openregister/api/objects/dossiq/dossiqIntegration'
					+ '?_limit=200',
			)
			expect(res.status()).toBe(200)
			const body = await res.json()

			expect(
				body.results ?? [],
				`${PLAIN_USER} holds no group and must see no integration rows; `
					+ `the admin sees ${adminRows.length}. Rows here mean the `
					+ '`authorization` block on the dossiqIntegration schema is '
					+ 'absent or was not imported — OpenRegister treats an absent '
					+ 'block as open, so this is exactly how it read before the fix',
			).toHaveLength(0)
			expect(
				body.total ?? 0,
				'the row list is empty but the total is not, so the count is '
					+ 'answering from outside the RBAC filter',
			).toBe(0)
		} finally {
			await plainApi.dispose()
		}
	})

	test('says plainly that a mock adapter is running', async ({ page }) => {
		const byKey = await integrationsByKey(api)

		for (const key of SIMULATED_KEYS) {
			const row = byKey[key]
			expect(row, `no seeded row for ${key}`).toBeTruthy()
			// The claim, not the rendering. A Simulated row whose message says
			// nothing would render perfectly and tell the reader nothing, which
			// is the failure mode this whole page exists to catch.
			expect(row.status).toBe('simulated')
			expect(String(row.statusMessage || '')).toMatch(/mock/i)
		}

		await openIntegrations(page)
		for (const key of SIMULATED_KEYS) {
			const row = page.getByRole('row', {
				name: new RegExp(byKey[key].title, 'i'),
			})
			await expect(row).toBeVisible({ timeout: 30_000 })
			// The reader must be able to see it WITHOUT opening a settings
			// page: the row itself carries the sentence.
			await expect(row).toContainText(/mock/i)
		}
	})

	test('lists a missing required app', async () => {
		// REQ-ADMIN-021 is RED and cannot pass yet. The Required apps
		// section needs CnLeafDependencySettings mounted through a
		// `type: "component"` widget on a `type: "settings"` page, and the
		// installed @conduction/nextcloud-vue resolves a settings section's
		// widgets only against `version-info`, `register-mapping` and the
		// `component` discriminator. `object-list` is a DASHBOARD widget.
		// So the connections could not live on a settings page, the page
		// ships as an index (task 2.2's named interim), and an index page
		// has nowhere to put this section. Unfixme when a settings section
		// can host a list; nothing else about the page has to change.
		//
		// Re-measured against the pinned 2.46.0: CnSettingsPage's
		// BUILTIN_SETTINGS_WIDGETS is still `version-info`,
		// `register-mapping` and `component`, so this stands. There is no
		// body yet because there is no section to assert on; writing one
		// belongs with the change that builds the section.
		//
		// The reason is passed to `test.fixme(true, reason)` so the run report
		// records it. `test.fixme(title, body)` records none, which is how
		// this reached the skip gate as an exclusion without a reason.
		test.fixme(
			true,
			'REQ-ADMIN-021 has no surface to test: the Integrations page ships '
				+ 'as a type index page, because a type settings section in '
				+ 'nextcloud-vue 2.46.0 resolves widgets only against version-info, '
				+ 'register-mapping and component, so the connections list could '
				+ 'not live on a settings page, and an index page has nowhere to '
				+ 'mount CnLeafDependencySettings. Building the Required apps '
				+ 'section is product work in src/manifest.json (and possibly '
				+ 'nextcloud-vue), not a change to this spec.',
		)
	})

	test('leaves the seeded rows where the next run expects them', async () => {
		// A guard on this spec's own residue, not a scenario. The KCC test
		// writes app config that persists, and the next run must still start
		// from a page that claims nothing it has not checked. Restoring the row
		// is cheap and keeps the "fresh instance" assertion honest on a reused
		// instance.
		const kcc = (await integrationsByKey(api)).kcc
		if (kcc && kcc.status === 'configured') {
			await updateObject(
				api,
				token,
				'dossiqIntegration',
				String(kcc['@self']?.id ?? kcc.id),
				{ status: 'unconfigured', statusMessage: 'Not checked yet' },
			)
		}
	})
})
