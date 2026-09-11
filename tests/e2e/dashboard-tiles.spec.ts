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
 * WHAT THE SEED HAS TO CONTROL. `deadlines` shows ten rows ordered by deadline
 * ascending, and this spec used to reason about them on the assumption that the
 * demo caseload already carried more than ten overdue cases. It does not.
 * `ci-seed.sh` skips the demo dataset deliberately, so the window held two rows
 * and the tile was never truncated. That is why the near-deadline scenario
 * still branches on whether there is room, and why the fixture now seeds the
 * rows that fill the window itself. See `DEADLINE_FILLERS`.
 *
 * WHAT THE SEED CAN CONTROL, ONCE IT OWNS THE USER. `my-work` had the same
 * problem and does not have to keep it, because unlike `deadlines` it is
 * scoped to the current user: its filter is `assignee: @me`. So the My work
 * scenarios run as a dedicated account this spec provisions, whose only open
 * tasks are the three seeded below. See `WORK_USER`.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	STORAGE_STATE,
	storageStatePath,
} from './helpers/auth.ts'
import {
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dateTokenPattern, dismissSupportDialog } from './helpers/nav.ts'

/** A day in milliseconds. */
const DAY = 24 * 60 * 60 * 1000

/** The five KPI tiles the dashboard declares. */
const KPI_TILE_COUNT = 5

/** The row limit both merged tables declare. */
const ROW_LIMIT = 10

/**
 * How many extra cases this spec puts inside the Deadlines window, so the tile
 * is TRUNCATED and therefore offers its "View all" footer.
 *
 * 🔴 THE FOOTER IS CONDITIONAL, AND THE CONDITION IS ABOUT ROWS, NOT ABOUT THE
 * ROUTE. Read from source rather than guessed, because the failure it produces
 * says only "element(s) not found" and reads as a broken locator:
 *
 *   - the manifest tile sets `source.limit: 10`;
 *   - `CnWidgetObjectTable` fetches `source.limit + 1`, so ELEVEN rows, and
 *     hands CnDataTable `limit: 10`;
 *   - `CnDataTable` renders the footer only when `totalRowCount >
 *     effectiveRows.length`, where the total is the rows it HOLDS and the
 *     effective rows are the first `limit` of them.
 *
 * So the footer exists only when the fetch comes back with eleven rows, which
 * needs eleven open cases whose deadline is on or before today plus three days.
 * Hiding the control when nothing is hidden is correct, so the fixture is what
 * has to change.
 *
 * The instance does not supply them. `ci-seed.sh` deliberately SKIPS the demo
 * dataset (installing it would push demo rows into every list the suite
 * asserts on), so a comment further down claiming the demo caseload carries
 * more than ten overdue cases was describing a caseload that is not there: a
 * failing run's snapshot showed this tile holding TWO rows and the Overdue KPI
 * reading "1 action needed".
 *
 * ⚠️ EVERY FILLER SITS AT THE WINDOW'S FAR EDGE, deadline exactly today+3, and
 * that placement is the point rather than an accident. The tile orders by
 * deadline ascending, so fillers sort AFTER every case this spec names and
 * cannot displace `OVERDUE_CASE` or `SOON_CASE` from the rows that render.
 * Move them earlier and the scenarios above start failing on the fixture.
 */
const DEADLINE_FILLERS = 9

/**
 * The case type this spec owns resolves its deadline as `startDate` plus this
 * many days, so a start date is how a deadline gets aimed. Named because the
 * fillers compute a start date backwards from the window edge, and a literal
 * there would silently drift the moment the case type changed.
 */
const PROCESSING_DAYS = 7

/**
 * The account the My work scenarios run as.
 *
 * `my-work` is defined ENTIRELY by `@me`: its source filter is
 * `assignee: @me, isTerminalStatus: false`, ordered by `dueDate` ascending and
 * capped at ten rows. Run as the admin, that is a race this fixture cannot
 * win. The app's demo caseload assigns every task to `admin` as a literal, and
 * on a rig that has loaded it there are eighteen open ones, fifteen of them due
 * before tomorrow. A task seeded to fall due TOMORROW is therefore the
 * sixteenth row of a ten-row table and cannot appear, however correct the
 * widget is — a dashboard that shows your ten most urgent tasks is behaving
 * exactly as specified.
 *
 * Seeding earlier due dates would only win that same race against today's demo
 * data and lose it against a rig with a longer backlog, and it would cost the
 * assertion these scenarios exist for: the days-left column is asserted to read
 * `1 days remaining`, which a back-dated task does not render.
 *
 * A dedicated account removes the race rather than winning it. `@me` resolves
 * to somebody whose only open tasks are the three seeded below, so the table's
 * whole contents belong to this fixture and its row count can be asserted
 * exactly. The account is provisioned over the OCS provisioning API, holds no
 * admin rights, and needs no group and no grant — OpenRegister answers a plain
 * account's reads of the dossiq register, which is what makes this cheap.
 *
 * It is deliberately left in place afterwards: provisioning is idempotent, and
 * deleting an account whose session a browser context still holds is a flake
 * this suite does not need to invent.
 */
const WORK_USER = process.env.DOSSIQ_E2E_WORK_USER ?? 'e2e-dashboard'

/** Its password. Must satisfy the instance's password policy. */
const WORK_PASSWORD = process.env.DOSSIQ_E2E_WORK_PASSWORD ?? 'e2eDash!2026'

/** Where its captured session is written, under the gitignored `.auth/`. */
const WORK_STATE = storageStatePath(WORK_USER)

let api: APIRequestContext
let token = ''

/** The case type this spec owns: a seven-day deadline it can aim. */
let caseTypeId = ''
/** A status flagged final, so a case can be closed and still be past due. */
let finalStatusId = ''

/** Titles, so every assertion reads text this fixture wrote. */
const OVERDUE_CASE = `${RUN_PREFIX} deadline passed`
const SOON_CASE = `${RUN_PREFIX} deadline in two days`
const CLOSED_CASE = `${RUN_PREFIX} closed but past due`
const FAR_CASE = `${RUN_PREFIX} deadline far out`
const MET_CASE = `${RUN_PREFIX} closed on time this month`
/** One filler title per row, so a failure names the row it could not find. */
const FILLER_CASE = (n: number) => `${RUN_PREFIX} deadline filler ${n}`
/** The engine's own table. A flow task is not an OpenRegister object. */
const FLOW_TASKS_BASE = '/index.php/apps/openregister/api/flow-tasks'

/** Every task this file seeds, so teardown can cancel each one. */
const seededTaskUuids: string[] = []

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
		// The `test.setTimeout` above governs TESTS, not hooks: a hook gets the
		// config's flat 60s. This seed now also provisions an account and logs
		// it in through the web form, on top of the case type, two status types,
		// three cases, three tasks and two picker case types it already wrote,
		// and 60s is not enough for that on a loaded rig — it failed as a bare
		// `"beforeAll" hook timeout`, which names the hook rather than anything
		// inside it. Raised here, in the hook, exactly as `cleanupRunObjects`
		// raises the teardown's, so no TEST budget is loosened with it.
		test.setTimeout(180_000)

		// 🔴 THE ADMIN SESSION IS NAMED, NOT INHERITED. This used to build the
		// seeding context from `browser.newContext()`, which merges the
		// project's `use` and therefore resolves whatever the default storage
		// state happens to be at that moment. On one worker of run
		// 34262842972 it resolved to the NON-admin account this same hook
		// provisions, and `ensureUser` came back with
		//
		//     HTTP 403, OCS 403 Logged in account must be at least a sub admin
		//
		// which names a permission rather than a session and reads as a broken
		// provisioning API. The trace settles it: that request carried
		// `nc_username=e2e-dashboard`. Every test after it in this file then
		// reported "did not run", including all four Deadlines scenarios, so
		// one ambiguous session cost the file.
		//
		// Naming the admin state file removes the ambiguity. It does not
		// explain how the default came to resolve elsewhere, which is still
		// open, and that is exactly why the assertion below exists.
		api = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		token = await getRequestToken(api)

		// Prove the seeding session is the admin before it seeds anything, the
		// same way the WORK_USER session is proved below.
		const seedWhoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(
			seedWhoami.ok(),
			`the seeding session must answer whoami, got ${seedWhoami.status()}`,
		).toBeTruthy()
		expect(
			String((await seedWhoami.json())?.ocs?.data?.id ?? ''),
			'the seeding session must be the admin, or nothing below can seed',
		).toBe(process.env.ADMIN_USER ?? 'admin')

		// 🔴 PROVISIONING GETS ITS OWN, SESSION-FREE CONTEXT. Creating an
		// account through the captured admin session is password-confirmation
		// protected, and that confirmation expires thirty minutes after
		// `global-setup.ts` logged in. Across 101 runs on 2026-09-10 and 11
		// this file's first result landed 6.8 to 25.3 minutes into the run, so
		// it has not failed yet. It would the day the suite grows, a shard
		// reorders it or a runner is slow enough, and then as `OCS 403 Password
		// confirmation is required`, which names neither the session nor the
		// clock. `provisioningContext` sends basic
		// auth and no session cookie, so there is no confirmation to expire.
		// See it for the measurement on vth-inspection-result-authz.spec.ts.
		const provisioning = await provisioningContext(playwright, String(baseURL))
		try {
			// Prove the basic-auth context IS the admin before anything asks it
			// to provision. Without this a wrong or refused credential surfaces
			// as `ensureUser`'s "could not provision" error, which reads as a
			// broken provisioning API rather than a failed authentication.
			const provWhoami = await provisioning.get(
				'/ocs/v2.php/cloud/user?format=json',
			)
			expect(
				String(
					(await provWhoami.json().catch(() => ({})))?.ocs?.data?.id ?? '',
				),
				'the basic-auth provisioning context must resolve to the admin; got '
					+ `HTTP ${provWhoami.status()}`,
			).toBe(process.env.ADMIN_USER ?? 'admin')

			// The account the My work scenarios run as. See `WORK_USER` for why
			// they cannot run as the admin.
			await ensureUser(provisioning, '', WORK_USER, WORK_PASSWORD)
		} finally {
			await provisioning.dispose()
		}

		// And its session.
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: WORK_USER,
			password: WORK_PASSWORD,
			statePath: WORK_STATE,
		})

		// Prove the captured session really is that account, before a single
		// task is seeded against it. A capture that silently fell back to the
		// admin would put the seeded tasks back behind the demo caseload, and
		// the two My work scenarios would then fail naming a widget rather than
		// a session.
		//
		// The OCS endpoints are CSRF-guarded, so the `OCS-APIRequest` header is
		// what marks this as an API call. Without it Nextcloud answers a plain
		// OCS GET with 412, which reads as "no session" rather than as a
		// missing header.
		const workApi = await playwright.request.newContext({
			baseURL,
			storageState: WORK_STATE,
		})
		const whoami = await workApi.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		expect(
			String((await whoami.json())?.ocs?.data?.id ?? ''),
			'the captured session must resolve to the dedicated account',
		).toBe(WORK_USER)
		await workApi.dispose()

		// `deadline` is READ-ONLY on the case: OpenRegister computes it as
		// startDate + the case type's processingDeadline. So the deadline is
		// aimed by owning the case type and moving the start date, never by
		// writing `deadline` directly.
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Deadlines`,
			identifier: `${RUN_PREFIX.toLowerCase()}-deadlines`,
			description: 'Throwaway caseType for the dashboard-tiles e2e layer.',
			processingDeadline: `P${PROCESSING_DAYS}D`,
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

		// Open, and far outside the Deadlines window: the case that proves the
		// View all landed on a FILTERED list rather than on all open cases.
		await seedCase(api, token, {
			title: FAR_CASE,
			caseType: caseTypeId,
			status: objectId(open),
			startDate: isoDay(30),
		})

		// 🔴 THE SLA TILE HAS NO DENOMINATOR WITHOUT THIS. `kpi-sla-compliance`
		// reads "the share of THIS MONTH's closed cases whose endDate met their
		// deadline", and a share over an empty set is not a number: the tile
		// renders an em dash, correctly. Nothing else in this fixture closes a
		// case this month with an endDate, so the tile had nothing to divide by
		// and `every KPI tile shows a number` failed on "—" while the widget
		// was behaving exactly as specified.
		//
		// Closed TODAY so the month is never in doubt: an endDate a few days
		// back falls into the previous month whenever the suite runs in the
		// first days of one, which is a fixture that passes for most of the
		// month and fails silently at the turn. The deadline lands two days
		// out, so this case MET it and the share is a real percentage.
		await seedCase(api, token, {
			title: MET_CASE,
			caseType: caseTypeId,
			status: finalStatusId,
			startDate: isoDay(-5),
			endDate: isoDay(0),
		})

		// The fillers that truncate the tile. See `DEADLINE_FILLERS` for why
		// the footer cannot appear without them, and why the deadline is
		// exactly today+3 rather than anywhere else inside the window.
		for (let n = 1; n <= DEADLINE_FILLERS; n++) {
			await seedCase(api, token, {
				title: FILLER_CASE(n),
				caseType: caseTypeId,
				status: objectId(open),
				startDate: isoDay(3 - PROCESSING_DAYS),
			})
		}

		const onCase = objectId(overdue)
		for (const [title, due] of [
			[TASK_SOON, isoDay(1)],
			[TASK_MID, isoDay(7)],
			[TASK_LATE, isoDay(30)],
		]) {
			// IN THE ENGINE, not as a `caseTask` object. remove-casetask 2.3
			// moved the tile onto `/api/flow-tasks`, and a fixture writing the
			// old store would put rows in a table nothing reads: the tile then
			// shows nothing and the three tests below time out waiting for a
			// title that was never going to arrive. Three field names change
			// with the table, `case` to `objectUuid`, `status` to `state` and
			// `dueDate` to `dueAt`.
			//
			// Assigned to the dedicated account, not to whoever seeded them:
			// the engine scopes `scope: assigned` to the session, and the whole
			// point of that account is that nothing else is on it.
			const res = await api.post(FLOW_TASKS_BASE, {
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: {
					title,
					objectUuid: onCase,
					assignee: WORK_USER,
					state: 'available',
					dueAt: `${due}T09:00:00+00:00`,
					appId: 'dossiq',
				},
			})
			expect(
				res.status(),
				`seed task "${title}" -> ${res.status()} ${await res.text()}`,
			).toBe(201)
			const created = await res.json()
			expect(created.uuid, `seeded task "${title}" has no uuid`).toBeTruthy()
			seededTaskUuids.push(String(created.uuid))
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
		//
		// One verb at a time, because an engine task is NOT an OpenRegister
		// object: `cleanupRunObjects` cannot see it however the prefix is
		// spelled, and the engine publishes no delete. `cancel` is its only
		// removal verb and it terminates rather than erases. Failures are
		// swallowed: a task already terminated answers 409 to a second cancel,
		// and a teardown that throws on that reddens a run whose assertions
		// all passed.
		for (const uuid of seededTaskUuids) {
			try {
				await api.post(`${FLOW_TASKS_BASE}/${uuid}/cancel`, {
					headers: {
						requesttoken: token,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					data: {},
				})
			} catch {
				// Best effort; the next run's residue sweep is the backstop.
			}
		}
		await api.dispose()
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-fresh-session-lands-on-the-dashboard
	test('every KPI tile shows a number on the first load of a session', async ({
		page,
	}) => {
		await openDashboard(page)
		const tiles = page.locator('.cn-stat-widget')
		await expect(tiles.first()).toBeVisible({ timeout: 30_000 })
		await expect(tiles).toHaveCount(KPI_TILE_COUNT)
		await expect(page.getByText('Widget not available')).toHaveCount(0)
		// Name the tile in the failure. Addressed positionally, the message
		// reads `.cn-stat-widget nth(4)` and says nothing about WHICH tile or
		// why: the one that failed here was SLA Compliance rendering "—",
		// which is correct for a share over an empty set and meant the fixture
		// owed it a closed case rather than the widget owing a number. See
		// `MET_CASE`.
		for (const tile of await tiles.all()) {
			const label = (await tile.textContent())?.trim().slice(0, 60) ?? '?'
			await expect(
				tile.locator('.cn-kpi-card__value'),
				`the KPI tile "${label}" should show a number, not a placeholder`,
			).toHaveText(/\d/, { timeout: 15_000 })
		}
	})

	/**
	 * The My work scenarios, on the account whose only open tasks are the
	 * seeded three.
	 *
	 * `test.use` here rather than on the file: the four scenarios outside this
	 * block read `deadlines` and the New case picker, which are instance-wide
	 * and want the ordinary admin session and the caseload that comes with it.
	 * Only `my-work` is scoped by `@me`, so only `my-work` needs its own
	 * account. The outer `beforeAll` is unaffected and still seeds as the
	 * admin.
	 */
	test.describe('on the account that owns them', () => {
		test.use({ storageState: WORK_STATE })

		// @e2e openspec/specs/dashboard/spec.md#scenario-your-tasks-appear-once-with-days-left
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

			// And nothing else, which is the stronger form of the same claim and
			// is only assertable because this account's queue is the fixture's.
			// A table that double-counted would read six rows here, and a row
			// that is neither of the three is residue an earlier run left on this
			// account — a finding, not noise.
			await expect(
				rows(table),
				'My work holds the three seeded tasks and nothing else',
			).toHaveCount(3)

			// The task due tomorrow reads its distance, not its date. Which phrase
			// depends on the clock: the seed is 09:00 UTC tomorrow, so a run late
			// in the day on a machine ahead of UTC can legitimately read "today".
			const soon = rows(table).filter({ hasText: TASK_SOON })
			await expect(soon).toContainText(/1 days remaining|Due today/)

			// No uuid anywhere in the row. The tile used to carry a Case
			// column, and the assertion here was that it named the case rather
			// than printing its 36-character uuid. remove-casetask 2.3 dropped
			// that column: the engine answers the case in a `subject` block
			// that resolves to null on every row today, so the column would be
			// blank on all of them. The uuid pattern stays, because the Task
			// column reading a raw identifier is the same failure in a
			// different cell, and the case name comes back with the column.
			await expect(soon).not.toContainText(
				/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
			)
		})

		// @e2e openspec/specs/dashboard/spec.md#scenario-you-complete-a-task-from-the-row
		test('a My work row opens the task, which is where Pick up and Complete are', async ({
			page,
		}) => {
			// Row actions are blocked on nextcloud-vue, so the declared interim
			// is the row route. This test holds the interim, so the day a row
			// action lands and the route is dropped, it says so. It also holds
			// the row's IDENTITY: the engine's `uuid` is what `/tasks/:id`
			// accepts, and its numeric `id`, which sits beside it in the same
			// response, is not.
			await openDashboard(page)
			const table = widget(page, 'my-work')
			await expect(table).toBeVisible({ timeout: 30_000 })
			await rows(table).filter({ hasText: TASK_SOON }).first().click()
			await expect(page).toHaveURL(/\/tasks\/[^/]+$/, { timeout: 15_000 })
		})
	})

	// @e2e openspec/specs/signalering-widgets/spec.md#scenario-overdue-and-near-deadline-cases-share-one-table
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
	// @e2e openspec/specs/signalering-widgets/spec.md
	// @e2e openspec/specs/dashboard/spec.md#scenario-dash-004c-overdue-panel-with-view-all-link
	test('View all on Deadlines opens the Cases list already filtered', async ({
		page,
	}) => {
		await openDashboard(page)
		const table = widget(page, 'deadlines')
		await expect(table).toBeVisible({ timeout: 30_000 })

		// The footer only exists because this spec seeded the window full.
		// Say so here, so a fixture that stopped truncating the tile fails
		// naming the row count rather than naming a missing control.
		await expect(
			rows(table),
			'the Deadlines tile must be truncated, or it offers no View all',
		).toHaveCount(ROW_LIMIT)

		// CnDataTable renders View all as an anchor with no href, so it carries
		// no link role; match it by its text in either language.
		const viewAll = table.getByText(/View all|Alles bekijken/, { exact: true })
		await expect(viewAll).toBeVisible({ timeout: 15_000 })
		await viewAll.click()

		await expect(page).toHaveURL(/\/cases\?/, { timeout: 15_000 })
		const query = new URL(page.url()).searchParams
		// The KEY is pinned and the VALUE is not, deliberately. The manifest
		// writes this window as a token, and the host may navigate with the
		// token or with the date it resolves to; both name the same day, so
		// pinning one spelling fails against the other while saying nothing
		// about the filter the reader lands on. What these assertions are
		// about is the KEY: a tile that counts one set of cases and a View all
		// that lands on another is the dropped filter they exist to catch.
		//
		// `dateTokenPattern` is the shared helper for exactly this, adopted
		// here rather than kept as a local copy, so the two spellings are
		// spelled out in one place.
		expect(query.get('deadline[lte]')).toMatch(dateTokenPattern('@today+3d'))
		expect(query.get('isFinalStatus')).toBe('false')

		// The query arriving is not the same as the LIST honouring it, and it
		// is the list the reader sees. `case-list-lenses` and `pages` both used
		// to assert this control separately, against a tile that could not
		// offer it because neither of them filled its window. Three copies of
		// one scenario, all red for the same reason. It belongs here, in the
		// one spec whose fixture owns the window, and their annotations came
		// with it.
		//
		// Absence is what catches a dropped filter, so the list is required to
		// have rendered a row FIRST: two `toHaveCount(0)` against an empty
		// table would pass while proving nothing. The rows are deliberately not
		// counted exactly, because other specs seed into this instance in
		// parallel and the list is paginated.
		//
		// The chip does NOT light up, and that is expected rather than a
		// defect: CnIndexPage activates only the quick filter marked `default`,
		// so the reader lands on All with the query applied. Naming a chip from
		// a query is a nextcloud-vue change.
		const list = page.getByRole('table')
		await expect(list).toBeVisible({ timeout: 30_000 })
		await expect(
			list.locator('[data-testid="cn-object-row"]').first(),
			'the filtered list must have rendered before absence proves anything',
		).toBeVisible({ timeout: 30_000 })
		await expect(
			list.getByText(CLOSED_CASE, { exact: true }),
			'a closed case is excluded by isFinalStatus',
		).toHaveCount(0)
		await expect(
			list.getByText(FAR_CASE, { exact: true }),
			'an open case due long after the window is excluded by the deadline filter',
		).toHaveCount(0)
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-draft-case-types-are-absent
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
