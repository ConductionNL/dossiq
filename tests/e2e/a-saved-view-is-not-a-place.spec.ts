/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CSL-05: a saved list of cases is a lens, not a place.
 *
 * Replaces `cases-views-are-places.spec.ts`, which went with the feature it
 * tested (retracted 23 September 2026). The pinning and presentation-switch
 * tests went with it.
 *
 * THE SUBJECT IS THE ADDRESS. Applying a view has always narrowed the list.
 * What this requirement pins is what the view writes into the page's own
 * address: its filters, its search term and its sort spelled `_order`, the
 * only spelling `parseSortKeysFromQuery` and OpenRegister read. The retracted
 * route wrote `_sortKey`/`_sortOrder`, which nothing reads, so a reload came
 * back in the default order while every in-page assertion stayed green. So
 * each test reads the ADDRESS after the act, and the reload test opens that
 * address in a fresh page that never applied the view.
 *
 * THE VIEW IS CREATED THROUGH THE API AS THE SIGNED-IN USER and deleted in
 * teardown: a saved view is per user and survives the run, and a leftover
 * joins the dropdown of every later run on this instance.
 *
 * Runs against the nightly instance, not in the build loop.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken, REGISTER, RUN_PREFIX } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const CASES_URL = `/apps/${REGISTER}/cases`
const VIEWS_API = '/index.php/apps/openregister/api/views'

/** The sort the view carries, in the one spelling the stack reads. */
const SORT = [{ key: 'title', order: 'desc' }]

let api: APIRequestContext
let token = ''
let viewId = ''

/**
 * Apply the seeded view from the saved views control on the Cases page.
 *
 * @param page The page, already on the Cases list.
 */
async function applyView(page: Page): Promise<void> {
	const chip = page.locator(
		`[data-testid="cn-saved-views-chip"][data-view-id="${viewId}"]`,
	)
	if ((await chip.count()) > 0) {
		await chip.first().click()
		return
	}
	await page.getByTestId('cn-saved-views-control').first().click()
	await page
		.locator(`[data-testid="cn-saved-views-item"][data-view-id="${viewId}"]`)
		.first()
		.click()
}

/**
 * The page's address, split into its path and its query.
 *
 * @param page The page.
 * @return The path and the query parameters.
 */
function addressOf(page: Page): { path: string; query: URLSearchParams } {
	const url = new URL(page.url())
	return { path: url.pathname, query: url.searchParams }
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const response = await api.post(VIEWS_API, {
		headers: { requesttoken: token },
		data: {
			name: `${RUN_PREFIX} lens`,
			description: '',
			isPublic: false,
			isDefault: false,
			query: {
				filters: { assignee: 'admin' },
				search: RUN_PREFIX,
				sortKeys: SORT,
			},
		},
	})
	expect(response.status(), 'creating the saved view').toBeLessThan(300)
	viewId = String((await response.json()).view.id)
})

test.afterAll(async () => {
	if (viewId !== '') {
		await api.delete(`${VIEWS_API}/${viewId}`, {
			headers: { requesttoken: token },
		})
	}
	await api.dispose()
})

test.describe('a saved view is a lens, not a place', () => {
	// @e2e openspec/specs/case-search-and-lists/spec.md#a-saved-view-narrows-the-list-at-the-pages-own-address
	test("narrows the list at the page's own address", async ({ page }) => {
		trackDossiqErrors(page)
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await applyView(page)

		await expect
			.poll(() => addressOf(page).query.get('_order'), { timeout: 10_000 })
			.not.toBeNull()
		const { path, query } = addressOf(page)
		expect(
			path.endsWith('/cases'),
			`still the page's own address: ${path}`,
		).toBe(true)
		expect(path).not.toContain('/views/')
		expect(query.get('assignee')).toBe('admin')
		expect(query.get('_search')).toBe(RUN_PREFIX)
		expect(JSON.parse(query.get('_order') ?? '[]')).toEqual(SORT)
		// The retired spelling is not written beside the new one.
		expect(query.has('_sortKey')).toBe(false)
		expect(query.has('_sortOrder')).toBe(false)
	})

	// @e2e openspec/specs/case-search-and-lists/spec.md#the-sort-a-view-carries-survives-a-reload
	test('keeps its sort when the address is opened in a fresh tab', async ({
		page,
		context,
	}) => {
		trackDossiqErrors(page)
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await applyView(page)
		await expect
			.poll(() => addressOf(page).query.get('_order'), { timeout: 10_000 })
			.not.toBeNull()
		const address = page.url()

		const fresh = await context.newPage()
		trackDossiqErrors(fresh)
		const listRequest = fresh.waitForRequest(
			(request) =>
				request.url().includes('/api/objects/')
				&& request.url().includes('_order'),
			{ timeout: 30_000 },
		)
		await fresh.goto(address, PAGE_LOAD)
		await dismissSupportDialog(fresh)

		// The list the fresh tab asks OpenRegister for carries the view's sort,
		// which is what "sorted the way the view was" means for a list whose
		// rows belong to other runs too.
		const request = await listRequest
		expect(decodeURIComponent(request.url())).toMatch(/_order/)
		expect(JSON.parse(addressOf(fresh).query.get('_order') ?? '[]')).toEqual(
			SORT,
		)
		await fresh.close()
	})

	// @e2e openspec/specs/case-search-and-lists/spec.md#clearing-the-filters-leaves-nothing-of-the-view-behind
	test('leaves nothing of itself behind when the filters are cleared', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await applyView(page)
		await expect
			.poll(() => addressOf(page).query.get('_order'), { timeout: 10_000 })
			.not.toBeNull()

		await page
			.getByRole('button', {
				name: /^(Clear all|Clear filters|Alles wissen|Filters wissen)$/,
			})
			.first()
			.click()

		await expect
			.poll(() => addressOf(page).query.has('assignee'), { timeout: 10_000 })
			.toBe(false)
		const { query } = addressOf(page)
		expect(query.has('_search')).toBe(false)
		expect(query.has('_order')).toBe(false)
	})
})
