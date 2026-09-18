/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CM-36: an administrator inspects a case from its own page, and a handler
 * is not offered the door.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. Every unit test in this change
 * asserts a DECLARATION: that the manifest carries two actions, that they
 * name a registry modal, that the endpoint exists and answers about the
 * reader. Not one of them can assert that the entries reach a menu. That is
 * the failure mode this change already walked into once: an action carrying
 * `children` validates against the schema, reads correctly in review, and
 * renders nothing at all on a detail page, because CnDetailPage runs
 * CnActionButtons in `display: "menu"` and that mode never looks at
 * `children`. A vitest cannot see that. A browser can.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED.
 * Opening the menu as an administrator proves only that an entry can render.
 * So a plain account is provisioned and the same two questions are asked of
 * it: what its case page offers, and what the gate endpoint answers it. An
 * admin's "yes" and a handler's "no" are the same object, the same page and
 * the same minute.
 *
 * 🔴 WHAT IT DELIBERATELY DOES NOT CLAIM. Not that the absent entry keeps
 * anybody out of anything. Hiding a menu item is an affordance; the refusal
 * belongs to OpenRegister, which answers the dialog's fetch and the runs page
 * alike. The handler assertions are about what is OFFERED, and they say so.
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
	STORAGE_STATE,
	storageStatePath,
} from './helpers/auth.ts'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { openHeaderActionsMenu, PAGE_LOAD } from './helpers/nav.ts'

/** The gate every Inspect entry is hidden or shown by. */
const AVAILABILITY = '/index.php/apps/dossiq/api/inspect/availability'

/** The account that is not an administrator. */
const HANDLER = 'e2e-inspect-handler'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Inspect-2026!'

/** The case both readers open. */
let caseId = ''

/** A request context signed in as the handler, for the gate probe. */
let handlerApi: APIRequestContext | null = null

/** Where the handler's captured session lives, for the browser half. */
const handlerState = storageStatePath(HANDLER)

/**
 * Sign one account in and hand back a request context for it.
 *
 * @param browser    A launched browser.
 * @param baseURL    The instance.
 * @param playwright The Playwright fixture.
 *
 * @return A request context carrying that account's session.
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

test.describe('Inspect, on a case page', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		const adminToken = await getRequestToken(adminApi)

		// Provisioning gets its own session-free context: a captured admin
		// session answers "Password confirmation is required" once it is half
		// an hour old, and this file sorts late enough in a run to meet that.
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await provisioning.dispose()

		const machine = await seedStateMachine(adminApi, adminToken)
		const seeded = await seedCase(adminApi, adminToken, {
			title: `${RUN_PREFIX} Zaak om in te kijken`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		caseId = objectId(seeded)
		expect(caseId, 'the fixture case must have an id').not.toBe('')

		handlerApi = await signInHandler(browser, String(baseURL), playwright)
		await adminApi.dispose()
	})

	test.afterAll(async ({ request }) => {
		await handlerApi?.dispose()
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/admin-inspect-entry/specs/case-management/spec.md#scenario-raw-data-shows-the-stored-case
	test('an admin opens the raw data, and reads the case as it is stored', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq/#/cases/${caseId}`, PAGE_LOAD)
		await openHeaderActionsMenu(page)

		const entry = page.getByTestId('cn-action-case-inspect-raw')
		await expect(
			entry,
			'an administrator must be offered the raw data',
		).toBeVisible({ timeout: 20_000 })
		await entry.click()

		const dialog = page.getByTestId('case-raw-data-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		const json = page.getByTestId('case-raw-data-json')
		await expect(json).toBeVisible({ timeout: 20_000 })

		// The record, not the metadata around it. `CnObjectMetadataModal`
		// would have rendered the `@self` block and no stored property at all,
		// so the case's OWN id is what separates the two.
		const printed = (await json.textContent()) ?? ''
		expect(
			printed,
			'the dialog must print the stored case, not a metadata summary',
		).toContain(caseId)
		expect(JSON.parse(printed), 'the printed text must be JSON').toBeTruthy()

		await expect(page.getByTestId('case-raw-data-error')).toHaveCount(0)
	})

	// @e2e openspec/changes/admin-inspect-entry/specs/case-management/spec.md#scenario-raw-data-shows-the-stored-case
	test('an admin can reach the flow runs for this case', async ({ page }) => {
		await page.goto(`/index.php/apps/dossiq/#/cases/${caseId}`, PAGE_LOAD)
		await openHeaderActionsMenu(page)

		const entry = page.getByTestId('cn-action-case-inspect-runs')
		await expect(entry).toBeVisible({ timeout: 20_000 })
		await entry.click()

		await expect(page).toHaveURL(/openregister/, { timeout: 30_000 })
		expect(page.url(), 'the runs page must be filtered to this case').toContain(
			caseId,
		)
	})

	// @e2e openspec/changes/admin-inspect-entry/specs/case-management/spec.md#scenario-handlers-do-not-see-inspect
	test('the gate answers no to a handler and yes to an admin', async ({
		request,
	}) => {
		// The control first. Without it a false could mean the endpoint is
		// broken, or missing, or that the whole app is down, and the handler
		// assertion below would read as a pass either way.
		const asAdmin = await request.get(AVAILABILITY)
		expect(
			asAdmin.ok(),
			`the gate must answer at all; got ${asAdmin.status()}`,
		).toBeTruthy()
		expect((await asAdmin.json())?.isAdmin).toBe(true)

		const asHandler = await handlerApi!.get(AVAILABILITY)
		expect(
			asHandler.ok(),
			`a handler must be ANSWERED, not refused; a 403 hides the entry too, so a refusal here would look like a pass. Got ${asHandler.status()}`,
		).toBeTruthy()
		expect((await asHandler.json())?.isAdmin).toBe(false)
	})

	// @e2e openspec/changes/admin-inspect-entry/specs/case-management/spec.md#scenario-handlers-do-not-see-inspect
	test('a handler is offered neither entry', async ({ browser, baseURL }) => {
		const context = await browser.newContext({
			baseURL,
			storageState: handlerState,
		})
		const page = await context.newPage()

		try {
			await page.goto(`/index.php/apps/dossiq/#/cases/${caseId}`, PAGE_LOAD)

			// The menu has to OPEN, or an absent entry proves only that the
			// page never rendered its header. This is the control for the two
			// absences below.
			await openHeaderActionsMenu(page)

			await expect(
				page.getByTestId('cn-action-case-inspect-raw'),
				'a handler must not be offered the raw data',
			).toHaveCount(0)
			await expect(
				page.getByTestId('cn-action-case-inspect-runs'),
				'a handler must not be offered the flow runs',
			).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
