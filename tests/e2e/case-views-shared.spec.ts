/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-views-shared-with-a-team task 3: a team lead shares a saved case view
 * read-only with their team, a member sees it with the same columns and
 * cannot overwrite it, and the share picker offers only the reader's own
 * teams (REQ-CSL-SHARE-01).
 *
 * THE SHARE IS OPENREGISTER'S. `View.sharedWith` holds `{ group, mode }`,
 * and OpenRegister's views controller refuses a write to a view the caller
 * does not own with 403 "You may not change these fields on this view",
 * naming the fields. The member half therefore asserts that refusal on the
 * API, then that the Cases side panel lists the view as one they may only
 * use: `CnSavedViewsPanel` marks the row `data-access="read"` and its Manage
 * menu offers Save a copy and nothing that writes.
 *
 * THE PICKER HALF IS fixme. `CnSavedViewShareFields` can narrow its groups
 * (`shareGroups: 'mine'`), but dossiq's list pages do not set
 * `savedViewsShareGroups` yet (task 2), so the picker still offers every group
 * the sharee API returns. The test is written so it turns green the day the
 * manifest says `mine`.
 *
 * SEEDED AND REMOVED. Three accounts, three teams (Nextcloud groups carrying
 * the run prefix) and one view; teardown deletes the view and the groups.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Browser, BrowserContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	storageStatePath,
} from './helpers/auth.ts'
import { ensureTeam, REGISTER, removeTeams, RUN_PREFIX } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'
import { unprivilegedRequestOptions } from './helpers/principals.ts'

/** The Cases list. */
const CASES_URL = `/apps/${REGISTER}/cases`

/** OpenRegister's saved views. */
const VIEWS_API = '/index.php/apps/openregister/api/views'

/** The team lead who shares, in two teams. */
const LEAD = 'e2e-share-lead'

/** A handler in the lead's first team only. */
const MEMBER = 'e2e-share-member'

/** Both accounts' password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Share-View-2026!'

/** The columns the lead's view carries, which a member must get unchanged. */
const COLUMNS = ['title', 'deadline', 'caseType']

let admin: APIRequestContext
let lead: APIRequestContext
let member: APIRequestContext
let viewId = ''
let teamVergunningen = { id: '', name: '' }
let teamWijk = { id: '', name: '' }
let notMine = { id: '', name: '' }

/**
 * Put an account in a group.
 *
 * @param uid   The account.
 * @param group The group id.
 */
async function addToGroup(uid: string, group: string): Promise<void> {
	const res = await admin.post(`/ocs/v2.php/cloud/users/${uid}/groups?format=json`, {
		form: { groupid: group },
	})
	expect(res.ok(), `adding ${uid} to ${group}: ${res.status()}`).toBeTruthy()
}

/**
 * A browser page logged in as one account.
 *
 * @param browser A launched browser.
 * @param baseURL The instance.
 * @param user    The account.
 *
 * @return The context and its page.
 */
async function loggedInPage(
	browser: Browser,
	baseURL: string,
	user: string,
): Promise<{ context: BrowserContext; page: Page }> {
	const statePath = storageStatePath(user)
	await captureStorageState(browser, { baseURL, user, password: PASSWORD, statePath })
	const context = await browser.newContext({ baseURL, storageState: statePath })

	return { context, page: await context.newPage() }
}

/**
 * Open the Cases list and its side panel on the Views tab.
 *
 * @param page The page.
 *
 * @return The saved views panel.
 */
async function openViewsTab(page: Page): Promise<Locator> {
	await page.goto(CASES_URL, PAGE_LOAD)
	await dismissSupportDialog(page)
	const sidebar = page.locator('.cn-index-sidebar-panel')
	if (!(await sidebar.isVisible())) {
		await page.getByTestId('cn-actions-bar-filter-button').click()
	}
	await expect(sidebar, 'the list opens its side panel').toBeVisible({ timeout: 30_000 })
	await sidebar.locator('#tab-button-filters-tab').click()
	const panel = page.getByTestId('cn-saved-views-panel')
	await expect(panel).toBeVisible({ timeout: 15_000 })

	return panel
}

test.describe('A saved case view shared with a team', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', LEAD, PASSWORD)
		await ensureUser(admin, '', MEMBER, PASSWORD)

		teamVergunningen = await ensureTeam(admin, '', 'Team Vergunningen')
		teamWijk = await ensureTeam(admin, '', 'Wijkteam Noord')
		notMine = await ensureTeam(admin, '', 'Team Belastingen')
		await addToGroup(LEAD, teamVergunningen.id)
		await addToGroup(LEAD, teamWijk.id)
		await addToGroup(MEMBER, teamVergunningen.id)

		lead = await playwright.request.newContext(unprivilegedRequestOptions(String(baseURL), LEAD, PASSWORD))
		member = await playwright.request.newContext(unprivilegedRequestOptions(String(baseURL), MEMBER, PASSWORD))

		const res = await lead.post(VIEWS_API, {
			data: {
				name: `${RUN_PREFIX} Te beoordelen deze week`,
				description: '',
				isPublic: false,
				isDefault: false,
				query: {
					filters: { statusHiddenInLists: false },
					search: '',
					sort: [{ key: 'deadline', order: 'asc' }],
					columns: COLUMNS,
					scope: `${REGISTER}/case`,
					registers: [REGISTER],
					schemas: ['case'],
				},
				sharedWith: [{ group: teamVergunningen.id, mode: 'read' }],
			},
		})
		expect(res.status(), `the lead saves and shares the view: ${await res.text()}`).toBeLessThan(300)
		viewId = String((await res.json()).view.id)
	})

	test.afterAll(async () => {
		if (viewId !== '') {
			await lead?.delete(`${VIEWS_API}/${viewId}`).catch(() => undefined)
		}
		await removeTeams(admin)
		await Promise.all([lead?.dispose(), member?.dispose()])
		await admin?.dispose()
	})

	// @e2e openspec/changes/case-views-shared-with-a-team/specs/case-search-and-lists/spec.md#scenario-a-team-lead-shares-a-view-with-the-handling-desk
	test('a member reads the shared view with the owner\'s columns', async () => {
		const listed = await member.get(`${VIEWS_API}?_limit=500`)
		expect(listed.ok(), `the member lists their views: ${listed.status()}`).toBeTruthy()
		const ids = ((await listed.json())?.results ?? []).map((view: any) => String(view.id))
		expect(ids, 'the shared view is among the member\'s views').toContain(viewId)

		const shown = await member.get(`${VIEWS_API}/${viewId}`)
		expect(shown.ok(), `the member opens the view: ${shown.status()}`).toBeTruthy()
		const view: any = (await shown.json()).view
		expect(view.query.columns, 'the same columns as the owner saved').toEqual(COLUMNS)
		if (view['@self']?.access !== undefined) {
			expect(view['@self'].access, 'read-only for a member').toBe('read')
		}
	})

	// @e2e openspec/changes/case-views-shared-with-a-team/specs/case-search-and-lists/spec.md#scenario-a-team-lead-shares-a-view-with-the-handling-desk
	test('the member sees it in the side panel as a view they may only use', async ({ browser, baseURL }) => {
		const { context, page } = await loggedInPage(browser, String(baseURL), MEMBER)
		try {
			trackDossiqErrors(page)
			const panel = await openViewsTab(page)
			const shared = panel.locator(`[data-testid="cn-saved-views-item"][data-view-id="${viewId}"]`)
			await expect(shared, 'the shared view is listed for the member').toBeVisible({ timeout: 30_000 })
			await expect(shared).toHaveAttribute('data-access', 'read')

			await panel.locator(`[data-testid="cn-saved-views-manage"][data-view-id="${viewId}"]`).locator('button').first().click()
			await expect(page.locator(`[data-testid="cn-saved-views-copy"][data-view-id="${viewId}"]`)).toBeVisible({ timeout: 10_000 })
			for (const entry of ['edit', 'update', 'share', 'delete']) {
				await expect(
					page.locator(`[data-testid="cn-saved-views-${entry}"][data-view-id="${viewId}"]`),
					`a read-only member is not offered ${entry}`,
				).toHaveCount(0)
			}
		} finally {
			await context.close()
		}
	})

	// @e2e openspec/changes/case-views-shared-with-a-team/specs/case-search-and-lists/spec.md#scenario-a-team-lead-shares-a-view-with-the-handling-desk
	test('a member cannot overwrite or delete the view', async () => {
		const before: any = (await (await lead.get(`${VIEWS_API}/${viewId}`)).json()).view

		const put = await member.put(`${VIEWS_API}/${viewId}`, {
			data: { ...before, query: { ...before.query, search: `${RUN_PREFIX} overwritten` } },
		})
		expect(put.status(), `a member's overwrite is refused: ${await put.text()}`).toBe(403)
		expect(await put.text()).toMatch(/may not change these fields/i)

		const patch = await member.patch(`${VIEWS_API}/${viewId}`, { data: { name: `${RUN_PREFIX} renamed` } })
		expect(patch.status(), `a member's rename is refused: ${await patch.text()}`).toBe(403)

		const removed = await member.delete(`${VIEWS_API}/${viewId}`)
		expect(removed.status(), 'a member\'s delete is refused').toBeGreaterThanOrEqual(400)
		expect(removed.status()).toBeLessThan(500)

		const after: any = (await (await lead.get(`${VIEWS_API}/${viewId}`)).json()).view
		expect(after.name, 'the refused writes left the view as the lead saved it').toBe(before.name)
		expect(after.query.search).toBe(before.query.search)
	})

	// @e2e openspec/changes/case-views-shared-with-a-team/specs/case-search-and-lists/spec.md#scenario-share-targets-are-the-readers-own-teams
	test('the share picker offers the lead\'s own two teams and no other group', async ({ browser, baseURL }) => {
		test.fixme(true, 'task 2 open: the list pages in src/manifest.json do not set savedViewsShareGroups: mine, so the picker lists every group')
		const { context, page } = await loggedInPage(browser, String(baseURL), LEAD)
		try {
			trackDossiqErrors(page)
			const panel = await openViewsTab(page)
			await panel.getByTestId('cn-saved-views-save').click()
			const share = page.getByTestId('cn-saved-view-share-groups')
			await expect(share, 'the save dialog offers a share picker').toBeVisible({ timeout: 15_000 })

			// The run prefix matches all three teams, so the answer separates
			// the two the lead is in from the one they are not.
			await share.locator('input').first().fill(RUN_PREFIX)
			const options = page.getByRole('option')
			await expect(options.filter({ hasText: teamVergunningen.name })).toBeVisible({ timeout: 15_000 })
			await expect(options.filter({ hasText: teamWijk.name })).toBeVisible()
			await expect(options.filter({ hasText: notMine.name }), 'a group the lead is not in is not offered').toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
