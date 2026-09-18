/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The way in to the data model, and the account that is refused it.
 *
 * dossiq renders no model page of its own. Every schema it uses is registered
 * in OpenRegister, whose register page lists each schema with its properties,
 * and this change adds the two doors: a Data model entry in the Integrations
 * section, and a Manage object types card beside the objects.
 *
 * WHY EACH ASSERTION IS THE ONE IT IS.
 *
 * The href is asserted on the ANCHOR, not followed. Both surfaces render a
 * real `<a>`, and what this change owns is where that anchor points. Clicking
 * through would assert OpenRegister's page instead, on an instance where the
 * register id, the schema count and the page's own markup are all outside
 * this repo. The one thing worth following is that the target is a route
 * OpenRegister serves, and that is asserted separately, by request.
 *
 * The register is named by SLUG. The numeric id differs per instance and no
 * manifest can know it, so `/registers/dossiq` is the only form a declaration
 * can carry. Reading the register back by slug over the API is what says the
 * name resolves to something on this instance rather than to a blank page.
 *
 * The refusal is asserted as a NAMED account. `captureStorageState` is the
 * sanctioned second session; an omitted `storageState` silently becomes the
 * admin's, which is how a test in this suite once spent its life asserting
 * the admin's view under a name promising the opposite. So the identity is
 * read off the page and asserted before anything else.
 *
 * `e2euser` comes from `ci-seed.sh`, not from `ensureUser`: provisioning is
 * password-confirmation protected and the admin session is outside that
 * window by the time this spec runs.
 *
 * Locale: the two labels are English strings this change declares in
 * `src/manifest.json` and are matched bare. Everything else is matched on a
 * `data-testid`, a role, or an href.
 */

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { captureStorageState, storageStatePath } from './helpers/auth.ts'
import { dismissSupportDialog, navToRoute, PAGE_LOAD } from './helpers/nav.ts'

/** The account `ci-seed.sh` creates, which holds no admin rights. */
const PLAIN_USER = process.env.E2E_USER_NAME || 'e2euser'
const PLAIN_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

/** The label on the Integrations entry. */
const DATA_MODEL = 'Data model'
/** The label on the card beside the objects. */
const OBJECT_TYPES = 'Manage object types'

/** OpenRegister's register page for dossiq, addressed by slug. */
const TARGET = /\/apps\/openregister\/#\/registers\/dossiq$/

/**
 * Open the per-user settings modal and return its Integrations section.
 *
 * ⚠️ THE SECTION ID IS NOT THE ONE THE APP WRITES. CnAppRoot declares
 * `id="cn-integrations"` and Nextcloud's NcAppSettingsSection renders it as
 * `settings-section_cn-integrations`, so `#cn-integrations` matches nothing
 * and fails as though the section were missing. Located by role and name
 * instead, which is what a reader sees and is not hostage to that prefix.
 *
 * @param page The page, already on the app.
 * @return The Integrations region inside the settings dialog.
 */
async function openIntegrations(page: Page) {
	const nav = page.locator('[data-testid="cn-nav"]')
	await expect(nav).toBeVisible({ timeout: 30_000 })
	await nav.locator('[data-testid="cn-nav-settings"]').click()
	await nav.locator('[data-testid="cn-nav-personal-settings"]').click()
	return page.getByRole('dialog').getByRole('region', { name: 'Integrations' })
}

test.describe('the data model is one link away', () => {
	// @e2e openspec/changes/data-model-link/specs/admin-settings/spec.md#scenario-an-admin-reaches-the-data-model
	test('an admin finds Data model in Integrations, pointing at the dossiq register', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const integrations = await openIntegrations(page)
		await expect(
			integrations,
			'the per-user settings modal must carry an Integrations section',
		).toBeVisible({ timeout: 15_000 })

		const entry = integrations.getByRole('link', { name: DATA_MODEL })
		await expect(
			entry,
			'Data model must render in Integrations, which is where ADR-110 puts a link that leaves the app',
		).toHaveCount(1)
		await expect(
			entry,
			"and it must address OpenRegister's register page for dossiq",
		).toHaveAttribute('href', TARGET)

		// NOT IN THE NAVIGATION, which is the other half of ADR-110: such a
		// link can never be the active route, and in the nav it reads as a
		// capability of this app.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(
			nav.locator('[data-testid="cn-nav-entry-DataModelLink"]'),
			'a link that leaves the app must not sit in the navigation',
		).toHaveCount(0)
	})

	// @e2e openspec/changes/data-model-link/specs/admin-settings/spec.md#scenario-an-admin-reaches-the-data-model
	test('the target names a register this instance actually has', async () => {
		// The declaration can only carry the slug, so the thing worth proving
		// is that the slug resolves. A link to a register nobody answers to
		// opens a page with an empty schema list and no error at all — the
		// failure this request is here to separate from a working link.
		const api = await request.newContext({ baseURL: BASE_URL })
		const response = await api.get(
			'/index.php/apps/openregister/api/registers/dossiq',
		)
		expect(
			response.status(),
			'OpenRegister must resolve the register slug the manifest names',
		).toBe(200)

		const register = await response.json()
		expect(
			register.slug ?? register.register?.slug,
			'and the register it answers with must be the dossiq one',
		).toBe('dossiq')
		await api.dispose()
	})

	// @e2e openspec/changes/data-model-link/specs/admin-settings/spec.md#scenario-from-the-objects-index
	test('an admin reaches Manage object types from the Objects index', async ({
		page,
	}) => {
		await navToRoute(page, '/case-objects')

		const card = page.getByRole('link', { name: new RegExp(OBJECT_TYPES) })
		await expect(
			card,
			'the Objects index must carry a Manage object types card',
		).toHaveCount(1)
		await expect(
			card,
			'and it must open the same register page as the menu entry',
		).toHaveAttribute('href', TARGET)
	})

	// @e2e openspec/changes/data-model-link/specs/admin-settings/spec.md#scenario-a-handler-does-not-see-it
	test('a handler is offered neither door', async ({ browser, baseURL }) => {
		// LOG IN, do not send credentials. Basic auth does not authenticate
		// Nextcloud's HTML route: with the admin jar cleared and
		// `httpCredentials` set the page comes back with no session at all,
		// and with the jar kept it comes back as the admin.
		const plainState = storageStatePath(PLAIN_USER)
		await captureStorageState(browser, {
			baseURL: String(baseURL ?? BASE_URL),
			user: PLAIN_USER,
			password: PLAIN_PASS,
			statePath: plainState,
		})

		const context = await browser.newContext({
			baseURL,
			storageState: plainState,
		})
		const page = await context.newPage()

		await page.goto('/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		// ASSERT THE IDENTITY FIRST, and name it in the failure. Without this
		// the test passes or fails on whatever account it happens to get, and
		// an absent entry proves nothing about the gate.
		const whoami = await page.evaluate(() => ({
			uid:
				(window as any).OC?.getCurrentUser?.()?.uid
				?? '(no OC.getCurrentUser)',
			isAdmin:
				typeof (window as any).OC?.isUserAdmin === 'function'
					? (window as any).OC.isUserAdmin()
					: '(no OC.isUserAdmin)',
		}))
		expect(
			whoami,
			'this test must act as the non-admin, not as whoever the shared storage state logged in',
		).toEqual({ uid: PLAIN_USER, isAdmin: false })

		// The Integrations section either is absent or carries other entries;
		// what it must not carry is this one. Asserted on the DIALOG rather
		// than on the section, so an instance where AvgRegisterLink is the only
		// other entry and the section renders is covered the same way as one
		// where the section does not render at all.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 30_000 })
		await nav.locator('[data-testid="cn-nav-settings"]').click()
		await nav.locator('[data-testid="cn-nav-personal-settings"]').click()
		await expect(
			page.getByRole('dialog').getByRole('link', { name: DATA_MODEL }),
			`the settings modal rendered as uid=${whoami.uid} isAdmin=${whoami.isAdmin}; `
				+ 'Data model must not be offered to an account without admin',
		).toHaveCount(0)

		// And the second door, which is gated by a different mechanism:
		// `visibleIf` against `manifest.runtime.user.isAdmin`, because
		// CnNavCardGrid reads `permission` on a card nowhere.
		await page.keyboard.press('Escape')
		await navToRoute(page, '/case-objects')
		await expect(
			page.getByRole('link', { name: new RegExp(OBJECT_TYPES) }),
			'Manage object types must not be offered beside the objects either',
		).toHaveCount(0)

		await context.close()
	})
})
