/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A colleague's draft is in no staff list until the reader asks for drafts.
 *
 * THE DRAFT IS SOMEBODY ELSE'S ON PURPOSE. OpenRegister keeps the caller's
 * own drafts in every list, so a draft the browsing account saved would show
 * under All and prove nothing about the chip. A second account saves it, and
 * the administrator, who may read it, looks for it.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, never by position or count: the
 * Cases index is a shared list on a shared instance.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here. Two things the
 * live pass has to confirm before trusting a red: that the second account may
 * create a case on the instance under test, and that `@self.status: "draft"`
 * on a create is how that instance takes a draft (OpenRegister
 * DraftStatusPolicy).
 *
 * @spec openspec/changes/staff-lists-show-drafts-on-request/specs/case-management/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

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
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { chooseLens, expectLensActive } from './helpers/lens.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const CASES_URL = `/apps/${REGISTER}/cases`

/** The colleague who saves the draft. */
const COLLEAGUE = 'e2e-drafts-colleague'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Drafts-2026!'

const CHIPS = {
	all: /^(All|Alle)$/,
	withDrafts: /^(With drafts|Met voorlopige zaken)$/,
}

const OPEN_TITLE = `${RUN_PREFIX} Open zaak`
const DRAFT_TITLE = `${RUN_PREFIX} Voorlopige zaak`

let adminApi: APIRequestContext
let colleagueApi: APIRequestContext
let caseTypeTitle = ''

/**
 * Every row of this run currently on the page.
 *
 * @param page The page.
 */
function ownRows(page: Page) {
	return page.getByRole('row').filter({ hasText: RUN_PREFIX })
}

/**
 * Narrow the Cases index to the case type this run seeded, so a row seeded
 * seconds ago is on page one.
 *
 * @param page The page.
 */
async function narrowToThisRun(page: Page): Promise<void> {
	const facet = page.getByRole('button', {
		name: new RegExp(
			`^${caseTypeTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s+\\d+)?$`,
		),
	})
	await expect(
		facet,
		`the sidebar should offer a case-type filter named ${caseTypeTitle}`,
	).toBeVisible({ timeout: 30_000 })
	await facet.click()
	await expect(ownRows(page).first()).toBeVisible({ timeout: 30_000 })
}

test.describe('Drafts on the Cases list', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		const adminToken = await getRequestToken(adminApi)

		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', COLLEAGUE, PASSWORD)
		await provisioning.dispose()

		const machine = await seedStateMachine(adminApi, adminToken)
		caseTypeTitle = machine.caseTypeTitle

		await seedCase(adminApi, adminToken, {
			title: OPEN_TITLE,
			caseType: machine.caseTypeId,
		})

		const colleagueState = storageStatePath(COLLEAGUE)
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: COLLEAGUE,
			password: PASSWORD,
			statePath: colleagueState,
		})
		colleagueApi = await playwright.request.newContext({
			baseURL,
			storageState: colleagueState,
		})
		await seedCase(colleagueApi, await getRequestToken(colleagueApi), {
			title: DRAFT_TITLE,
			caseType: machine.caseTypeId,
			'@self': { status: 'draft' },
		})
	})

	test.afterAll(async () => {
		await colleagueApi?.dispose()
		await cleanupRunObjects(adminApi, await getRequestToken(adminApi))
		await adminApi.dispose()
	})

	// @e2e openspec/changes/staff-lists-show-drafts-on-request/specs/case-management/spec.md#a-colleagues-draft-is-not-in-the-work-list
	test('a draft shows under With drafts only', async ({ page }) => {
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await narrowToThisRun(page)

		// Under All the open case is listed and the colleague's draft is not.
		await expectLensActive(page, CHIPS.all)
		await expect(ownRows(page).filter({ hasText: OPEN_TITLE })).toHaveCount(1)
		await expect(ownRows(page).filter({ hasText: DRAFT_TITLE })).toHaveCount(0)

		// The chip asks for drafts beside the rest: both rows are listed.
		await chooseLens(page, CHIPS.withDrafts)
		await expectLensActive(page, CHIPS.withDrafts)
		await expect(ownRows(page).filter({ hasText: DRAFT_TITLE })).toHaveCount(1, {
			timeout: 30_000,
		})
		await expect(ownRows(page).filter({ hasText: OPEN_TITLE })).toHaveCount(1)

		// Back to All: the draft leaves the list again.
		await chooseLens(page, CHIPS.all)
		await expect(ownRows(page).filter({ hasText: DRAFT_TITLE })).toHaveCount(0, {
			timeout: 30_000,
		})
	})
})
