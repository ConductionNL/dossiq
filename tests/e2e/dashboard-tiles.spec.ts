/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The landing page shows your work once.
 *
 * `dashboard-tiles` merged four object-table widgets into two: `my-tasks` +
 * `task-reminders` became `my-work`, and `overdue-cases` + `deadline-alerts`
 * became `deadlines`. A vitest can hold the declarations to each other, and
 * `tests/vitest/dashboardWorkTables.spec.js` does. Only a browser can show
 * that the merged tables fetch, that the days-left column resolves against a
 * live OpenRegister, and that the row past its deadline actually reads red.
 *
 * HOW A WIDGET IS ADDRESSED. `CnDashboardGrid` puts an `aria-label` on every
 * `.grid-stack-item`, and with no `title` on a layout entry that label falls
 * through to the widget id. So `[aria-label="my-work"]` is the widget, and it
 * survives a title change and a translated instance. Nothing forces the
 * language of the e2e instance, so no assertion here turns on English chrome:
 * the only text asserted is text this fixture seeded.
 *
 * WHAT THE SEED CANNOT CONTROL. `deadlines` shows ten rows ordered by deadline
 * ascending, and the demo caseload already carries more than ten overdue
 * cases. A case seeded to fall due in two days therefore sits below the cut by
 * construction. The near-deadline scenario asserts it in the table when there
 * is room and through the table's own View all when there is not, because that
 * link carries the table's filter and is the promise the tile makes.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/** A day in milliseconds. */
const DAY = 24 * 60 * 60 * 1000

/** The five KPI tiles the dashboard declares. */
const KPI_TILE_COUNT = 5

/** The row limit both merged tables declare. */
const ROW_LIMIT = 10

let api: APIRequestContext
let token = ''
let currentUser = ''

/** The case type this spec owns: a seven-day deadline it can aim. */
let caseTypeId = ''
/** A status flagged final, so a case can be closed and still be past due. */
let finalStatusId = ''

/** Titles, so every assertion reads text this fixture wrote. */
const OVERDUE_CASE = `${RUN_PREFIX} deadline passed`
const SOON_CASE = `${RUN_PREFIX} deadline in two days`
const CLOSED_CASE = `${RUN_PREFIX} closed but past due`
const TASK_SOON = `${RUN_PREFIX} task due tomorrow`
const TASK_MID = `${RUN_PREFIX} task due next week`
const TASK_LATE = `${RUN_PREFIX} task due next month`

/** The case types the New case picker must and must not offer. */
const PUBLISHED_TYPE = `${RUN_PREFIX} published type`
const DRAFT_TYPE = `${RUN_PREFIX} draft type`

/**
 * A date `days` from now, as `YYYY-MM-DD`.
 *
 * @param days Offset in whole days; negative is in the past.
 * @return The ISO date.
 */
function isoDay(days: number): string {
	return new Date(Date.now() + days * DAY).toISOString().slice(0, 10)
}

/**
 * Open the dashboard from a hard load and settle the support dialog.
 *
 * A HARD load, deliberately: the widget catalog used to register only inside
 * the lazy detail-page chunk, so the tiles were fine after a client-side visit
 * and broken as the first page of a session. Client-side navigation cannot
 * tell the two apart.
 *
 * @param page The Playwright page.
 */
async function openDashboard(page: Page): Promise<void> {
	await page.goto(`/index.php/apps/${REGISTER}/`)
	await dismissSupportDialog(page)
}

/**
 * One dashboard widget, addressed by its manifest id.
 *
 * @param page The Playwright page.
 * @param id   The widget id.
 * @return The grid item locator.
 */
function widget(page: Page, id: string): Locator {
	return page.locator(`[aria-label="${id}"]`)
}

/**
 * The rows of an object-table widget, once it has answered.
 *
 * @param table The widget locator.
 * @return The row locator.
 */
function rows(table: Locator): Locator {
	return table.locator('[data-testid="cn-object-row"]')
}

test.describe('Dashboard tiles', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json')
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// `deadline` is READ-ONLY on the case: OpenRegister computes it as
		// startDate + the case type's processingDeadline. So the deadline is
		// aimed by owning the case type and moving the start date, never by
		// writing `deadline` directly.
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Deadlines`,
			identifier: `${RUN_PREFIX.toLowerCase()}-deadlines`,
			description: 'Throwaway caseType for the dashboard-tiles e2e layer.',
			processingDeadline: 'P7D',
			isDraft: false,
		})
		caseTypeId = objectId(caseType)

		// `isFinalStatus` is computed from the linked statusType's isFinal, so
		// closing a case means pointing it at a final status.
		const open = await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Open`,
			caseType: caseTypeId,
			order: 1,
			isFinal: false,
		})
		const done = await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Done`,
			caseType: caseTypeId,
			order: 2,
			isFinal: true,
		})
		finalStatusId = objectId(done)

		const overdue = await seedCase(api, token, {
			title: OVERDUE_CASE,
			caseType: caseTypeId,
			status: objectId(open),
			startDate: isoDay(-30),
		})
		await seedCase(api, token, {
			title: SOON_CASE,
			caseType: caseTypeId,
			status: objectId(open),
			startDate: isoDay(-5),
		})
		await seedCase(api, token, {
			title: CLOSED_CASE,
			caseType: caseTypeId,
			status: finalStatusId,
			startDate: isoDay(-40),
		})

		const onCase = objectId(overdue)
		for (const [title, due] of [
			[TASK_SOON, isoDay(1)],
			[TASK_MID, isoDay(7)],
			[TASK_LATE, isoDay(30)],
		]) {
			await createObject(api, token, 'caseTask', {
				title,
				case: onCase,
				assignee: currentUser,
				status: 'available',
				dueDate: `${due}T09:00:00+00:00`,
			})
		}

		// The picker fixtures. The draft one carries the schema default for
		// `isDraft` explicitly, so the test cannot pass on a missing field.
		await createObject(api, token, 'caseType', {
			title: PUBLISHED_TYPE,
			identifier: `${RUN_PREFIX.toLowerCase()}-published`,
			description: 'Published case type, which the New case form must offer.',
			isDraft: false,
		})
		await createObject(api, token, 'caseType', {
			title: DRAFT_TYPE,
			identifier: `${RUN_PREFIX.toLowerCase()}-draft`,
			description: 'Draft case type, which the New case form must not offer.',
			isDraft: true,
		})
	})

	test.afterAll(async () => {
		if (!api) return
		// Tasks only. A case is archival and cannot be removed by a user, and
		// deleting the case type would leave those cases pointing at a type
		// that is gone. Both carry RUN_PREFIX, so global-setup's residue sweep
		// takes them before the next run.
		await cleanupRunObjects(api, token, ['caseTask'])
		await api.dispose()
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-fresh-session-lands-on-the-dashboard
	// @e2e dashboard::kpi-tiles-render-on-a-fresh-load
	test('every KPI tile shows a number on the first load of a session', async ({
		page,
	}) => {
		await openDashboard(page)
		const tiles = page.locator('.cn-stat-widget')
		await expect(tiles.first()).toBeVisible({ timeout: 30_000 })
		await expect(tiles).toHaveCount(KPI_TILE_COUNT)
		await expect(page.getByText('Widget not available')).toHaveCount(0)
		for (const tile of await tiles.all()) {
			await expect(tile.locator('.cn-kpi-card__value')).toHaveText(/\d/, {
				timeout: 15_000,
			})
		}
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-your-tasks-appear-once-with-days-left
	// @e2e dashboard::one-work-table-with-days-left-and-row-actions
	test('My work lists each of your open tasks once, with days left', async ({
		page,
	}) => {
		await openDashboard(page)
		const table = widget(page, 'my-work')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await expect(rows(table).first()).toBeVisible({ timeout: 30_000 })

		// ONCE each. This is the whole point of the merge: the two tiles this
		// table replaces showed seven of these ten rows twice between them.
		for (const title of [TASK_SOON, TASK_MID, TASK_LATE]) {
			await expect(
				rows(table).filter({ hasText: title }),
				`${title} on My work`,
			).toHaveCount(1)
		}

		// The task due tomorrow reads its distance, not its date. Which phrase
		// depends on the clock: the seed is 09:00 UTC tomorrow, so a run late
		// in the day on a machine ahead of UTC can legitimately read "today".
		const soon = rows(table).filter({ hasText: TASK_SOON })
		await expect(soon).toContainText(/1 days remaining|Due today/)

		// The case is named, not its uuid. `case.title` only resolves because
		// the widget extends `case`; without the extend this cell renders a
		// 36-character uuid, which is what this pattern refuses.
		await expect(soon).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)
		await expect(soon).toContainText(OVERDUE_CASE)
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-you-complete-a-task-from-the-row
	// @e2e dashboard::one-work-table-with-days-left-and-row-actions
	test('a My work row opens the task, which is where Pick up and Complete are', async ({
		page,
	}) => {
		// Row actions are blocked on nextcloud-vue: the object-table vocabulary
		// has no `rowActions` key, so the declared interim is the row route.
		// This test holds the interim, so the day the key lands and the route
		// is dropped, it says so.
		await openDashboard(page)
		const table = widget(page, 'my-work')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await rows(table).filter({ hasText: TASK_SOON }).first().click()
		await expect(page).toHaveURL(/\/tasks\/[^/]+$/, { timeout: 15_000 })
	})

	// @e2e openspec/specs/signalering-widgets/spec.md#scenario-overdue-and-near-deadline-cases-share-one-table
	// @e2e signalering-widgets::one-deadlines-table-replaces-the-overdue-and-deadline-alert-tiles
	test('Deadlines holds the overdue and the nearly due, overdue first and in red', async ({
		page,
	}) => {
		await openDashboard(page)
		const table = widget(page, 'deadlines')
		await expect(table).toBeVisible({ timeout: 30_000 })
		await expect(rows(table).first()).toBeVisible({ timeout: 30_000 })

		const overdueRow = rows(table).filter({ hasText: OVERDUE_CASE })
		await expect(overdueRow, 'the overdue case on Deadlines').toHaveCount(1)
		await expect(overdueRow).toContainText(/days overdue|dagen te laat/)
		// The red is a class on the row, declared as a rowClass rule, and the
		// stylesheet paints it with --color-error-text.
		await expect(overdueRow).toHaveClass(/cn-row--danger/)

		// Ordered by deadline, so every overdue row precedes every row that is
		// still in hand. Read the rendered classes rather than the dates: the
		// class IS the "past due" verdict the page shows.
		const danger = await rows(table).evaluateAll((els) =>
			els.map((el) => el.classList.contains('cn-row--danger')),
		)
		const lastDanger = danger.lastIndexOf(true)
		const firstSafe = danger.indexOf(false)
		if (lastDanger !== -1 && firstSafe !== -1) {
			expect(
				firstSafe,
				'a case still in hand sits above one already past its deadline',
			).toBeGreaterThan(lastDanger)
		}

		// The nearly-due case. `deadlines` shows ten rows ordered by deadline,
		// and the demo caseload carries more overdue cases than that, so a
		// case due in two days is below the cut by construction. When there is
		// room it must be in the table; when there is not, it must be in the
		// list the tile's own View all opens, which is the same filter.
		const rowCount = await rows(table).count()
		if (rowCount < ROW_LIMIT) {
			await expect(rows(table).filter({ hasText: SOON_CASE })).toHaveCount(1)
		} else {
			const viaFilter = await listObjects(api, 'case', {
				'deadline[lte]': isoDay(3),
				isFinalStatus: 'false',
				_limit: '200',
			})
			expect(
				viaFilter.some((row: any) => String(row.title ?? '') === SOON_CASE),
				'the nearly-due case is inside the window the tile filters on',
			).toBe(true)
		}
	})

	// @e2e openspec/specs/signalering-widgets/spec.md#scenario-closed-cases-stay-out
	// @e2e signalering-widgets::one-deadlines-table-replaces-the-overdue-and-deadline-alert-tiles
	test('a closed case stays off Deadlines, however late it was', async ({
		page,
	}) => {
		await openDashboard(page)
		const table = widget(page, 'deadlines')
		await expect(rows(table).first()).toBeVisible({ timeout: 30_000 })
		await expect(rows(table).filter({ hasText: CLOSED_CASE })).toHaveCount(0)
		// And nowhere else on the page either: the tiles that used to repeat
		// these rows are gone, not hidden.
		for (const retired of [
			'my-tasks',
			'task-reminders',
			'overdue-cases',
			'deadline-alerts',
		]) {
			await expect(
				widget(page, retired),
				`retired tile ${retired}`,
			).toHaveCount(0)
		}
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-view-all-from-the-deadlines-table
	// @e2e dashboard::view-all-keeps-the-tiles-filter
	test('View all on Deadlines opens the Cases list already filtered', async ({
		page,
	}) => {
		await openDashboard(page)
		const table = widget(page, 'deadlines')
		await expect(table).toBeVisible({ timeout: 30_000 })
		// CnDataTable renders View all as an anchor with no href, so it carries
		// no link role; match it by its text in either language.
		const viewAll = table.getByText(/View all|Alles bekijken/, { exact: true })
		await expect(viewAll).toBeVisible({ timeout: 15_000 })
		await viewAll.click()

		await expect(page).toHaveURL(/\/cases\?/, { timeout: 15_000 })
		const query = new URL(page.url()).searchParams
		expect(query.get('deadline[lte]')).toBe('@today+3d')
		expect(query.get('isFinalStatus')).toBe('false')
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-draft-case-types-are-absent
	// @e2e dashboard::the-case-type-list-on-new-case-is-sorted-and-filtered
	test('New case offers the published case type and not the draft', async ({
		page,
	}) => {
		await openDashboard(page)
		await page.getByRole('button', { name: /^(New case|Nieuwe zaak)$/i }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		const picker = dialog.getByRole('combobox', { name: /case ?type|zaaktype/i })
		await expect(picker).toBeVisible({ timeout: 15_000 })
		// Typing the run prefix narrows the picker to this fixture's own two
		// types, so nothing here depends on what else the instance holds.
		await picker.fill(RUN_PREFIX)

		const options = page.getByRole('option')
		await expect(options.filter({ hasText: PUBLISHED_TYPE })).toHaveCount(1, {
			timeout: 15_000,
		})
		await expect(
			options.filter({ hasText: DRAFT_TYPE }),
			'a draft case type must never reach the picker',
		).toHaveCount(0)
	})
})
