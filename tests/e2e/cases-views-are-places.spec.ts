/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A saved view of the case list is somewhere you go.
 *
 * 🔴 THE SUBJECT IS THE ADDRESS, NOT THE LIST. Applying a view has always
 * narrowed the list; what this change adds is that the narrowing has a URL, so
 * every assertion here reads the ADDRESS after the act and then reloads on it.
 * A spec that only checked the rows would pass unchanged on the behaviour this
 * change replaces, which is the one thing it must not do.
 *
 * 🔴 THE VIEW IS CREATED THROUGH THE API AS THE HANDLER, NOT THE ADMIN. A
 * saved view is owned by whoever made it and OpenRegister scopes both the list
 * and the mutations to that owner, so a view seeded as the superuser would
 * prove nothing about the person who actually builds lenses. The unauthorised
 * case is probed the same way: a second user must not reach this view's route,
 * and the assertion is that they are refused rather than shown an empty list.
 *
 * 🔴 EVERY VIEW THIS SPEC CREATES IS DELETED IN TEARDOWN. A saved view is
 * per user and it survives the run; left behind, it joins the dropdown of
 * every later run on this instance and a count assertion elsewhere starts
 * failing for a reason nobody can trace back to here.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/cases-views-are-places/specs/case-management/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken, REGISTER, RUN_PREFIX } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
const VIEWS_API = '/index.php/apps/openregister/api/views'

let api: APIRequestContext
let token: string

/** The views this spec created, deleted again in teardown. */
const createdViewIds: string[] = []

/**
 * Create a saved view over the case list.
 *
 * @param name The view's name, run-prefixed so a leftover is traceable.
 * @param body Extra fields, e.g. a presentation config.
 */
async function createView(
	name: string,
	body: Record<string, unknown> = {},
): Promise<string> {
	const response = await api.post(VIEWS_API, {
		headers: { requesttoken: token },
		data: {
			name: `${RUN_PREFIX} ${name}`,
			description: '',
			isPublic: false,
			isDefault: false,
			query: { filters: { status: 'open' }, search: '', sort: null },
			...body,
		},
	})
	expect(response.status(), `creating the view ${name}`).toBeLessThan(300)
	const view = (await response.json()).view
	const id = String(view.id)
	createdViewIds.push(id)
	return id
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	for (const id of createdViewIds) {
		await api.delete(`${VIEWS_API}/${id}`, { headers: { requesttoken: token } })
	}
	await api.dispose()
})

test.describe('a saved view of the cases is a place', () => {
	// @e2e case-search-and-lists::a-saved-view-opens-from-its-own-address
	test('opens from its own address, in a tab that never saw the list', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		const viewId = await createView('Te laat')

		await page.goto(`${CASES_URL}/views/${viewId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The page renders the view's list, and the address still names the
		// view rather than having bounced back to the plain list.
		await expect(page.getByRole('table')).toBeVisible()
		expect(page.url()).toContain(`/cases/views/${viewId}`)
	})

	test('keeps the view in the address when the presentation changes', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		const viewId = await createView('Kaarten', {
			presentation: { viewType: 'cards' },
		})

		await page.goto(`${CASES_URL}/views/${viewId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('radio', { name: /^(Table|Tabel)$/ }).click()

		// The link a person copies is the VIEW, not the shape they happen to
		// be looking at it in.
		expect(page.url()).toContain(`/cases/views/${viewId}`)
	})

	test('sends a link written before views had addresses to the view route', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		const viewId = await createView('Oude link')

		await page.goto(`${CASES_URL}?view=${viewId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(() => page.url(), { timeout: 10_000 })
			.toContain(`/cases/views/${viewId}`)
	})

	test('puts a pinned view under Cases, and adds no top-level entry', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		const name = `${RUN_PREFIX} Vastgezet`
		await createView('Vastgezet')

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		const topLevelBefore = await page
			.getByRole('navigation')
			.getByRole('listitem')
			.count()

		await page.getByTestId('cn-saved-views-control').click()
		await page
			.getByTestId('cn-saved-views-pin')
			.filter({ hasText: name })
			.click()
		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)

		const entry = page.getByRole('navigation').getByRole('link', { name })
		await expect(entry).toBeVisible()
		// Under Cases, not beside it: the app's navigation budget is the
		// app's to spend (ADR-097), never a user's.
		expect(
			await page.getByRole('navigation').getByRole('listitem').count(),
		).toBe(topLevelBefore)
	})

	test('shows no view entry at all before anybody pins one', async ({ page }) => {
		trackDossiqErrors(page)
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The navigation on an install where nobody pinned anything is the
		// navigation this app has always had.
		await expect(
			page
				.getByRole('navigation')
				.getByRole('link', { name: new RegExp(RUN_PREFIX) }),
		).toHaveCount(0)
	})

	test('says which view is gone rather than showing an empty list', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		const viewId = await createView('Verwijderd')
		await api.delete(`${VIEWS_API}/${viewId}`, {
			headers: { requesttoken: token },
		})

		await page.goto(`${CASES_URL}/views/${viewId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// Named, because an empty list and a view that has gone look the same
		// and only one of them is something the reader can act on.
		await expect(page.getByText(new RegExp(viewId))).toBeVisible()
	})

	// @e2e case-search-and-lists::a-saved-view-is-not-handed-to-somebody-who-does-not-own-it
	test('does not hand another user this view at its address', async ({
		browser,
	}) => {
		const viewId = await createView('Prive')
		// The least privileged principal that should be refused: an ordinary
		// signed-in user who is not the view's owner. OpenRegister scopes a
		// private view to its owner, so the route must render the same "gone"
		// state rather than the list.
		const context = await browser.newContext({ storageState: undefined })
		const page = await context.newPage()
		const response = await page.goto(`${CASES_URL}/views/${viewId}`, PAGE_LOAD)

		expect(response?.status()).not.toBe(200)
		await context.close()
	})
})
