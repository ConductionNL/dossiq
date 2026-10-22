/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A link `/cases?savedView=<uuid>` applies the view. OpenRegister answers a
 * view with both a numeric `id` and a `uuid`, and a menu entry or a shared link
 * may name either; DqFilterInMenu stayed on "Alle zaken" because the page only
 * matched the numeric id.
 *
 * The control test opens the same view by its numeric id, which already
 * works, so a red on the uuid test is about the uuid and not about the view,
 * the seeding or the page.
 *
 * The view is created through the API as the signed-in user and deleted in
 * teardown: a saved view is per user and a leftover joins every later run's
 * dropdown.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken, REGISTER, RUN_PREFIX } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const CASES_URL = `/apps/${REGISTER}/cases`
const VIEWS_API = '/index.php/apps/openregister/api/views'

/**
 * Applying a view by uuid needs nextcloud-vue fix/saved-view-route-uuid.
 * Set DOSSIQ_E2E_UNRELEASED_NCV=1 on an instance built against it to run it
 * before the release.
 */
const NEEDS_RELEASE = 'needs nextcloud-vue fix/saved-view-route-uuid (1476e90970), batch 24t1b, '
	+ 'not in 2.127.0. Drop this fixme when dossiq\'s lock carries that release.'

let api: APIRequestContext
let token = ''
let viewId = ''
let viewUuid = ''

/**
 * Open the Cases list at `?savedView=<ref>` and wait for the list request
 * that carries the view's search term.
 *
 * @param page The page under test.
 * @param ref  The view's id or uuid.
 */
async function openByReference(page: Page, ref: string): Promise<void> {
	const applied = page.waitForRequest(
		(request) => request.url().includes('/api/objects/')
			&& new URL(request.url()).searchParams.get('_search') === RUN_PREFIX,
		{ timeout: 45_000 },
	)
	await page.goto(`${CASES_URL}?savedView=${encodeURIComponent(ref)}`, PAGE_LOAD)
	await dismissSupportDialog(page)
	await applied
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const response = await api.post(VIEWS_API, {
		headers: { requesttoken: token },
		data: {
			name: `${RUN_PREFIX} by uuid`,
			description: '',
			isPublic: false,
			isDefault: false,
			query: { filters: { assignee: 'admin' }, search: RUN_PREFIX },
		},
	})
	expect(response.status(), 'creating the saved view').toBeLessThan(300)
	const view = (await response.json()).view
	viewId = String(view.id)
	viewUuid = String(view.uuid ?? '')
	if (viewUuid === '') {
		const shown = await api.get(`${VIEWS_API}/${viewId}`, { headers: { requesttoken: token } })
		const body = await shown.json()
		viewUuid = String(body?.view?.uuid ?? body?.uuid ?? '')
	}
	expect(viewUuid, 'OpenRegister names the view by uuid too').not.toBe('')
	expect(viewUuid).not.toBe(viewId)
})

test.afterAll(async () => {
	if (viewId !== '') {
		await api.delete(`${VIEWS_API}/${viewId}`, { headers: { requesttoken: token } })
	}
	await api.dispose()
})

test.describe('?savedView= applies the view it names', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/case-search-and-lists/spec.md#a-saved-view-narrows-the-list-at-the-pages-own-address
	test('control: the numeric id applies the view', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await openByReference(page, viewId)
		expect(errors).toEqual([])
	})

	// @e2e openspec/specs/case-search-and-lists/spec.md#a-saved-view-narrows-the-list-at-the-pages-own-address
	test('the uuid applies the same view', async ({ page }) => {
		test.fixme(process.env.DOSSIQ_E2E_UNRELEASED_NCV !== '1', NEEDS_RELEASE)
		const errors = trackDossiqErrors(page)
		await openByReference(page, viewUuid)
		expect(errors).toEqual([])
	})
})
