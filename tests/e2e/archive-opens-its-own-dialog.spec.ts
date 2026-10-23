/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqArchiveren: choosing Archiveren in the lifecycle menu closes the menu
 * and opens the case-archive dialog of its own ("Zaak archiveren"), with the
 * case on top, its readiness checklist and its reason field. The form no longer
 * folds open inside the menu.
 *
 * The unit test (`archiveFromTheMenuOpensItsOwnDialog.spec.js`) proves the
 * menu calls the page dispatcher with the `case-archive` header action. Only a
 * browser shows that the dispatcher on a real case page opens that dialog for
 * this case, and that the menu is gone when it does.
 *
 * Nothing is archived: the dialog is opened and cancelled. The case is ended
 * through dossiq's own finish endpoint in the setup, because Archiveren is only
 * enabled on an ended case.
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
import { clickHeaderAction, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The archive dialog's title, in either locale. */
const ARCHIVE_TITLE = /^(Archive this case|Zaak archiveren)$/

let api: APIRequestContext
let token = ''
let caseId = ''

/**
 * Open the case page and its lifecycle menu.
 *
 * @param page The page under test.
 */
async function openLifecycleMenu(page: Page): Promise<void> {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 30_000 })
	await clickHeaderAction(page, 'cn-action-case-lifecycle-menu')
	await expect(page.getByTestId('case-acts-list')).toBeVisible({ timeout: 25_000 })
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	caseId = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} Archiveren uit het menu`,
			caseType: machine.caseTypeId,
			description: 'Seeded by the archive-opens-its-own-dialog layer.',
		}),
	)
	const finished = await api.post(
		`/index.php/apps/${REGISTER}/api/case/${caseId}/finish`,
		{
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { reason: 'Afgerond voor de e2e', resultTypeId: '' },
		},
	)
	expect(finished.ok(), `finish -> ${finished.status()} ${await finished.text()}`).toBeTruthy()
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('Archiveren opens its own dialog', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/case-management/spec.md#archive-a-closed-case
	test('choosing Archiveren closes the menu and opens "Zaak archiveren" for this case', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await openLifecycleMenu(page)

		const list = page.getByTestId('case-acts-list')
		const archive = list.locator('input[value="ending:archive"]')
		await expect(archive, 'an ended case offers Archiveren').toBeEnabled({
			timeout: 20_000,
		})
		await list.locator('label', { has: archive }).click()

		// The menu has gone, and the act's form did not fold open in it.
		await expect(page.getByTestId('case-acts-dialog')).toHaveCount(0, {
			timeout: 15_000,
		})

		const dialog = page.getByTestId('cn-action-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('dialog', { name: ARCHIVE_TITLE })).toBeVisible()

		// The case it opened for: the summary reads this case, and the
		// readiness checklist asks this case's archive readiness.
		await expect(dialog.getByTestId('cn-action-dialog-summary')).toContainText(
			`${RUN_PREFIX} Archiveren uit het menu`,
			{ timeout: 20_000 },
		)
		await expect(dialog.getByTestId('cn-action-dialog-checklist')).toBeVisible()
		await expect(dialog.getByTestId('cn-action-dialog-check-ended')).toBeVisible()
		await expect(dialog.getByTestId('cn-action-dialog-reason')).toBeVisible()

		await dialog.getByTestId('cn-action-dialog-cancel').click()
		await expect(dialog).toHaveCount(0, { timeout: 15_000 })
		expect(errors, 'no dossiq errors while opening the dialog').toEqual([])

		// Cancelled, nothing was written.
		const acts = await api.get(`/index.php/apps/${REGISTER}/api/case/${caseId}/acts`, {
			headers: { requesttoken: token },
		})
		expect(acts.ok()).toBeTruthy()
		expect((await acts.json()).archived).toBe(false)
	})
})
