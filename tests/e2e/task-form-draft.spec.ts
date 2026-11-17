/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A form keeps what the handler typed (REQ-TM-DRAFT-01).
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. `tests/vitest/manifestDrafts.spec.js` pins
 * that TaskDetail, CaseDetail and Cases carry `draftAppId` and `draftField`,
 * and that the case forms carry `allowDraft`. It cannot show that the library
 * forwards those keys to the form it opens, that the form writes the draft to
 * the browser and offers it back on the next open, or that the server accepts
 * a draft with its required fields empty. All three sit between dossiq's
 * manifest, nextcloud-vue and OpenRegister, so they are asserted here.
 *
 * 🔴 THE HANDLER IS NOT THE ADMIN. A draft belongs to the signed-in user (the
 * key carries the uid), and a draft case shows only to its author. Run as the
 * admin, both scenarios would pass on a page that ignored the user entirely,
 * so the browser context starts from an empty storage state and logs in as a
 * provisioned non-admin account.
 *
 * WHICH FORM TAKES WHICH SCENARIO. "Input survives a closed dialog" runs on the
 * task page's Edit form, the form the requirement is named for. "Save as draft
 * skips validation" runs on the new-case form: an engine task carries no draft
 * marker, so the task page offers no Save draft (manifest `_draftNote` on
 * TaskDetail), and the case forms are where the button lives.
 *
 * @spec openspec/changes/task-forms-keep-a-draft/specs/task-management/spec.md
 */

import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, login, provisioningContext } from './helpers/auth.ts'
import {
	getRequestToken,
	REGISTER,
	RUN_PREFIX,
	seedFlowTask,
	showObject,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { openHeaderActionsMenu, PAGE_LOAD } from './helpers/nav.ts'

/** The handler, who is not the admin. */
const HANDLER = process.env.E2E_USER_NAME || 'e2euser'
const HANDLER_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

/** The staff group the app opens for. Joined when it exists; absent is not fatal. */
const STAFF_GROUP = 'dossiq-medewerkers'

/** The engine task the handler edits. */
let taskId = ''

/**
 * A browser page signed in as the handler, with no admin session riding along.
 *
 * @param browser The launched browser.
 * @param baseURL The instance under test.
 * @return The page.
 */
async function handlerPage(browser: Browser, baseURL: string): Promise<Page> {
	const context = await browser.newContext({
		baseURL,
		// An explicit empty jar, or the project's captured admin session is
		// used and the draft is keyed on the admin's uid.
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await login(page, HANDLER, HANDLER_PASS)
	return page
}

/**
 * The form dialog's root, which holds the fields and the footer buttons.
 *
 * @param page The page.
 * @return The dialog locator.
 */
function formDialog(page: Page) {
	return page.getByRole('dialog').filter({
		has: page.locator('[data-testid-modal="cn-form-dialog"]'),
	})
}

/**
 * Open the task's Edit form: a verb button on the header, or an entry in the
 * Actions menu when the source marks it `menu`.
 *
 * @param page The task page.
 */
async function openTaskEdit(page: Page): Promise<void> {
	const verb = page.getByTestId('cn-detail-page-verb-edit')
	if (await verb.isVisible().catch(() => false)) {
		await verb.click()
	} else {
		await openHeaderActionsMenu(page)
		await page.getByRole('menuitem', { name: /^Edit/ }).first().click()
	}
	await expect(formDialog(page)).toBeVisible({ timeout: 20_000 })
}

/**
 * The browser-kept drafts of this app for the signed-in handler.
 *
 * @param page The page.
 * @return The localStorage keys that hold a dossiq draft.
 */
async function draftKeys(page: Page): Promise<string[]> {
	return page.evaluate(
		({ app, uid }) => Object.keys(window.localStorage).filter(
			(key) => key.includes(`:${app}:`) && key.endsWith(`:${uid}`),
		),
		{ app: REGISTER, uid: HANDLER },
	)
}

test.describe('A form keeps what the handler typed (REQ-TM-DRAFT-01)', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', HANDLER, HANDLER_PASS)
		// Membership of the staff group, so the app opens for the handler. A
		// rig without the group answers OCS 102/103 and the handler stays as is.
		await admin.post(`/ocs/v2.php/cloud/users/${encodeURIComponent(HANDLER)}/groups?format=json`, {
			form: { groupid: STAFF_GROUP },
		})

		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} taak met formulier`,
			assignee: HANDLER,
			state: 'enabled',
			priority: 'normal',
		})
	})

	/**
	 * @spec openspec/changes/task-forms-keep-a-draft/specs/task-management/spec.md#requirement-a-task-form-keeps-what-you-typed-req-tm-draft-01
	 */
	test('input survives a closed dialog and is offered back @spec REQ-TM-DRAFT-01', async ({
		browser,
		baseURL,
	}) => {
		const page = await handlerPage(browser, String(baseURL))
		await page.goto(`/index.php/apps/dossiq/tasks/${taskId}`, PAGE_LOAD)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible(PAGE_LOAD)

		await openTaskEdit(page)
		const typed = `${RUN_PREFIX} half ingevuld`
		const title = formDialog(page).locator('[data-cn-field="title"] input, [data-cn-field="title"] textarea').first()
		await title.fill(typed)

		// The draft is written after a short debounce; wait for it to land
		// rather than racing the close against the timer.
		await expect.poll(() => draftKeys(page), { timeout: 10_000 }).not.toHaveLength(0)

		await page.keyboard.press('Escape')
		await expect(formDialog(page)).toBeHidden({ timeout: 10_000 })

		await openTaskEdit(page)
		const offer = formDialog(page).getByTestId('cn-form-dialog-draft-offer')
		await expect(offer, 'the form says unsaved input was kept').toBeVisible({ timeout: 10_000 })
		// OFFERED, not applied: before Restore the field holds the stored title.
		await expect(title).not.toHaveValue(typed)

		await offer.getByTestId('cn-form-dialog-draft-restore').click()
		await expect(title, 'the typed value is back after Restore').toHaveValue(typed)

		await page.context().close()
	})

	/**
	 * @spec openspec/changes/task-forms-keep-a-draft/specs/task-management/spec.md#requirement-a-task-form-keeps-what-you-typed-req-tm-draft-01
	 */
	test('Save draft stores the case as a draft with required fields empty @spec REQ-TM-DRAFT-01', async ({
		browser,
		baseURL,
		playwright,
	}) => {
		const page = await handlerPage(browser, String(baseURL))
		await page.goto('/index.php/apps/dossiq/', PAGE_LOAD)
		await page.getByRole('button', { name: 'New case', exact: true }).click()
		const dialog = formDialog(page)
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// Title and case type are the case's required fields; both stay empty.
		const marker = `${RUN_PREFIX} voorlopige omschrijving`
		await dialog
			.locator('[data-cn-field="description"] textarea, [data-cn-field="description"] input')
			.first()
			.fill(marker)

		const saved = page.waitForResponse(
			(res) => res.request().method() === 'POST'
				&& /\/api\/objects\/dossiq\/case(\?|$)/.test(res.url()),
			{ timeout: 30_000 },
		)
		await dialog.getByTestId('cn-form-dialog-save-draft').click()
		const response = await saved
		expect(response.ok(), await response.text()).toBeTruthy()

		const body = await response.json()
		const id = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')
		expect(id, 'the draft came back with an id').not.toBe('')
		trackCreatedObject('case', id)

		await expect(
			dialog.locator('.input-field__helper-text-message--error, [role="alert"]'),
			'no required-field error is shown',
		).toHaveCount(0)

		// Read back as the author: a draft case shows only to them.
		const basic = Buffer.from(`${HANDLER}:${HANDLER_PASS}`).toString('base64')
		const asHandler = await playwright.request.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: { Authorization: `Basic ${basic}`, 'OCS-APIRequest': 'true' },
		})
		const stored = await showObject(asHandler, 'case', id)
		expect(stored?.isDraft, 'the case is stored in status draft').toBe(true)
		expect(stored?.description, 'with the values given').toBe(marker)

		await page.context().close()
	})
})
