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
	'signing',
]

/** The two connections that are specified and not built. */
const UNAVAILABLE_KEYS = ['brp', 'kvk']

/**
 * The two seams that ship a mock adapter.
 *
 * These are the rows the page did not have and most needed. Both seams WORK:
 * the compose dialog opens, the send succeeds, an id comes back. Neither
 * reaches anything outside this instance. A row that read Configured or Not
 * available would be the same lie the page was built to remove, one layer
 * down, so the invariant asserted below is that they read as neither.
 */
const SIMULATED_KEYS = ['berichtenbox', 'templates', 'signing']

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

	test('lists the thirteen connections in the seeded order', async ({ page }) => {
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

	test('offers no settings link where the connection is not built', async ({
		page,
	}) => {
		const byKey = await integrationsByKey(api)
		for (const key of UNAVAILABLE_KEYS) {
			expect(byKey[key].status).toBe('unavailable')
			expect(byKey[key].statusMessage).toBe('Specified, not built yet')
			// The seed writes `""` and the register does not store an empty
			// string, so the row reads back with no such key at all. Both
			// spellings say the same thing, and the assertion that matters is
			// the one below: the affordance is absent from the page.
			expect(byKey[key].settingsUrl || '').toBe('')
		}

		await openIntegrations(page)
		for (const key of UNAVAILABLE_KEYS) {
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
			if (SIMULATED_KEYS.includes(key)) {
				// Signing is probed against an APP, not a setting, so an
				// instance with LibreSign installed legitimately reads
				// Configured. The invariant either way is that it never reads
				// Not configured, which would understate a mock that signs
				// nothing.
				expect(['simulated', 'configured']).toContain(row.status)
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

	test('is not reachable by a user who is not an admin', async ({
		browser,
		baseURL,
	}) => {
		const context = await browser.newContext({
			baseURL,
			httpCredentials: {
				username: PLAIN_USER,
				password: PLAIN_PASS,
				// The dossiq API answers 401 without a WWW-Authenticate header,
				// so Playwright would never send the credentials on the
				// challenge. `always` sends them on the first request.
				send: 'always',
			},
		})
		const page = await context.newPage()

		await page.goto('/apps/dossiq')
		await dismissSupportDialog(page)

		// The gear foldout does not carry the entry.
		await expect(
			page.locator('.app-navigation a[href$="/settings/integrations"]'),
		).toHaveCount(0)

		// And the route renders no rows even when typed in directly.
		await page.goto('/apps/dossiq/settings/integrations')
		await dismissSupportDialog(page)
		await expect(page.locator('a[href^="/settings/admin/dossiq#"]')).toHaveCount(
			0,
		)

		await context.close()
	})

	test('says plainly that a mock adapter is running', async ({ page }) => {
		const byKey = await integrationsByKey(api)

		for (const key of SIMULATED_KEYS) {
			const row = byKey[key]
			expect(row, `no seeded row for ${key}`).toBeTruthy()
			if (row.status === 'configured') {
				// Signing on an instance that really has LibreSign. Nothing to
				// assert about a mock, because there is not one.
				continue
			}
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

	test.fixme('lists a missing required app', async () => {
		// REQ-ADMIN-021 is RED and cannot pass yet. The Required apps
		// section needs CnLeafDependencySettings mounted through a
		// `type: "component"` widget on a `type: "settings"` page, and the
		// installed @conduction/nextcloud-vue resolves a settings section's
		// widgets only against `version-info`, `register-mapping` and the
		// `component` discriminator — `object-list` is a DASHBOARD widget.
		// So the connections could not live on a settings page, the page
		// ships as an index (task 2.2's named interim), and an index page
		// has nowhere to put this section. Unfixme when a settings section
		// can host a list; nothing else about the page has to change.
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
