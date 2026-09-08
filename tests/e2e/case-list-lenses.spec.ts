/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lenses, deadlines and bulk actions on the case list (one-case-list).
 *
 * Covers the browser scenarios of five deltas: the chips on Cases and Tasks,
 * the countdown Deadline column, the Overdue tile's View all, and the four
 * bulk actions with their required reason.
 *
 * THREE THINGS ABOUT THIS FILE CHANGE WHAT AN ASSERTION HERE CAN CLAIM.
 *
 * ONE. THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR
 * COUNT. The Cases index is a shared list on a shared instance, and another
 * session's fixtures land in it while this one runs. "The list shows N rows"
 * is therefore never asserted; "the list holds this seeded case and not that
 * one" is, and that is what every lens scenario actually claims.
 *
 * TWO. `case.deadline` IS NOT WRITABLE. It is `readOnly` and materialised by
 * OpenRegister from `startDate + caseType.processingDeadline`. So a case is
 * not seeded with a deadline; it is seeded with a `startDate` that PUTS the
 * deadline where the scenario needs it, and `beforeAll` reads the computed
 * value back and asserts it landed. Without that read-back a countdown
 * assertion would fail with "expected 3 days left, got —" and point at the
 * cell rather than at the calculation that never ran.
 *
 * THREE. THE LABELS ARE MATCHED IN BOTH LANGUAGES. CI runs English and this
 * instance may be Dutch; a spec that asserts English chip labels fails on a
 * correct Dutch nav. Chips are addressed by `role="tab"` with a bilingual
 * name, and their active state by `aria-selected`, which is language-free.
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
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
const TASKS_URL = `${APP_URL}tasks`
const QUEUE_URL = `${APP_URL}queue`

/** The signed-in user every "Mine" lens is about. */
const ME = process.env.ADMIN_USER ?? 'admin'
/** Somebody else, who must never show up under Mine. */
const OTHER = 'someone-else'

/** The case type's processing deadline, so `startDate` places the deadline. */
const PROCESSING_DAYS = 10

let api: APIRequestContext
let token: string
let caseTypeId = ''
let statusReceived = ''
let statusDone = ''

const cases: Record<string, string> = {}
const tasks: Record<string, string> = {}

/**
 * A `YYYY-MM-DD` date `offset` days from today, in local time.
 *
 * @param offset Days ahead; negative for the past.
 */
function day(offset: number): string {
	const now = new Date()
	const d = new Date(now.getFullYear(), now.getMonth(), now.getDate() + offset)
	const month = String(d.getMonth() + 1).padStart(2, '0')
	const date = String(d.getDate()).padStart(2, '0')
	return `${d.getFullYear()}-${month}-${date}`
}

/**
 * The `startDate` that makes a case's computed deadline land `dueIn` days
 * from today: the deadline is `startDate + processingDeadline`.
 *
 * @param dueIn Days from today the deadline should fall on.
 */
function startDateForDeadlineIn(dueIn: number): string {
	return day(dueIn - PROCESSING_DAYS)
}

/**
 * Seed one case of the throwaway case type.
 *
 * @param key    The key this spec refers to the case by.
 * @param fields The case's own fields.
 */
async function seed(key: string, fields: Record<string, unknown>): Promise<string> {
	const row = await seedCase(api, token, {
		title: `${RUN_PREFIX} ${key}`,
		caseType: caseTypeId,
		status: statusReceived,
		...fields,
	})
	cases[key] = objectId(row)
	return cases[key]
}

/**
 * Open a page and clear whatever is masking it.
 *
 * `CnAppRoot` mounts a support dialog, and on a fresh browser profile the
 * first-time-setup wizard as well; both put a modal mask over the app that
 * swallows every click. This spec is almost entirely clicks, so every
 * navigation goes through here rather than through a bare `page.goto`.
 *
 * @param page The page.
 * @param url  Where to go.
 */
async function visit(page: Page, url: string): Promise<void> {
	await page.goto(url)
	await dismissSupportDialog(page)
}

/**
 * The Cases table, once it has rendered.
 *
 * @param page The page.
 */
async function casesTable(page: Page): Promise<Locator> {
	const table = page.getByRole('table')
	await expect(table).toBeVisible({ timeout: 30_000 })
	return table
}

/**
 * One quick-filter chip, by its English or Dutch label.
 *
 * @param page  The page.
 * @param label A pattern matching the chip's label in either language.
 */
function chip(page: Page, label: RegExp): Locator {
	return page.getByRole('tab', { name: label })
}

const CHIPS = {
	all: /^(All|Alle)$/,
	mine: /^(Mine|Van mij)$/,
	unclaimed: /^(Unclaimed|Niet toegewezen)$/,
	closed: /^(Closed|Gesloten)$/,
	overdue: /^(Overdue|Verlopen)$/,
}

/**
 * The row for one seeded object, addressed by its run-prefixed title.
 *
 * @param page The page.
 * @param key  The key the object was seeded under.
 */
function row(page: Page, key: string): Locator {
	return page.getByRole('row').filter({ hasText: `${RUN_PREFIX} ${key}` })
}

/**
 * Wait for the list to have settled on a lens: the row that must be there is
 * there. Asserting an ABSENCE first would pass against a list that has not
 * fetched yet, which is the way a lens test silently stops testing anything.
 *
 * @param page    The page.
 * @param present The key of a row the active lens must show.
 */
async function listSettled(page: Page, present: string): Promise<void> {
	await expect(row(page, present).first()).toBeVisible({ timeout: 30_000 })
}

test.describe('Lenses, deadlines and bulk actions on the case list', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		caseTypeId = machine.caseTypeId
		statusReceived = machine.statusReceived
		statusDone = machine.statusDone

		// The case type carries the rules the lifecycle gestures ask it for,
		// and the processing deadline the countdown column counts against.
		await updateObject(api, token, 'caseType', caseTypeId, {
			processingDeadline: `P${PROCESSING_DAYS}D`,
			suspensionAllowed: true,
			extensionAllowed: true,
			extensionPeriod: 'P14D',
		})

		// One case per shape the lenses have to tell apart.
		await seed('mine-open', {
			assignee: ME,
			startDate: startDateForDeadlineIn(3),
		})
		await seed('other-open', {
			assignee: OTHER,
			startDate: startDateForDeadlineIn(30),
		})
		await seed('unclaimed-open', { startDate: startDateForDeadlineIn(30) })
		await seed('mine-closed', {
			assignee: ME,
			status: statusDone,
			startDate: startDateForDeadlineIn(30),
		})
		await seed('mine-overdue', {
			assignee: ME,
			startDate: startDateForDeadlineIn(-2),
		})
		await seed('closed-overdue', {
			assignee: ME,
			status: statusDone,
			startDate: startDateForDeadlineIn(-5),
		})
		await seed('mine-far', {
			assignee: ME,
			startDate: startDateForDeadlineIn(30),
		})
		// Two cases in the same starting status, for the bulk transition.
		await seed('bulk-a', { assignee: ME, startDate: startDateForDeadlineIn(20) })
		await seed('bulk-b', { assignee: ME, startDate: startDateForDeadlineIn(20) })
		await seed('suspend-me', {
			assignee: ME,
			startDate: startDateForDeadlineIn(20),
		})
		await seed('extend-me', {
			assignee: ME,
			startDate: startDateForDeadlineIn(5),
		})

		// The deadline is COMPUTED. Read it back before any scenario counts
		// days against it: an empty value here means the calculation did not
		// run on this instance, and every countdown assertion below would
		// otherwise fail pointing at the cell rather than at the cause.
		const dueSoon = await showObject(api, 'case', cases['mine-open'])
		expect(
			String(dueSoon.deadline ?? ''),
			'case.deadline is materialised from startDate + caseType.processingDeadline; '
				+ 'an empty value means that calculation did not run on this instance',
		).toBe(day(3))

		const overdue = await showObject(api, 'case', cases['mine-overdue'])
		expect(String(overdue.deadline ?? '')).toBe(day(-2))

		// Four tasks, for the Tasks index's three chips.
		for (const [key, fields] of Object.entries({
			'task-mine': { assignee: ME, status: 'active' },
			'task-other': { assignee: OTHER, status: 'active' },
			'task-unclaimed': { status: 'active' },
			'task-done': { status: 'completed' },
		})) {
			const created = await createObject(api, token, 'caseTask', {
				title: `${RUN_PREFIX} ${key}`,
				case: cases['mine-open'],
				...fields,
			})
			tasks[key] = objectId(created)
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// ---------------------------------------------------------------------
	// my-work — Lenses on the Cases index
	// ---------------------------------------------------------------------

	// @e2e openspec/changes/one-case-list/specs/my-work/spec.md
	test('All is the lens you land on, and Mine is one click away', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		// The landing lens is All, not Mine. A default that narrowed to the
		// signed-in user would make an empty result read as an empty
		// register rather than as a filter (decision D-default, revised).
		await expect(chip(page, CHIPS.all)).toHaveAttribute('aria-selected', 'true')
		await listSettled(page, 'other-open')
		await expect(row(page, 'mine-open').first()).toBeVisible()

		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-open')
		await expect(row(page, 'other-open')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/my-work/spec.md
	test('Unclaimed shows what nobody has picked up, and the Queue agrees', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.unclaimed).click()
		await listSettled(page, 'unclaimed-open')
		await expect(row(page, 'mine-open')).toHaveCount(0)

		// The Queue page's base filter is the same two conditions, so the two
		// lists cannot disagree. This is the browser half of the manifest
		// vitest's deep-equal.
		await visit(page, QUEUE_URL)
		await casesTable(page)
		await listSettled(page, 'unclaimed-open')
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/my-work/spec.md
	test("All shows the other person's case and the closed one", async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.all).click()
		await listSettled(page, 'other-open')
		await expect(row(page, 'mine-closed').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e openspec/changes/one-case-list/specs/my-work/spec.md
	test('choosing a chip replaces the previous one rather than stacking', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.unclaimed).click()
		await listSettled(page, 'unclaimed-open')

		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-open')

		// Exactly one chip is active, and it is Mine.
		await expect(chip(page, CHIPS.mine)).toHaveAttribute('aria-selected', 'true')
		await expect(
			page.getByRole('tab').and(page.locator('[aria-selected="true"]')),
		).toHaveCount(1)
		// And the unassigned case is gone, so the two filters did not stack
		// into "mine OR unclaimed".
		await expect(row(page, 'unclaimed-open')).toHaveCount(0)
	})

	// ---------------------------------------------------------------------
	// case-management — open work by default, closed work on request
	// ---------------------------------------------------------------------

	// @e2e openspec/changes/one-case-list/specs/case-management/spec.md
	test('a closed case leaves Mine', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-open')
		await expect(row(page, 'mine-closed')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/case-management/spec.md
	test('Closed shows the closed case and not the open one', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.closed).click()
		await listSettled(page, 'mine-closed')
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// ---------------------------------------------------------------------
	// signalering-widgets — the countdown Deadline column
	// ---------------------------------------------------------------------

	// @e2e openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	test('the Deadline cell counts the days left', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-open')

		const cell = row(page, 'mine-open')
			.first()
			.locator('[data-testid="deadline-countdown"]')
		await expect(cell).toHaveText(/3 (days left|dagen)/, { timeout: 20_000 })
		await expect(cell).not.toHaveClass(/is-overdue/)
	})

	// @e2e openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	test('a past deadline reads as overdue, and carries the overdue class', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-overdue')

		const cell = row(page, 'mine-overdue')
			.first()
			.locator('[data-testid="deadline-countdown"]')
		await expect(cell).toHaveText(/2 (days overdue|dagen)/, { timeout: 20_000 })
		// The class is what colours the cell. Asserting the text alone would
		// pass against a list that had quietly lost its only visual alarm.
		await expect(cell).toHaveClass(/is-overdue/)
	})

	// @e2e openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	test('Overdue shows the open overdue case only', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.overdue).click()
		await listSettled(page, 'mine-overdue')
		await expect(row(page, 'closed-overdue')).toHaveCount(0)
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	test('the Overdue tile View all carries its filter to the list', async ({
		page,
	}) => {
		await visit(page, APP_URL)

		// The same locator `pages.spec.ts` uses: the widget wrapper carries no
		// id attribute, and CnDataTable renders View all as an anchor with no
		// href, so it has no link role to address it by.
		const tile = page.locator('.cn-widget-wrapper').filter({
			has: page.getByRole('heading', { name: /^(Overdue|Verlopen)$/ }),
		})
		await expect(tile).toBeVisible({ timeout: 30_000 })
		const viewAll = tile.getByText(/View all|Alles bekijken/, { exact: true })
		await expect(viewAll).toBeVisible({ timeout: 15_000 })
		await viewAll.click()

		await casesTable(page)
		// The filter survives the trip — that is the defect (triage item 4).
		// The chip does NOT light up: CnIndexPage activates only the chip
		// marked `default`, so the reader lands on All with the tile's query
		// applied, and naming a chip from a query is a nextcloud-vue change.
		await expect(page).toHaveURL(/\/cases\?/, { timeout: 15_000 })
		const query = new URL(page.url()).searchParams
		expect(query.get('deadline[lt]')).toBe('@today')
		expect(query.get('isFinalStatus')).toBe('false')
		await listSettled(page, 'mine-overdue')
		await expect(row(page, 'closed-overdue')).toHaveCount(0)
		await expect(row(page, 'mine-far')).toHaveCount(0)
	})

	// ---------------------------------------------------------------------
	// task-management — the same three chips on Tasks
	// ---------------------------------------------------------------------

	// @e2e openspec/changes/one-case-list/specs/task-management/spec.md
	test('the Tasks index lands on All and offers Mine', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await expect(chip(page, CHIPS.all)).toHaveAttribute('aria-selected', 'true')
		await listSettled(page, 'task-other')

		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'task-mine')
		await expect(row(page, 'task-other')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/task-management/spec.md
	test('Unclaimed on Tasks shows the open task nobody holds', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.unclaimed).click()
		await listSettled(page, 'task-unclaimed')
		await expect(row(page, 'task-done')).toHaveCount(0)
		await expect(row(page, 'task-mine')).toHaveCount(0)
	})

	// @e2e openspec/changes/one-case-list/specs/task-management/spec.md
	test('All on Tasks shows the completed task too', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.all).click()
		await listSettled(page, 'task-other')
		await expect(row(page, 'task-done').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// ---------------------------------------------------------------------
	// case-bulk-status-transition — the four bulk actions
	// ---------------------------------------------------------------------

	/**
	 * Select the given seeded cases on the Cases page and open one bulk
	 * action's dialog.
	 *
	 * @param page     The page.
	 * @param keys     The keys of the cases to tick.
	 * @param actionId The bulk action's manifest id.
	 */
	async function openBulkAction(
		page: Page,
		keys: string[],
		actionId: string,
	): Promise<Locator> {
		await visit(page, CASES_URL)
		await casesTable(page)
		await chip(page, CHIPS.mine).click()
		await listSettled(page, keys[0])

		for (const key of keys) {
			await row(page, key).first().getByRole('checkbox').check()
		}

		const strip = page.locator('[data-testid="cn-selection-strip"]')
		await expect(strip).toBeVisible({ timeout: 15_000 })
		await strip.locator(`[data-testid="cn-bulk-action-${actionId}"]`).click()

		const dialog = page.locator('[data-testid="bulk-dialog"]')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		return dialog
	}

	// @e2e openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	test('Transition moves the selection, with a reason', async ({ page }) => {
		const dialog = await openBulkAction(page, ['bulk-a', 'bulk-b'], 'transition')

		await dialog.getByRole('combobox').first().click()
		await page
			.getByRole('option', { name: /Start behandeling/ })
			.first()
			.click()
		await dialog
			.locator('[data-testid="bulk-reason"] textarea')
			.fill('Quarterly clean-up')

		const execute = dialog.locator('[data-testid="bulk-execute"]')
		await expect(execute).toBeEnabled({ timeout: 20_000 })
		await execute.click()

		await expect(
			dialog.locator('[data-testid="bulk-execute-summary"]'),
		).toContainText(/2 of 2|2 van 2/, { timeout: 30_000 })

		// The engine ran for both cases, not just the dialog's counter.
		for (const key of ['bulk-a', 'bulk-b']) {
			const saved = await showObject(api, 'case', cases[key])
			expect(saved.status, `${key} moved`).not.toBe(statusReceived)
		}
	})

	// @e2e openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	test('no reason, no execute', async ({ page }) => {
		const dialog = await openBulkAction(page, ['suspend-me'], 'suspend')

		// The preview has come back and the case is ready; the only thing
		// missing is the reason, and that alone keeps Execute disabled.
		await expect(
			dialog.locator('[data-testid="bulk-preview-summary"]'),
		).toBeVisible({ timeout: 30_000 })
		await expect(dialog.locator('[data-testid="bulk-execute"]')).toBeDisabled()

		await dialog
			.locator('[data-testid="bulk-reason"] textarea')
			.fill('Awaiting documents')
		await expect(dialog.locator('[data-testid="bulk-execute"]')).toBeEnabled()
	})

	// @e2e openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	test('Suspend then Resume, each with its reason', async ({ page }) => {
		const suspend = await openBulkAction(page, ['suspend-me'], 'suspend')
		await suspend
			.locator('[data-testid="bulk-reason"] textarea')
			.fill('Awaiting documents')
		await suspend.locator('[data-testid="bulk-execute"]').click()
		await expect(
			suspend.locator('[data-testid="bulk-execute-summary"]'),
		).toContainText(/1 of 1|1 van 1/, { timeout: 30_000 })

		// The case itself records the suspension, with the reason: the
		// journal on the case is what the case page reads to say it is
		// suspended, and a dialog counter alone proves nothing about it.
		await expect
			.poll(
				async () => {
					const saved = await showObject(api, 'case', cases['suspend-me'])
					return JSON.stringify(saved.activity ?? '')
				},
				{
					timeout: 30_000,
					message: 'the suspension reaches the case journal',
				},
			)
			.toContain('Awaiting documents')

		const resume = await openBulkAction(page, ['suspend-me'], 'resume')
		await resume
			.locator('[data-testid="bulk-reason"] textarea')
			.fill('Documents received')
		await resume.locator('[data-testid="bulk-execute"]').click()
		await expect(
			resume.locator('[data-testid="bulk-execute-summary"]'),
		).toContainText(/1 of 1|1 van 1/, { timeout: 30_000 })

		await expect
			.poll(
				async () => {
					const saved = await showObject(api, 'case', cases['suspend-me'])
					return JSON.stringify(saved.activity ?? '')
				},
				{ timeout: 30_000, message: 'the resume reaches the case journal' },
			)
			.toContain('Documents received')
	})

	// @e2e openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	test('Extend term writes the new end date, and leaves the Deadline column alone', async ({
		page,
	}) => {
		const before = await showObject(api, 'case', cases['extend-me'])
		const dialog = await openBulkAction(page, ['extend-me'], 'extend-term')

		await dialog.locator('[data-testid="bulk-new-deadline"]').fill(day(20))
		await dialog
			.locator('[data-testid="bulk-reason"] textarea')
			.fill('Complex case')
		await dialog.locator('[data-testid="bulk-execute"]').click()

		await expect(
			dialog.locator('[data-testid="bulk-execute-summary"]'),
		).toContainText(/1 of 1|1 van 1/, { timeout: 30_000 })

		await expect
			.poll(
				async () => {
					const saved = await showObject(api, 'case', cases['extend-me'])
					return String(saved.plannedEndDate ?? '')
				},
				{
					timeout: 30_000,
					message: 'the extension writes the planned end date',
				},
			)
			.toBe(day(20))

		// And the Deadline column does NOT move: `case.deadline` is readOnly
		// and recomputed from startDate + the case type's processingDeadline
		// on every save, so nothing outside that calculation can write it.
		// This is asserted rather than left implied, because a reader who
		// expects the column to follow the extension is expecting the wrong
		// thing and should find out here.
		const after = await showObject(api, 'case', cases['extend-me'])
		expect(String(after.deadline ?? '')).toBe(String(before.deadline ?? ''))
	})

	// @e2e openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	test('the Cases list offers all five bulk actions on a selection', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-far')
		await row(page, 'mine-far').first().getByRole('checkbox').check()

		const strip = page.locator('[data-testid="cn-selection-strip"]')
		await expect(strip).toBeVisible({ timeout: 15_000 })
		for (const id of [
			'reassign',
			'transition',
			'suspend',
			'resume',
			'extend-term',
		]) {
			await expect(
				strip.locator(`[data-testid="cn-bulk-action-${id}"]`),
				`the strip offers ${id}`,
			).toBeVisible()
		}
	})

	// @e2e openspec/changes/one-case-list/specs/case-management/spec.md
	test('the deadline query narrows the list, which is what the sidebar filter would drive', async () => {
		// The Deadline before SIDEBAR filter is blocked: nextcloud-vue 2.41's
		// index sidebar derives its filters from schema `facetable`
		// properties as value lists, with no date input and no operator. The
		// QUERY the filter would send is reachable and is what the Overdue
		// tiles use, so the narrowing itself is asserted here through the
		// same path rather than left untested until the control exists.
		const narrowed = await listObjects(api, 'case', {
			'deadline[lt]': day(10),
			isFinalStatus: 'false',
		})
		const titles = narrowed.map((c: any) => String(c.title ?? ''))

		expect(
			titles.some((t) => t.includes(`${RUN_PREFIX} mine-open`)),
			'the case due in 3 days is inside the window',
		).toBe(true)
		expect(
			titles.some((t) => t.includes(`${RUN_PREFIX} mine-far`)),
			'the case due in 30 days is not',
		).toBe(false)
	})
})
