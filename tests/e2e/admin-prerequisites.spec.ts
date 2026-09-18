/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-ADMIN-024: the prerequisites are declared once and shown.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. `PrerequisitesTest` proves that
 * `check()` reads the declaration, and `prerequisitesBlock.spec.js` proves the
 * component draws present and missing differently. Neither can prove the
 * reading reaches the page. The seam between them is `provideInitialState` and
 * `loadState`, and that seam has failed silently in this exact file before:
 * the consultation and mandate tabs both shipped calling `loadState()` for a
 * key nothing ever provided, defaulted to `{}`, and showed hardcoded defaults
 * to every administrator for months. A block that renders its empty fallback
 * looks like a block with nothing to report.
 *
 * So the assertion is not "the block is there". It is that the block names a
 * specific app, with a specific verdict, that the server decided.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED. The
 * settings page is admin-only, and the refusal belongs to Nextcloud's settings
 * framework rather than to dossiq, so the non-admin half asks for the page and
 * expects not to be given it.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext, Browser } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	storageStatePath,
} from './helpers/auth.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The dossiq admin settings page. */
const SETTINGS = '/index.php/settings/admin/dossiq'

/** An account with no administrator rights. */
const HANDLER = 'e2e-prereq-handler'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Prereq-2026!'

/** Where the handler's captured session lives. */
const handlerState = storageStatePath(HANDLER)

/** A request context signed in as the handler. */
let handlerApi: APIRequestContext | null = null

/**
 * Sign the handler in.
 *
 * @param browser    A launched browser.
 * @param baseURL    The instance.
 * @param playwright The Playwright fixture.
 *
 * @return That account's request context.
 */
async function signInHandler(
	browser: Browser,
	baseURL: string,
	playwright: any,
): Promise<APIRequestContext> {
	await captureStorageState(browser, {
		baseURL,
		user: HANDLER,
		password: PASSWORD,
		statePath: handlerState,
	})

	return playwright.request.newContext({ baseURL, storageState: handlerState })
}

test.describe('The Prerequisites block', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await provisioning.dispose()

		handlerApi = await signInHandler(browser, String(baseURL), playwright)
	})

	test.afterAll(async () => {
		await handlerApi?.dispose()
	})

	// @e2e openspec/changes/declared-prerequisites/specs/admin-settings/spec.md#scenario-optional-apps-say-what-they-unlock
	test('an optional app that is absent is listed with what it unlocks', async ({
		page,
	}) => {
		await page.goto(SETTINGS, PAGE_LOAD)

		const block = page.getByTestId('prerequisites-block')
		await expect(block).toBeVisible({ timeout: 30_000 })

		// The server decided this row. An empty fallback renders the block and
		// no rows at all, so a row carrying an app id is what separates "the
		// state arrived" from "the state was never provided".
		const hours = page.getByTestId('prerequisite-app-hrmq')
		await expect(
			hours,
			'the hours app must be listed among the optional ones',
		).toBeVisible({ timeout: 20_000 })

		const text = (await hours.textContent()) ?? ''
		expect(
			/present|missing/.test(text),
			`each row must carry a verdict in words, not only a colour; got "${text}"`,
		).toBeTruthy()
		expect(
			text.replace(/present|missing/, '').trim().length,
			'an optional app must say what it would have added',
		).toBeGreaterThan(0)
	})

	// @e2e openspec/changes/declared-prerequisites/specs/admin-settings/spec.md#scenario-optional-apps-say-what-they-unlock
	test('the block reports what the server read, not a browser guess', async ({
		page,
	}) => {
		await page.goto(SETTINGS, PAGE_LOAD)
		await expect(page.getByTestId('prerequisites-block')).toBeVisible({
			timeout: 30_000,
		})

		// Open Register is installed on any rig this suite runs against, so
		// this row is the control: a block that reported everything missing
		// would be as useless as one that reported everything present, and
		// only asserting both verdicts can tell them apart.
		await expect(
			page.getByTestId('prerequisite-app-openregister'),
		).toContainText('present', { timeout: 20_000 })

		// The extensions are invisible from a browser, so a row about one is
		// proof the reading came from PHP.
		await expect(page.getByTestId('prerequisite-ext-zip')).toBeVisible({
			timeout: 20_000,
		})
	})

	// @e2e openspec/changes/declared-prerequisites/specs/admin-settings/spec.md#scenario-optional-apps-say-what-they-unlock
	test('a handler is not given the settings page at all', async () => {
		const response = await handlerApi!.get(SETTINGS)

		expect(
			response.status(),
			'a non-admin must be refused or redirected away from the admin settings',
		).not.toBe(200)
	})
})
