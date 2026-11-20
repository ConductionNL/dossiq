/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * saved-filters-manage-and-defaults task 4: rename a saved view, replace its
 * criteria, delete it, copy a shipped view, add a view to the left menu and
 * take it out again (REQ-SVM-01, REQ-SVM-02, REQ-SVM-04, REQ-SVM-05).
 *
 * WHAT DRAWS WHAT. The Views tab of the Cases side panel (`#tab-button-
 * filters-tab`) is nextcloud-vue's `CnSavedViewsPanel`: one row per view
 * (`cn-saved-views-item`, keyed by `data-view-id`) and one "Manage" menu per
 * row (`cn-saved-views-manage`) holding Edit, Update with current filters,
 * Share, Save a copy, Add to menu / Remove from menu and Delete. The dialogs
 * are `CnSavedViewEditDialog` and `CnIndexPage`'s delete confirm. The left
 * menu is `CnAppNav`, where a pinned view is `cn-nav-entry-saved-view-<id>`.
 * Every selector below is one of those hooks or a label in either locale.
 *
 * THE PROOF IS THE STORED VIEW, NOT THE DIALOG. Each act is read back from
 * OpenRegister's `GET /api/views/{id}`: a rename that showed in the panel and
 * never reached the server would pass any assertion on the page alone.
 *
 * SEEDED AND REMOVED. Views are created through the API as the logged-in
 * admin and deleted in teardown, the copy of the shipped view included. The
 * left menu is the admin's `cn_saved_view_menu` preference; it is read before
 * the run and written back as it was, so no pinned entry outlives the run.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, provisioningContext } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The Cases list. */
const CASES_URL = `/apps/${REGISTER}/cases`

/** OpenRegister's saved views. */
const VIEWS_API = '/index.php/apps/openregister/api/views'

/** The admin's left-menu entries, as CnAppRoot stores them. */
const MENU_PREFERENCE = '/index.php/apps/dossiq/api/preferences/cn_saved_view_menu'

/** The shipped view the copy scenario copies (`defaultSavedViews` slug `my-cases`). */
const SHIPPED_VIEW = 'default:my-cases'

/** A colleague the shared view is shared with. */
const COLLEAGUE = 'e2e-views-colleague'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Views-Colleague-2026!'

/** The criteria every seeded view starts with. */
const QUERY = {
	filters: { isDraft: false },
	search: RUN_PREFIX,
	sort: [{ key: 'title', order: 'desc' }],
	scope: `${REGISTER}/case`,
	registers: [REGISTER],
	schemas: ['case'],
}

/** Labels, in either locale. */
const LABEL = {
	keep: /^(Keep what is saved|Behouden wat opgeslagen is)$/,
	replace: /^(Replace with the current search term, filters and sort|Vervangen door de huidige zoekterm, filters en sortering)$/,
	sharedNote: /(sees the change at once|ziet de wijziging meteen)/,
	addToMenu: /(Add to menu|Toevoegen aan menu)/,
	removeFromMenu: /(Remove from menu|Uit menu halen)/,
	deleteHint: /(Also for whoever it is shared with|Ook bij wie het is gedeeld)/,
	cancel: /^(Cancel|Annuleren)$/,
	deleteGoes: /(leaves your list and the menu on the left|verdwijnt uit uw lijst en uit het menu links)/,
	cannotUndo: /(cannot be undone|niet ongedaan maken)/,
}

let api: APIRequestContext
let colleague: APIRequestContext | null = null
let token = ''

/** The menu preference as it was before the run, or null when unset. */
let menuBefore: string | null = null

/** Every view this run created, deleted in teardown. */
const createdViews: string[] = []

/**
 * Create a saved view as the logged-in admin.
 *
 * @param name       The view's name.
 * @param sharedWith Who it is shared with, if anyone.
 *
 * @return The view's id.
 */
async function createView(
	name: string,
	sharedWith: Array<Record<string, string>> = [],
): Promise<string> {
	const res = await api.post(VIEWS_API, {
		headers: { requesttoken: token },
		data: {
			name,
			description: '',
			isPublic: false,
			isDefault: false,
			query: QUERY,
			...(sharedWith.length > 0 ? { sharedWith } : {}),
		},
	})
	expect(res.status(), `creating the saved view "${name}": ${await res.text()}`).toBeLessThan(300)
	const id = String((await res.json()).view.id)
	createdViews.push(id)

	return id
}

/**
 * Read a saved view back from OpenRegister.
 *
 * @param id      The view's id.
 * @param context Who reads it; the admin when left out.
 *
 * @return The view, or null when it does not resolve.
 */
async function readView(id: string, context: APIRequestContext = api): Promise<any> {
	const res = await context.get(`${VIEWS_API}/${id}`)
	if (res.status() === 404) {
		return null
	}
	expect(res.ok(), `reading view ${id}: ${res.status()} ${await res.text()}`).toBeTruthy()

	return (await res.json()).view
}

/**
 * The ids of every view the admin can see.
 *
 * @return The ids.
 */
async function viewIds(): Promise<string[]> {
	const res = await api.get(`${VIEWS_API}?_limit=500`)
	expect(res.ok(), `listing views: ${res.status()}`).toBeTruthy()
	const body: any = await res.json()

	return (body?.results ?? []).map((view: any) => String(view.id))
}

/**
 * Open the Cases list at an address and its side panel on the Views tab.
 *
 * @param page   The page.
 * @param search A search term on the list, so the list carries other criteria than the view.
 *
 * @return The saved views panel.
 */
async function openViewsTab(page: Page, search = ''): Promise<Locator> {
	const address = search === '' ? CASES_URL : `${CASES_URL}?_search=${encodeURIComponent(search)}`
	await page.goto(address, PAGE_LOAD)
	await dismissSupportDialog(page)

	const sidebar = page.locator('.cn-index-sidebar-panel')
	if (!(await sidebar.isVisible())) {
		await page.getByTestId('cn-actions-bar-filter-button').click()
	}
	await expect(sidebar, 'the list opens its side panel').toBeVisible({ timeout: 30_000 })
	// By tab id, not by label: the library names the Views tab `filters-tab` in every language.
	await sidebar.locator('#tab-button-filters-tab').click()
	const panel = page.getByTestId('cn-saved-views-panel')
	await expect(panel, 'the side panel has a Views tab').toBeVisible({ timeout: 15_000 })

	return panel
}

/**
 * One view's row in the Views tab.
 *
 * @param panel The saved views panel.
 * @param id    The view's id.
 *
 * @return The row's name button.
 */
function row(panel: Locator, id: string): Locator {
	return panel.locator(`[data-testid="cn-saved-views-item"][data-view-id="${id}"]`)
}

/**
 * Open one view's Manage menu.
 *
 * @param page  The page.
 * @param panel The saved views panel.
 * @param id    The view's id.
 */
async function openManage(page: Page, panel: Locator, id: string): Promise<void> {
	await expect(row(panel, id)).toBeVisible({ timeout: 30_000 })
	// The testid lands on NcActions' root, the toggle is the button inside it.
	const menu = panel.locator(`[data-testid="cn-saved-views-manage"][data-view-id="${id}"]`)
	await menu.locator('button').first().click()
	// Save a copy is offered for every view, whatever the reader's access, so it says the menu is open.
	await expect(manageEntry(page, 'copy', id)).toBeVisible({ timeout: 10_000 })
}

/**
 * One entry of an open Manage menu.
 *
 * @param page  The page.
 * @param entry The entry: edit, update, share, copy, menu or delete.
 * @param id    The view's id.
 *
 * @return The entry.
 */
function manageEntry(page: Page, entry: string, id: string): Locator {
	return page.locator(`[data-testid="cn-saved-views-${entry}"][data-view-id="${id}"]`)
}

/**
 * The left menu's entry for a pinned view.
 *
 * @param page The page.
 * @param id   The view's id.
 *
 * @return The entry.
 */
function menuEntry(page: Page, id: string): Locator {
	return page.getByTestId(`cn-nav-entry-saved-view-${id}`)
}

/**
 * Put a view in the left menu from its Manage menu.
 *
 * @param page  The page.
 * @param panel The saved views panel.
 * @param id    The view's id.
 */
async function addToMenu(page: Page, panel: Locator, id: string): Promise<void> {
	await openManage(page, panel, id)
	const entry = manageEntry(page, 'menu', id)
	await expect(entry).toContainText(LABEL.addToMenu)
	await entry.click()
	await expect(menuEntry(page, id), 'the view stands in the left menu').toBeVisible({ timeout: 20_000 })
}

/**
 * Type into a text field whose testid sits either on the input or on its wrapper.
 *
 * @param field The field.
 * @param value The text.
 */
async function fillField(field: Locator, value: string): Promise<void> {
	const inner = field.locator('input')
	if ((await inner.count()) > 0) {
		await inner.first().fill(value)
		return
	}
	await field.fill(value)
}

/**
 * The open edit dialog.
 *
 * @param page The page.
 *
 * @return The dialog.
 */
function editDialog(page: Page): Locator {
	return page.getByRole('dialog').filter({
		has: page.locator('[data-testid-modal="cn-saved-view-edit-dialog"]'),
	})
}

/**
 * The open delete confirm.
 *
 * @param page The page.
 *
 * @return The dialog.
 */
function deleteDialog(page: Page): Locator {
	return page.getByRole('dialog').filter({
		has: page.locator('[data-testid-modal="cn-confirm-dialog"]'),
	})
}

test.describe('A saved view is managed from the side panel', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', COLLEAGUE, PASSWORD)
		await provisioning.dispose()
		colleague = await playwright.request.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${COLLEAGUE}:${PASSWORD}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
			},
		})

		const stored = await api.get(MENU_PREFERENCE)
		if (stored.ok()) {
			const value = (await stored.json())?.value
			menuBefore = typeof value === 'string' ? value : null
		}
	})

	test.afterAll(async () => {
		for (const id of createdViews) {
			await api.delete(`${VIEWS_API}/${id}`, { headers: { requesttoken: token } }).catch(() => undefined)
		}
		// The admin's left menu as it was: the page writes it as views come and go.
		await api.put(MENU_PREFERENCE, {
			headers: { requesttoken: token },
			data: { value: menuBefore ?? '' },
		}).catch(() => undefined)
		await cleanupRunObjects(api, token, ['case', 'caseType'])
		await colleague?.dispose()
		await api.dispose()
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-rename-and-keep-the-criteria
	test('a rename that keeps the criteria shows in the panel and the left menu, and the criteria stay', async ({ page }) => {
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} Termijn deze week`)
		const renamed = `${RUN_PREFIX} Deze week`

		// The list carries other criteria than the view, so "keep" has something to keep it from.
		const panel = await openViewsTab(page, `${RUN_PREFIX} other`)
		await addToMenu(page, panel, id)

		await openManage(page, panel, id)
		await manageEntry(page, 'edit', id).click()
		const dialog = editDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await fillField(page.getByTestId('cn-saved-view-edit-name'), renamed)
		await dialog.getByText(LABEL.keep).first().click()
		await page.getByTestId('cn-saved-view-edit-confirm').click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect(row(panel, id)).toContainText(renamed, { timeout: 15_000 })
		await expect(menuEntry(page, id), 'the left menu follows the new name').toContainText(renamed, { timeout: 20_000 })

		const stored = await readView(id)
		expect(stored.name).toBe(renamed)
		expect(stored.query.search, 'the stored search term is the one the view had').toBe(QUERY.search)
		expect(stored.query.filters).toEqual(QUERY.filters)
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-replace-the-criteria
	test('replacing the criteria stores what the list shows now, and says the colleague sees it at once', async ({ page }) => {
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} Gedeeld`, [{ user: COLLEAGUE, mode: 'read' }])
		const current = `${RUN_PREFIX}-replaced`

		const panel = await openViewsTab(page, current)
		await openManage(page, panel, id)
		await manageEntry(page, 'edit', id).click()
		const dialog = editDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByText(LABEL.replace).first().click()
		await expect(dialog, 'the dialog says the colleague sees the change').toContainText(LABEL.sharedNote)
		await page.getByTestId('cn-saved-view-edit-confirm').click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect.poll(async () => (await readView(id)).query.search, { timeout: 15_000 }).toBe(current)
		const seen = await readView(id, colleague!)
		expect(seen?.query?.search, 'the colleague reads the new criteria straight away').toBe(current)
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-confirm-a-delete
	test('a confirmed delete takes the view out of the panel and the left menu, and leaves the cases', async ({ page }) => {
		trackDossiqErrors(page)
		const caseType = await ensureCaseType(api, token)
		const kept = await seedCase(api, token, { title: `${RUN_PREFIX} stays`, caseType: caseType.id })
		const id = await createView(`${RUN_PREFIX} Weg`)

		const panel = await openViewsTab(page)
		await addToMenu(page, panel, id)

		await openManage(page, panel, id)
		const entry = manageEntry(page, 'delete', id)
		await expect(entry, 'Delete says it goes for everyone it is shared with too').toContainText(LABEL.deleteHint)
		await entry.click()

		const dialog = deleteDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await expect(dialog).toContainText(LABEL.deleteGoes)
		await expect(dialog).toContainText(LABEL.cannotUndo)
		await page.getByTestId('cn-confirm-dialog-confirm').click()

		await expect(row(panel, id)).toHaveCount(0, { timeout: 20_000 })
		await expect(menuEntry(page, id)).toHaveCount(0, { timeout: 20_000 })
		expect(await readView(id), 'the view is gone from OpenRegister').toBeNull()

		const stillThere = await showObject(api, 'case', objectId(kept))
		expect(objectId(stillThere), 'the cases themselves stay').toBe(objectId(kept))
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-confirm-a-delete
	test('the delete confirm reads the sentence the board words', async ({ page }) => {
		test.fixme(true, 'task 1/2 open: nextcloud-vue words the confirm "Wat erin staat, blijft bestaan", not "De zaken zelf blijven staan", and names the view')
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} Zin`)

		const panel = await openViewsTab(page)
		await openManage(page, panel, id)
		await manageEntry(page, 'delete', id).click()
		const dialog = deleteDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await expect(dialog).toContainText(
			'De weergave verdwijnt uit uw lijst en uit het menu links. De zaken zelf blijven staan. Dit kunt u niet ongedaan maken.',
		)
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-cancel-a-delete
	test('cancelling the delete keeps the view', async ({ page }) => {
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} Blijft`)

		const panel = await openViewsTab(page)
		await openManage(page, panel, id)
		await manageEntry(page, 'delete', id).click()
		const dialog = deleteDialog(page)
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByRole('button', { name: LABEL.cancel }).click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		await expect(row(panel, id)).toBeVisible()
		expect(await readView(id), 'the view is still stored').not.toBeNull()
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-copy-a-shipped-view
	test('a shipped view offers only a copy, and the copy is the reader\'s own to edit and share', async ({ page }) => {
		trackDossiqErrors(page)
		const before = new Set(await viewIds())

		const panel = await openViewsTab(page)
		await openManage(page, panel, SHIPPED_VIEW)
		await expect(manageEntry(page, 'edit', SHIPPED_VIEW), 'a shipped view is not edited').toHaveCount(0)
		await expect(manageEntry(page, 'delete', SHIPPED_VIEW), 'a shipped view is not deleted').toHaveCount(0)
		await manageEntry(page, 'copy', SHIPPED_VIEW).click()

		let copyId = ''
		await expect.poll(async () => {
			copyId = (await viewIds()).find((id) => !before.has(id)) ?? ''
			return copyId
		}, { timeout: 20_000 }).not.toBe('')
		createdViews.push(copyId)

		const copy = await readView(copyId)
		expect(Object.values(copy.query.filters ?? {}), 'the copy keeps the shipped criteria, "handler is me"').toContain('@me')

		const own = row(panel, copyId)
		await expect(own, 'the copy appears under My views').toBeVisible({ timeout: 20_000 })
		await expect(own).toHaveAttribute('data-access', 'owner')
		await expect(own).not.toHaveAttribute('data-group', 'seeded')

		await openManage(page, panel, copyId)
		await expect(manageEntry(page, 'edit', copyId), 'the copy can be edited').toBeVisible()
		await expect(manageEntry(page, 'share', copyId), 'the copy can be shared').toBeVisible()
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-add-to-the-menu
	test('Add to menu puts the view under My views in the left menu, and the entry opens the list with its criteria', async ({ page }) => {
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} In het menu`)

		const panel = await openViewsTab(page)
		await addToMenu(page, panel, id)
		await expect(page.getByTestId('cn-nav-caption-saved-views-caption'), 'the My views group heads the entry').toBeVisible()

		const applied = page.waitForRequest(
			(request) => request.url().includes('/api/objects/')
				&& new URL(request.url()).searchParams.get('_search') === RUN_PREFIX,
			{ timeout: 45_000 },
		)
		await menuEntry(page, id).click()
		await applied
		await expect(page).toHaveURL(new RegExp(`savedView=${id}`))

		const stored = await api.get(MENU_PREFERENCE)
		expect(String((await stored.json())?.value ?? ''), 'the menu is stored for the user').toContain(`"${id}"`)
	})

	// @e2e openspec/changes/saved-filters-manage-and-defaults/specs/saved-filters-manage-and-defaults/spec.md#scenario-remove-from-the-menu
	test('Remove from menu takes the entry out of the left menu and keeps the view', async ({ page }) => {
		trackDossiqErrors(page)
		const id = await createView(`${RUN_PREFIX} Uit het menu`)

		const panel = await openViewsTab(page)
		await addToMenu(page, panel, id)

		await openManage(page, panel, id)
		const entry = manageEntry(page, 'menu', id)
		await expect(entry).toContainText(LABEL.removeFromMenu)
		await entry.click()

		await expect(menuEntry(page, id), 'the entry leaves the left menu').toHaveCount(0, { timeout: 20_000 })
		await expect(row(panel, id), 'the view stays in the side panel').toBeVisible()
		expect(await readView(id), 'the view is still stored').not.toBeNull()
	})
})
