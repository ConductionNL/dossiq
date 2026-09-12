/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for my-work spec.
 *
 * My Work is now a standard CnIndexPage card list scoped to the current
 * user (assignee = current uid), not the legacy 4-tab case+task board.
 * Each test is tagged with the scenario it covers.
 *
 * ⚠️ THE LIST NEEDS A ROW OF ITS OWN, SO THIS FILE SEEDS ONE
 * ---------------------------------------------------------
 * Both tests used to assert only chrome: the sort buttons, the Cards
 * toggle, and "no Internal Server Error" after clicking Table. None of that
 * can fail when the view defaults to the wrong mode or the table drops a
 * column, and on the shared rig the admin had no assigned case at all, so
 * there was never a card or a table to look at. CnDataTable renders no
 * `<table>` without rows. So one case assigned to the signed-in user is
 * seeded, and the page is narrowed to it by title (the index passes a
 * `title` query parameter through as a filter, measured 2026-09-11), so
 * neither answer depends on how many other cases that user holds.
 *
 * MUTATION POINTS, NOT YET RUN. The mutation runs were refused by the
 * permission system on 2026-09-11. Both points are client-side, in
 * `src/views/MyWorkCards.vue`:
 *
 *  - `viewMode="cards"` becomes `viewMode="table"`. Expected red: `My Work
 *    must open in card view, so the seeded case renders as a card`.
 *  - drop `'deadline'` from `columns()`. Expected red: `the table view must
 *    show identifier, title, case type, status and deadline, in that order`.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'
// Route named after the component that renders it, so this spec states WHICH
// screen it covers in executable code rather than in a comment.
import { MyWorkCards } from '../helpers/page-components.ts'

/** The seeded case. Assigned to the signed-in user, found by its title. */
const TITLE = `${RUN_PREFIX} My work case`

/** The signed-in user the storage state carries; global-setup logs in as it. */
const ME = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'

let api: APIRequestContext
let token: string
let identifier: string

test.describe('My Work spec coverage', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		const seeded = await seedCase(api, token, {
			title: TITLE,
			caseType: caseType.id,
			assignee: ME,
		})
		identifier = String(seeded.identifier)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open My Work narrowed to the seeded case.
	 *
	 * @param page The page.
	 */
	async function openMyWork(page: Page): Promise<void> {
		await page.goto(
			`/index.php/apps/dossiq${MyWorkCards}?title=${encodeURIComponent(TITLE)}`,
		)
		await dismissSupportDialog(page)
		// The My Work route renders NO page heading — measured on a CI runner
		// (2026-08-04) it exposes zero `heading` roles. Identify the view by
		// the sort controls unique to it.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 60_000,
		})
	}

	// @e2e openspec/specs/my-work/spec.md#card-and-table-view
	test('opens in card view, and offers a card/table toggle', async ({ page }) => {
		await openMyWork(page)

		// The DEFAULT is the claim, so nothing is clicked before it is read.
		// The seeded case must arrive as a card, and no table may be on the
		// page: a list that opened in table view renders the same case as a
		// row instead.
		await expect(
			page.locator('.mywork-card', { hasText: TITLE }),
			'My Work must open in card view, so the seeded case renders as a card',
		).toBeVisible({ timeout: 60_000 })
		await expect(page.locator('table')).toHaveCount(0)

		// And the toggle is offered, with Cards the pressed half.
		const cards = page.getByRole('button', { name: /Cards/ }).first()
		const table = page.getByRole('button', { name: /Table/ }).first()
		await expect(cards).toHaveAttribute('aria-pressed', 'true')
		await expect(table).toBeVisible()
		await expect(table).toHaveAttribute('aria-pressed', 'false')
	})

	// @e2e openspec/specs/my-work/spec.md#card-and-table-view
	test('the table view shows identifier, title, case type, status and deadline', async ({
		page,
	}) => {
		await openMyWork(page)
		// Wait for the list to answer before switching: the toggle paints with
		// the shell, and switching before the rows exist leaves a table view
		// with no `<table>` to read.
		await expect(page.locator('.mywork-card', { hasText: TITLE })).toBeVisible({
			timeout: 60_000,
		})

		await page.getByRole('button', { name: /Table/ }).first().click()
		const tableEl = page.locator('table').first()
		await expect(tableEl).toBeVisible({ timeout: 30_000 })

		// The five columns the scenario names, in order. The selection and
		// actions columns carry no text and are dropped; a sort arrow on the
		// active column is stripped.
		const headers = (await tableEl.getByRole('columnheader').allInnerTexts())
			.map((text) => text.replace(/[▲▼]/g, '').trim())
			.filter((text) => text !== '')
		expect(
			headers,
			'the table view must show identifier, title, case type, status and deadline, in that order',
		).toEqual(['Identifier', 'Title', 'Case type', 'Status', 'Deadline'])

		// And the seeded case is a ROW of that table, carrying its own
		// identifier and title rather than a placeholder.
		const row = tableEl.locator('tbody tr', { hasText: TITLE })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row).toContainText(identifier)
	})
})
