/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakGerelateerd "Zaak koppelen": the Related tab offers Link case,
 * a CnActionDialog whose case field is a search over dossiq/case. Typing part
 * of a case's title finds it; the case being linked FROM is never offered
 * (`excludeSelf`), and the relation type is one of the five the relations API
 * accepts.
 *
 * `caseLinkCaseDialog.spec.js` proves the manifest declares the field. Only a
 * browser shows that the search field actually asks OpenRegister and fills
 * its options from the answer, which is where a wrong `source` or a wrong
 * `_search` parameter would silently offer nothing.
 *
 * Nothing is linked: the dialog is cancelled after the search.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** A word only the cases of this run carry in their title. */
const WORD = `${RUN_PREFIX} Koppelzaak`

let api: APIRequestContext
let token = ''
const cases: Record<string, string> = {}

/**
 * Open a case on its Related tab and return the open panel.
 *
 * @param page   The page under test.
 * @param caseId The case id.
 */
async function openRelatedTab(page: Page, caseId: string) {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	await strip.getByRole('tab', { name: /^(Related|Gerelateerd)/ }).first().click()
	return strip.locator('.cn-tabs__content > [role="tabpanel"]:not([hidden])')
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	for (const key of ['from', 'target']) {
		cases[key] = objectId(
			await seedCase(api, token, {
				title: `${WORD} ${key}`,
				caseType: machine.caseTypeId,
				description: 'Seeded by the link-case-searches-for-the-case layer.',
			}),
		)
	}
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('Link case searches for the case to link', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/related-case-linking/spec.md#relation-type-is-mandatory-and-constrained
	// @e2e openspec/specs/related-case-linking/spec.md#self-relation-rejected
	test('typing a title finds the other case and never offers this one', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		const panel = await openRelatedTab(page, cases.from)

		await panel.getByRole('button', { name: /^(Link case|Zaak koppelen)$/ }).first().click()
		const dialog = page.getByTestId('cn-action-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('dialog', { name: /^(Link case|Zaak koppelen)$/ })).toBeVisible()

		// The search asks OpenRegister's case list with the typed term.
		const field = dialog.getByTestId('cn-action-dialog-field-targetId')
		await expect(field.getByTestId('cn-search-select')).toBeVisible()
		const searched = page.waitForRequest(
			(request) => request.url().includes('/apps/openregister/api/objects/dossiq/case')
				&& new URL(request.url()).searchParams.get('_search') === WORD,
			{ timeout: 20_000 },
		)
		await field.locator('input').first().fill(WORD)
		await searched

		const options = page.getByRole('option')
		await expect(options.filter({ hasText: `${WORD} target` })).toHaveCount(1, { timeout: 20_000 })
		await expect(options.filter({ hasText: `${WORD} from` })).toHaveCount(0)

		// The relation type offers the five the relations API takes, no more.
		const relation = dialog.getByTestId('cn-action-dialog-field-aardRelatie')
		await relation.locator('input').first().click()
		await expect(page.getByRole('option')).toHaveCount(5)
		await page.keyboard.press('Escape')

		await dialog.getByTestId('cn-action-dialog-cancel').click()
		await expect(dialog).toHaveCount(0, { timeout: 15_000 })
		expect(errors, 'no dossiq errors while searching').toEqual([])
	})
})
