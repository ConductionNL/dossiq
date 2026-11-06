/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqVolgendeStap: the lifecycle menu draws ONE entry, "Heropenen of
 * terughalen uit het archief" ("Alleen bij een afgeronde of gearchiveerde
 * zaak."), where it used to draw Reopen and Restore side by side. On an ended
 * case the entry reopens; on an archived case it restores, because an archived
 * case refuses every write until it is back.
 *
 * `reopenOrRestoreFromTheArchive.spec.js` proves the menu builder merges the
 * two. Only a real case shows which of the two the `/acts` and state answers
 * actually make the entry carry, so this layer reads the radio's value, the
 * act it will post, and never presses confirm.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

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
import { clickHeaderAction, PAGE_LOAD } from './helpers/nav.ts'

/** The one entry's label, in either locale. */
const ONE_ENTRY = /^(Reopen or restore from the archive|Heropenen of terughalen uit het archief)$/

/** The labels the two separate entries had, in either locale. */
const OLD_ENTRIES = /^(Reopen|Heropenen|Restore from the archive|Terughalen uit het archief)$/

let api: APIRequestContext
let token = ''
const cases: Record<string, string> = {}
let archivedRefused = ''

/**
 * Post one act on one case through dossiq's own endpoint.
 *
 * @param caseId The case id.
 * @param act    The act path.
 * @param body   The act's inputs.
 * @return The response.
 */
async function act(caseId: string, act: string, body: Record<string, unknown>) {
	return api.post(`/index.php/apps/${REGISTER}/api/case/${caseId}/${act}`, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: body,
	})
}

/**
 * Open a case's lifecycle menu and return its list.
 *
 * @param page   The page under test.
 * @param caseId The case id.
 * @return The menu's choice list.
 */
async function openLifecycleMenu(page: Page, caseId: string): Promise<Locator> {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 30_000 })
	await clickHeaderAction(page, 'cn-action-case-lifecycle-menu')
	const list = page.getByTestId('case-acts-list')
	await expect(list).toBeVisible({ timeout: 25_000 })
	await expect(list.locator('.cn-choice-cards__row').first()).toBeVisible({ timeout: 25_000 })
	return list
}

/**
 * The rows of the menu whose title matches.
 *
 * @param list  The menu's choice list.
 * @param title The title to match.
 * @return The rows.
 */
function rowsTitled(list: Locator, title: RegExp): Locator {
	return list.locator('.cn-choice-cards__row').filter({
		has: list.page().locator('.cn-choice-cards__row-title', { hasText: title }),
	})
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	for (const key of ['ended', 'archived']) {
		cases[key] = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Heropenen ${key}`,
				caseType: machine.caseTypeId,
				description: 'Seeded by the reopen-or-restore-is-one-entry layer.',
			}),
		)
		const finished = await act(cases[key], 'finish', {
			reason: 'Afgerond voor de e2e',
			resultTypeId: '',
		})
		expect(finished.ok(), `finish -> ${finished.status()} ${await finished.text()}`).toBeTruthy()
	}
	const archived = await act(cases.archived, 'archive', { reason: 'Naar het e-depot' })
	if (archived.status() === 403) {
		archivedRefused = 'this handler is not in the case type\'s archive role, so no case could be archived'
	} else {
		expect(archived.ok(), `archive -> ${archived.status()} ${await archived.text()}`).toBeTruthy()
	}
})

test.afterAll(async () => {
	// The marker comes off before the delete: an archived row is out of the
	// default lists the teardown reads.
	if (archivedRefused === '' && cases.archived) {
		await act(cases.archived, 'unarchive', { reason: 'e2e teardown' })
	}
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('Reopen and Restore are one lifecycle entry', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/case-management/spec.md#reopening-keeps-the-ending-in-the-record
	test('an ended case shows one entry, and it reopens', async ({ page }) => {
		const list = await openLifecycleMenu(page, cases.ended)

		const entry = rowsTitled(list, ONE_ENTRY)
		await expect(entry).toHaveCount(1)
		await expect(entry).toContainText(
			/Only for a finished or archived case\.|Alleen bij een afgeronde of gearchiveerde zaak\./,
		)
		await expect(entry.locator('input')).toHaveAttribute('value', 'gesture:reopen')
		await expect(entry.locator('input')).toBeEnabled()

		await expect(rowsTitled(list, OLD_ENTRIES)).toHaveCount(0)
		await expect(list.locator('input[value="state:unarchive"]')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#restore-in-one-action
	test('an archived case shows the same one entry, and it restores', async ({ page }) => {
		test.skip(archivedRefused !== '', archivedRefused)
		const list = await openLifecycleMenu(page, cases.archived)

		const entry = rowsTitled(list, ONE_ENTRY)
		await expect(entry).toHaveCount(1)
		await expect(entry.locator('input')).toHaveAttribute('value', 'state:unarchive')

		await expect(rowsTitled(list, OLD_ENTRIES)).toHaveCount(0)
		await expect(list.locator('input[value="gesture:reopen"]')).toHaveCount(0)
	})
})
