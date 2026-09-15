/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CPL-01 to REQ-CPL-05: the case list is somewhere a handler works from,
 * not somewhere they pass through.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. Every requirement here is a
 * declaration in `src/manifest.json` that some other repository renders.
 * `tests/vitest/caseListPlace.spec.js` proves the declaration is there and
 * that the router registers the second address it needs; it cannot prove the
 * list survived. A split view that re-fetches and re-scrolls on every case is
 * the same round trip with a narrower column, it satisfies every manifest
 * assertion, and only a browser can tell the two apart.
 *
 * THREE THINGS ABOUT THIS FILE CHANGE WHAT AN ASSERTION HERE CAN CLAIM.
 *
 * ONE. THE LIST IS SHARED. The Cases index holds another session's fixtures
 * while this one runs, so nothing is addressed by position or by count. The
 * scroll assertions compare the list's own scrollTop before and after, which
 * is a property of THIS page, and the row assertions address the run prefix.
 *
 * TWO. THE POSITION IS THE CAPABILITY, NOT THE LAYOUT. `expect(panes).toBe(2)`
 * would pass on a split view that reloaded the list every time. So the check
 * is: note where the list is, open a case, and require the list to still be
 * there. Two panes are asserted as well, but they are the weaker half.
 *
 * THREE. NEXT AND PREVIOUS ARE NOT ASSERTED TO EXIST ON A BARE LINK. A record
 * reached without list context must offer no step at all rather than guess an
 * order, so the negative is the assertion that carries the requirement.
 *
 * TWO TESTS BELOW ARE `fixme`, AND THE REASON IS NOT DOSSIQ'S CODE. Measured
 * against @conduction/nextcloud-vue 3.1.0, two of the five declarations this
 * change makes are read by nothing in the library yet:
 *
 *   - `listNavigation` is a prop on `CnDetailPage` with a default of null, and
 *     no code path passes it. `CnPageRenderer` mounts every manifest detail
 *     page and never sets it, so the next and previous controls cannot render
 *     for a manifest-driven app however the page is declared. REQ-CPL-02.
 *   - `pages[].referencePreview` validates against the v2 schema (2.33.0) and
 *     is read by no component. `CnReferencePreview` is exported and nothing
 *     mounts it from a page declaration. REQ-CPL-03.
 *
 * They are written out rather than deleted, and `fixme` rather than skipped,
 * so the day the library wires either one the missing half is a line to
 * delete instead of a test somebody has to think to write. The declarations
 * stay in the manifest for the same reason.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
const QUEUE_URL = `${APP_URL}queue`

/** Enough rows that the list scrolls, so a lost position is visible. */
const SEEDED = 40

let api: APIRequestContext
let token = ''
let caseTypeId = ''
let caseTypeTitle = ''
const seeded: string[] = []

/**
 * The scroll container of the case list.
 *
 * Addressed by role rather than by class: the table is the library's and its
 * class names are not dossiq's to depend on.
 *
 * @param page The browser page.
 */
function listBody(page: Page) {
	return page.getByRole('table').first()
}

/**
 * How far the list is scrolled, in pixels.
 *
 * Reads the nearest scrollable ancestor, because the table itself may not be
 * the element that scrolls.
 *
 * @param page The browser page.
 */
async function scrollOffset(page: Page): Promise<number> {
	return page.evaluate(() => {
		const table = document.querySelector('table')
		let node: HTMLElement | null = table as HTMLElement | null
		while (node) {
			if (node.scrollHeight > node.clientHeight + 1) {
				return node.scrollTop
			}
			node = node.parentElement
		}
		return window.scrollY
	})
}

test.describe('A case opens beside the list it came from', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id
		caseTypeTitle = caseType.name
		for (let i = 0; i < SEEDED; i++) {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} plaats ${String(i).padStart(2, '0')}`,
				caseType: caseTypeId,
			})
			seeded.push(objectId(row))
		}
	})

	test.afterAll(async () => {
		// Cases are archival records: a row left behind is counted by every
		// caseload figure this instance shows, so the sweep is not optional.
		await cleanupRunObjects(api, token)
	})

	test('the queue does not scroll back to the top @spec REQ-CPL-01', async ({
		page,
	}) => {
		await page.goto(QUEUE_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(listBody(page)).toBeVisible(PAGE_LOAD)

		const row = page.getByRole('row', { name: new RegExp(RUN_PREFIX) }).last()
		await row.scrollIntoViewIfNeeded()
		const before = await scrollOffset(page)
		expect(
			before,
			'the queue never scrolled, so this run cannot tell a kept position from a lost one',
		).toBeGreaterThan(0)

		await row.click()
		await expect(page).toHaveURL(/\/queue\/split\//, PAGE_LOAD)

		// The list is still mounted AND still where it was. Either half alone
		// passes on a page that re-rendered the list from scratch.
		await expect(listBody(page)).toBeVisible()
		expect(await scrollOffset(page)).toBe(before)
	})

	test('closing the case leaves the list where it was @spec REQ-CPL-01', async ({
		page,
	}) => {
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(listBody(page)).toBeVisible(PAGE_LOAD)

		const row = page.getByRole('row', { name: new RegExp(RUN_PREFIX) }).last()
		await row.scrollIntoViewIfNeeded()
		const before = await scrollOffset(page)
		await row.click()
		await expect(page).toHaveURL(/\/cases\/split\//, PAGE_LOAD)

		await page.getByRole('button', { name: /close|sluiten/i }).first().click()
		await expect(page).toHaveURL(/\/cases(\?|$)/, PAGE_LOAD)
		expect(await scrollOffset(page)).toBe(before)
	})

	// FIXME once the library hands `listNavigation` to a manifest-mounted
	// CnDetailPage. See the note at the top of this file.
	test.fixme('a handler walks a filtered queue @spec REQ-CPL-02', async ({
		page,
	}) => {
		await page.goto(`${CASES_URL}?caseType=${caseTypeId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(listBody(page)).toBeVisible(PAGE_LOAD)

		// Opening a row on a split-view page keeps the list's own address and
		// its query, so the filter the handler applied travels with the pane.
		await page.getByRole('row', { name: new RegExp(RUN_PREFIX) }).first().click()
		await expect(page).toHaveURL(/\/cases\/split\//, PAGE_LOAD)
		await expect(page).toHaveURL(new RegExp(`caseType=${caseTypeId}`), PAGE_LOAD)

		await page.getByRole('button', { name: /next|volgende/i }).first().click()
		// Still inside the filter: the next record is of the seeded case type.
		await expect(page.getByText(caseTypeTitle).first()).toBeVisible(PAGE_LOAD)
	})

	test('a record reached without a list offers no next @spec REQ-CPL-02', async ({
		page,
	}) => {
		// The mirror of the test above, and the one that carries the
		// requirement: guessing an order the reader never chose is worse than
		// offering no step at all.
		await page.goto(`${APP_URL}cases/${seeded[0]}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(
			page.getByRole('button', { name: /next|volgende/i }),
		).toHaveCount(0)
	})

	// FIXME once `pages[].referencePreview` mounts CnReferencePreview. See
	// the note at the top of this file.
	test.fixme('a related case is read without leaving @spec REQ-CPL-03', async ({
		page,
	}) => {
		await page.goto(`${APP_URL}cases/${seeded[0]}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const here = page.url()

		const reference = page
			.getByTestId('cn-reference-preview-trigger')
			.first()
		await reference.hover()
		await expect(
			page.getByTestId('cn-reference-preview-card').first(),
		).toBeVisible(PAGE_LOAD)
		// The summary appears without navigating away, which is the whole
		// point: the address must not have moved.
		expect(page.url()).toBe(here)
	})

	test('the open tab is in the address @spec REQ-CPL-01', async ({ page }) => {
		await page.goto(`${APP_URL}cases/${seeded[0]}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const tabs = page.getByRole('tab')
		await expect(tabs.first()).toBeVisible(PAGE_LOAD)
		await tabs.nth(1).click()
		await expect(page).toHaveURL(/_tab=/, PAGE_LOAD)
	})

	test('a held order is one person\'s alone @spec REQ-CPL-05', async ({
		page,
		browser,
	}) => {
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(listBody(page)).toBeVisible(PAGE_LOAD)

		const rows = page.getByRole('row', { name: new RegExp(RUN_PREFIX) })
		const firstBefore = await rows.first().innerText()
		await rows.nth(1).dragTo(rows.first())
		await expect(rows.first()).not.toHaveText(firstBefore)

		// A second context is a second person as far as the preference store
		// is concerned only when it signs in as one; a fresh context of the
		// same account would read the same preference back. So this asserts
		// the weaker, true thing: the order was never written onto the
		// records, which is what would leak it to everyone.
		const fresh = await browser.newContext()
		const other = await fresh.newPage()
		await other.goto(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${seeded[0]}`,
			PAGE_LOAD,
		)
		expect(await other.content()).not.toContain('cn_manual_order')
		await fresh.close()
	})

	test('the personal options are in the Nextcloud personal settings @spec REQ-CPL-04', async ({
		page,
	}) => {
		await page.goto('/settings/user/dossiq', PAGE_LOAD)
		await expect(page.locator('#dossiq-personal-settings')).toBeVisible(
			PAGE_LOAD,
		)
	})
})
