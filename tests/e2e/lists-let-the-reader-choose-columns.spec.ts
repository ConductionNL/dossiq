/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reader chooses which columns a list shows (REQ-CSL-COL-01).
 *
 * The Cases and Tasks lists mount the index side panel, and its Columns tab
 * lists every column of the list with a switch. Turning a switch off takes the
 * column out of the header without leaving the page; turning it on brings it
 * back.
 *
 * AS A HANDLER, NOT AS THE ADMIN. The project's `use.storageState` is the
 * admin session, and Playwright fills every option a `newContext` call leaves
 * out from that block. The handler's context is built from a session captured
 * through an explicitly empty state (`captureStorageState`), so nothing of the
 * admin rides along.
 *
 * THE CHOICE IS THE READER'S OWN AND PERSISTS. The library keeps a reader's
 * visible columns as a personal preference, so a run that stopped between
 * "off" and "on" would leave the handler's list without the column. The
 * afterEach turns it back on whatever happened.
 *
 * THE HEADER IS READ AS A SET OF LABELS, never by position or count: the Cases
 * index is a shared list on a shared instance.
 *
 * @spec openspec/changes/lists-let-the-reader-choose-columns/specs/case-search-and-lists/spec.md
 */

import type { BrowserContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	storageStatePath,
} from './helpers/auth.ts'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const CASES_URL = `/apps/${REGISTER}/cases`
const TASKS_URL = `/apps/${REGISTER}/tasks`

/** The ordinary account that reads the lists. */
const HANDLER = process.env.E2E_COLUMNS_USER ?? 'e2e-columns-handler'

/** Its password. Must satisfy the instance's password policy. */
const PASSWORD = process.env.E2E_COLUMNS_PASS ?? 'e2e-Columns-2026!'

/** The case-type column of the Cases list, in either shipped language. */
const TYPE_LABEL = /^(Case type|Zaaktype)$/

let handlerContext: BrowserContext

/**
 * The column labels the list header renders, trimmed and empties dropped.
 *
 * @param page The Playwright page.
 * @return The header cell texts.
 */
async function headerLabels(page: Page): Promise<string[]> {
	const cells = await page.locator('table thead th').allInnerTexts()
	return cells.map((c) => c.trim()).filter(Boolean)
}

/**
 * Whether the header holds a column whose label matches.
 *
 * @param page  The Playwright page.
 * @param label The label to look for.
 * @return True when one header cell matches.
 */
async function headerHas(page: Page, label: RegExp): Promise<boolean> {
	return (await headerLabels(page)).some((text) => label.test(text))
}

/**
 * Open the index side panel on its Columns tab and return the tab's content.
 *
 * By tab id, not by label: NcAppSidebarTab renders `#tab-button-<id>`, and the
 * library names the tab `columns-tab` in every language.
 *
 * @param page The Playwright page.
 * @return The Columns tab content.
 */
async function openColumnsTab(page: Page): Promise<Locator> {
	const sidebar = page.locator('.cn-index-sidebar-panel')
	if (!(await sidebar.isVisible())) {
		await page.getByTestId('cn-actions-bar-filter-button').click()
	}
	await expect(sidebar, 'the list opens its side panel').toBeVisible({ timeout: 30_000 })
	await sidebar.locator('#tab-button-columns-tab').click()
	const columns = sidebar.locator('.cn-sidebar-columns')
	await expect(columns, 'the side panel has a Columns tab').toBeVisible({ timeout: 15_000 })
	return columns
}

/**
 * The switch for one column in the Columns tab.
 *
 * @param columns The Columns tab content.
 * @param label   The column's label.
 * @return The switch's wrapper, which takes the click.
 */
function columnSwitch(columns: Locator, label: RegExp): Locator {
	return columns
		.locator('.cn-sidebar-columns__group-content .checkbox-radio-switch')
		.filter({ has: columns.page().locator('.checkbox-content__text', { hasText: label }) })
}

/**
 * Whether a column's switch is on.
 *
 * @param toggle The switch wrapper from `columnSwitch`.
 * @return True when its input is checked.
 */
async function isOn(toggle: Locator): Promise<boolean> {
	return await toggle.locator('input').isChecked()
}

test.describe('a reader chooses which columns a list shows', () => {
	test.setTimeout(120_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await provisioning.dispose()

		const state = storageStatePath(HANDLER)
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: HANDLER,
			password: PASSWORD,
			statePath: state,
		})
		handlerContext = await browser.newContext({ baseURL, storageState: state })
	})

	test.afterAll(async () => {
		await handlerContext?.close()
	})

	test.afterEach(async () => {
		// Leave the handler's personal layout as it was: the Type column on.
		const page = await handlerContext.newPage()
		try {
			await page.goto(CASES_URL, PAGE_LOAD)
			await dismissSupportDialog(page)
			const columns = await openColumnsTab(page)
			const toggle = columnSwitch(columns, TYPE_LABEL)
			if ((await toggle.count()) === 1 && !(await isOn(toggle))) {
				await toggle.click()
			}
		} catch {
			// The test itself reports what went wrong; cleanup is best effort.
		} finally {
			await page.close()
		}
	})

	// @e2e openspec/changes/lists-let-the-reader-choose-columns/specs/case-search-and-lists/spec.md#a-column-is-turned-off-and-on-again
	test('the Cases list drops the Type column and brings it back', async () => {
		const page = await handlerContext.newPage()
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		const url = page.url()

		await expect
			.poll(async () => await headerHas(page, TYPE_LABEL), {
				message: 'the Cases list does not show the Type column to begin with',
				timeout: 30_000,
			})
			.toBe(true)

		const columns = await openColumnsTab(page)
		const toggle = columnSwitch(columns, TYPE_LABEL)
		await expect(toggle, 'the Columns tab lists the Type column with a switch').toHaveCount(1)
		expect(await isOn(toggle), 'the Type switch is on while the column shows').toBe(true)

		await toggle.click()
		await expect
			.poll(async () => await headerHas(page, TYPE_LABEL), {
				message: 'turning Type off did not remove the column',
			})
			.toBe(false)
		expect(page.url(), 'turning a column off does not leave the page').toBe(url)

		await toggle.click()
		await expect
			.poll(async () => await headerHas(page, TYPE_LABEL), {
				message: 'turning Type on again did not bring the column back',
			})
			.toBe(true)
		expect(page.url(), 'turning a column on does not leave the page').toBe(url)

		await page.close()
	})

	// @e2e openspec/changes/lists-let-the-reader-choose-columns/specs/case-search-and-lists/spec.md#requirement-a-reader-chooses-which-columns-a-list-shows-req-csl-col-01
	test('the Tasks list offers a switch for every column it shows', async () => {
		const page = await handlerContext.newPage()
		await page.goto(TASKS_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(async () => (await headerLabels(page)).length, {
				message: 'the Tasks list shows no column header',
				timeout: 30_000,
			})
			.toBeGreaterThan(0)
		// The row-actions header is the table's own, not a data column.
		const shown = (await headerLabels(page)).filter((label) => !/^(Actions|Acties)$/.test(label))

		const columns = await openColumnsTab(page)
		const offered = (
			await columns
				.locator('.cn-sidebar-columns__group-content .checkbox-content__text')
				.allInnerTexts()
		).map((text) => text.trim())

		for (const label of shown) {
			expect(offered, `the Columns tab has no switch for the "${label}" column`).toContain(label)
		}

		await page.close()
	})
})
