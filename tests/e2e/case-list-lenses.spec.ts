/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lenses, deadlines and bulk actions on the case list (one-case-list).
 *
 * Covers the browser scenarios of five deltas: the chips on Cases and Tasks,
 * the countdown Deadline column, the Overdue tile's View all, and the four
 * bulk actions with their required reason. `lists-that-answer` added the
 * Closed, Overdue and Due this week chips on Tasks, the Due this week chip
 * on Cases, and the priority column the task list was always specified to
 * carry.
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
	cleanupFlowTasks,
	cleanupRunObjects,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import {
	dateTokenPattern,
	dismissSupportDialog,
	PAGE_LOAD,
	tickCheckbox,
} from './helpers/nav.ts'

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
/** The seeded caseType's exact title, read back from the fixture. */
let caseTypeTitle = ''
let statusReceived = ''
let statusDone = ''

const cases: Record<string, string> = {}
/** Engine task uuids, by the key this spec refers to them by. */
const tasks: Record<string, string> = {}
/**
 * Whether the instance's OpenRegister answers the `dueAfter`/`dueBefore`
 * inbox predicates. Probed in `beforeAll` against the live endpoint rather
 * than read off a version string, so the window scenarios start running by
 * themselves the moment the instance gains them.
 */
let dueWindowSupported = false

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
 * An ISO instant `offset` days from today at `hour`:`minute` LOCAL time.
 *
 * 🔴 THE ENGINE COMPARES INSTANTS, NOT DATE STRINGS. `caseTask.dueDate` was
 * matched against `@today` lexically, so `2026-09-10T09:00` sorted after the
 * bare date `2026-09-10` and a task due at nine this morning counted as due
 * TODAY all day long. OpenRegister's engine derives `overdue` as
 * `dueAt < now` on real instants (`TaskTemporalProjection`), so that same
 * nine-o'clock task is genuinely late from 09:01. Every dated fixture below
 * therefore states the time it means.
 *
 * @param offset Days ahead; negative for the past.
 * @param hour   Local hour of day.
 * @param minute Local minute.
 */
function instant(offset: number, hour: number, minute = 0): string {
	const now = new Date()
	return new Date(
		now.getFullYear(),
		now.getMonth(),
		now.getDate() + offset,
		hour,
		minute,
		0,
		0,
	).toISOString()
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
	await page.goto(url, PAGE_LOAD)
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
	dueThisWeek: /^(Due this week|Deze week te doen)$/,
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
 * The index sidebar offers one filter button per case type, so this run's own
 * type is a control that narrows the list to exactly the rows it seeded.
 *
 * 🔴 THE TITLE IS READ BACK FROM THE FIXTURE, NEVER REBUILT FROM `RUN_PREFIX`.
 * `seedStateMachine` appends a per-call suffix (`RUN_PREFIX` is per-process, so
 * a second call in the same worker would otherwise collide), which means the
 * stored title is `<prefix> Vergunning 1`, `… 2`, and so on. A locator built
 * from the prefix alone matched EVERY machine this worker had seeded, and a
 * second spec file sharing the worker was enough to make it two — a strict-mode
 * violation that reads as "the sidebar should offer a case-type filter named …"
 * while two perfectly good buttons sit in the snapshot. See `caseTypeTitle`.
 */

/**
 * Narrow the Cases index to the rows this run seeded.
 *
 * 🔴 WITHOUT THIS THE LENS TESTS LOOK AT PAGE 1 OF 4. The index paginates at
 * 20 and the shared instance holds far more: a failing run's page snapshot
 * read "Showing 20 of 62", "Page 1 of 4", ordered by identifier ascending, so
 * rows 2026-0001 upward. A case seeded seconds ago gets a HIGH number and
 * lands on the last page, and the assertion reads as "the lens does not show
 * my row" when the lens is fine and the row is three pages away.
 *
 * That is also why the Mine lenses passed while All, Unclaimed and Other
 * failed. Mine narrows to the signed-in user, which cuts 62 down to this run's
 * handful, and they fit on one page.
 *
 * ⚠️ THE FIRST ATTEMPT AT THIS USED A SEARCH BOX THAT DOES NOT EXIST HERE, and
 * it is worth saying why so nobody reaches for it again. `CnActionsBar` renders
 * an `<input type="search">` only behind its `showSearch` PROP, and this page
 * does not set it; the "Search and columns" button beside it opens something
 * else. `getByRole('searchbox')` therefore matched nothing and every one of
 * these tests failed inside the helper rather than on its own assertion.
 *
 * The case-type facet is a control the page really renders, one button per
 * type in the sidebar list, and this run seeds its own type. It composes with
 * the lens by design: CnIndexPage spreads a quick filter's own filter BEFORE
 * the user's `activeFilters`, so user facets narrow WITHIN the active tab, and
 * changing tabs re-fetches at page 1 with the facet still applied. Both
 * `/cases` and `/queue` declare a sidebar, so it works on either.
 *
 * @param page The page.
 */
async function narrowToThisRun(page: Page): Promise<void> {
	// 🔴 ANCHOR ON THE FIXTURE'S TITLE, NEVER ON A REBUILT ONE.
	//
	// This used to anchor on `${RUN_PREFIX} Vergunning` with the trailing
	// digits optional, on the reading that they were the facet's match count.
	// They are not. `seedStateMachine` appends a per-call suffix to the
	// caseType TITLE — deliberately, because `RUN_PREFIX` is per-process and a
	// second call in the same worker would otherwise reuse the first call's
	// identifier — so the stored titles are `… Vergunning 1` and
	// `… Vergunning 2`, the regex absorbed both, and Playwright refused the
	// ambiguity.
	//
	// One spec file per worker hid it. A second file sharing the worker seeded
	// the second machine, and from then on every lens test failed inside this
	// helper rather than on its own assertion.
	//
	// ⚠️ THE COUNT SUFFIX IS TOLERANCE, NOT A DESCRIPTION. Measured in a real
	// browser on 2026-09-10, `.cn-folder-tree__item` renders the bare title and
	// nothing else: `textContent` of the "Cultuursubsidie 2026" facet is
	// exactly that, digits and all, with no count node anywhere in the button.
	// The optional group stays only so a build that DOES render a count cannot
	// break this, and it is safe now that the exact title anchors the match.
	const facet = page.getByRole('button', {
		name: new RegExp(
			`^${caseTypeTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s+\\d+)?$`,
		),
	})
	await expect(
		facet,
		`the sidebar should offer a case-type filter named ${caseTypeTitle}`,
	).toBeVisible({ timeout: 30_000 })
	await facet.click()

	// The list must have answered before a lens is touched, or the first
	// assertion races the fetch this click started.
	await expect(
		page.getByRole('row').filter({ hasText: RUN_PREFIX }).first(),
	).toBeVisible({ timeout: 30_000 })
}

/**
 * Wait for the list to have settled on a lens: the row that must be there is
 * there. Asserting an ABSENCE first would pass against a list that has not
 * fetched yet, which is the way a lens test silently stops testing anything.
 *
 * @param page    The page.
 * @param present The key of a row the active lens must show.
 */
/**
 * Record every task-inbox request the page makes, from now on.
 *
 * The Tasks index is a NAMED source, so its predicates never reach the URL
 * the way the Cases index's do: they go straight onto the engine's own
 * endpoint. Reading them off the wire is therefore the only way to say what
 * a chip asked for, as opposed to what came back.
 *
 * @param page The page, hooked before the navigation that triggers the load.
 *
 * @return The captured query strings, newest last.
 */
function inboxQueries(page: Page): URLSearchParams[] {
	const seen: URLSearchParams[] = []
	page.on('request', (request) => {
		const url = request.url()
		if (url.includes('/api/flow-tasks') === true) {
			seen.push(new URL(url).searchParams)
		}
	})
	return seen
}

/**
 * The first captured inbox request carrying `key`, once one arrives.
 *
 * Polled rather than read once: a chip click re-fetches asynchronously, and
 * the page has already made its own unfiltered load before the click.
 *
 * @param seen The array `inboxQueries` returned.
 * @param key  The predicate the lens under test must send.
 *
 * @return That request's query string.
 */
async function firstQueryWith(
	seen: URLSearchParams[],
	key: string,
): Promise<URLSearchParams> {
	await expect
		.poll(() => seen.some((query) => query.get(key) !== null), {
			message: `no task-inbox request carried \`${key}\``,
			timeout: 30_000,
		})
		.toBe(true)
	return seen.find((query) => query.get(key) !== null) as URLSearchParams
}

async function listSettled(page: Page, present: string): Promise<void> {
	await expect(row(page, present).first()).toBeVisible({ timeout: 30_000 })
}

test.describe('Lenses, deadlines and bulk actions on the case list', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		// 🔴 THE DESCRIBE'S `test.setTimeout` DOES NOT REACH THIS HOOK.
		// It sets the budget for TESTS; a hook keeps the 30s default until
		// `test.setTimeout` is called inside it. This hook seeds a state
		// machine, six cases and six engine tasks and then probes the due
		// window, which is past 30s on a loaded instance: measured
		// 2026-09-12, every test in this file failed as `"beforeAll" hook
		// timeout of 30000ms exceeded` and the file read as a broken lens
		// suite rather than as a hook that ran out of time.
		test.setTimeout(300_000)
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		caseTypeId = machine.caseTypeId
		caseTypeTitle = machine.caseTypeTitle
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

		// The tasks the six chips on the Tasks index have to tell apart.
		//
		// 🔴 ENGINE ROWS, NOT OBJECTS. dossiq#2408 retyped this index to
		// `entitySource: "tasks"`, OpenRegister's task engine. A fixture that
		// posts `/api/objects/dossiq/caseTask` writes a table no surface reads,
		// so every lens below waited out its timeout on a row that was never
		// going to arrive — a failure that reads as a broken list rather than
		// as a fixture pointing at the wrong store.
		//
		// Three field names travel with the table (`case` → `objectUuid`,
		// `status` → `state`, `dueDate` → `dueAt`) and one MEANING does: see
		// `instant()` for why the "due later today" fixture is the end of the
		// day rather than nine in the morning.
		const laterToday = instant(0, 23, 59)
		expect(
			new Date(laterToday).getTime(),
			'the "due later today" fixture must still be in the future; this spec '
				+ 'cannot be started in the last minute of the day',
		).toBeGreaterThan(Date.now())

		tasks['task-mine'] = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} task-mine`,
			objectUuid: cases['mine-open'],
			assignee: ME,
			state: 'active',
		})
		tasks['task-other'] = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} task-other`,
			objectUuid: cases['mine-open'],
			assignee: OTHER,
			state: 'active',
		})
		// Pooled is TWO facts, not one: no assignee AND the caller in the
		// candidate pool. The engine's `scope=pooled` is an EXISTS over the
		// candidate index, so a task left merely unassigned is in nobody's
		// pool and the Unclaimed lens would correctly not show it.
		tasks['task-unclaimed'] = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} task-unclaimed`,
			objectUuid: cases['mine-open'],
			state: 'available',
			candidateUsers: [ME],
		})
		// Completed through the VERB. The engine refuses a task born terminal
		// ("it reaches that state through a lifecycle verb"), and driving it
		// there is also the transition a person makes.
		tasks['task-done'] = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} task-done`,
			objectUuid: cases['mine-open'],
			assignee: ME,
			state: 'active',
		})
		await invokeFlowTask(api, token, tasks['task-done'], 'complete')

		for (const [key, dueAt] of Object.entries({
			'task-overdue': instant(-2, 9),
			'task-due-today': laterToday,
			'task-this-week': instant(2, 9),
			'task-next-month': instant(30, 9),
		})) {
			tasks[key] = await seedFlowTask(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				objectUuid: cases['mine-open'],
				assignee: OTHER,
				state: 'active',
				dueAt,
			})
		}

		// The completion has to have LANDED before a Closed or a Mine lens is
		// read: `isTerminal` is what those two chips filter on, and a read-back
		// here names the cause once instead of leaving two browser scenarios to
		// time out on a row whose state never changed.
		const closed = await listFlowTasks(api, {
			scope: 'all',
			isTerminal: 'true',
			// Anchored on the case every task above hangs off, so an API read
			// here is exact rather than page one of the instance's inbox.
			objectUuid: cases['mine-open'],
			limit: '200',
		})
		expect(
			closed.map((t: any) => String(t.uuid)),
			'the completed task must be terminal in the engine',
		).toContain(tasks['task-done'])

		// Does this OpenRegister answer the due-window predicates at all?
		// They arrived in openregister 2.1.4 (openregister#3581); an older
		// instance DROPS them silently — Nextcloud hands a controller only the
		// parameters it declares — and the window then answers everything
		// non-terminal. Probed by behaviour rather than by version string: a
		// task due in thirty days coming back inside a seven-day window is the
		// filter not being applied.
		const probeWindow = await listFlowTasks(api, {
			scope: 'all',
			isTerminal: 'false',
			dueAfter: instant(0, 0),
			dueBefore: instant(7, 0),
			objectUuid: cases['mine-open'],
			limit: '200',
		})
		const probeUuids = probeWindow.map((t: any) => String(t.uuid))
		expect(
			probeUuids,
			'a task due in two days is inside a seven-day window on every version',
		).toContain(tasks['task-this-week'])
		dueWindowSupported = probeUuids.includes(tasks['task-next-month']) === false
	})

	test.afterAll(async () => {
		// The engine tasks first, and separately: a flow task is not an
		// OpenRegister object, so `cleanupRunObjects` cannot see it however
		// the prefix is spelled, and the engine publishes no delete — cancel
		// terminates rather than erases.
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// ---------------------------------------------------------------------
	// my-work — Lenses on the Cases index
	// ---------------------------------------------------------------------

	// @e2e openspec/specs/my-work/spec.md#all-is-the-lens-you-land-on-mine-is-one-click-away
	test('All is the lens you land on, and Mine is one click away', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await narrowToThisRun(page)

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

	// @e2e openspec/specs/my-work/spec.md#unclaimed-shows-what-nobody-has-picked-up
	test('Unclaimed shows what nobody has picked up, and the Queue agrees', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await narrowToThisRun(page)

		await chip(page, CHIPS.unclaimed).click()
		await listSettled(page, 'unclaimed-open')
		await expect(row(page, 'mine-open')).toHaveCount(0)

		// The Queue page's base filter is the same two conditions, so the two
		// lists cannot disagree. This is the browser half of the manifest
		// vitest's deep-equal.
		await visit(page, QUEUE_URL)
		await casesTable(page)
		await narrowToThisRun(page)
		await listSettled(page, 'unclaimed-open')
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// @e2e openspec/specs/my-work/spec.md#all-shows-every-open-and-closed-case
	test("All shows the other person's case and the closed one", async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await narrowToThisRun(page)

		await chip(page, CHIPS.all).click()
		await listSettled(page, 'other-open')
		await expect(row(page, 'mine-closed').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e openspec/specs/my-work/spec.md#chips-replace-each-other
	test('choosing a chip replaces the previous one rather than stacking', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await narrowToThisRun(page)

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

	// @e2e openspec/specs/case-management/spec.md#a-closed-case-leaves-mine
	test('a closed case leaves Mine', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-open')
		await expect(row(page, 'mine-closed')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#closed-shows-the-closed-case
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

	// @e2e openspec/specs/signalering-widgets/spec.md#days-left-on-each-row
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

	// @e2e openspec/specs/signalering-widgets/spec.md#past-due-reads-red
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

	// @e2e openspec/specs/signalering-widgets/spec.md#overdue-shows-only-open-cases-past-their-deadline
	test('Overdue shows the open overdue case only', async ({ page }) => {
		await visit(page, CASES_URL)
		await casesTable(page)

		await chip(page, CHIPS.overdue).click()
		await listSettled(page, 'mine-overdue')
		await expect(row(page, 'closed-overdue')).toHaveCount(0)
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#due-this-week-shows-the-case-due-in-three-days-and-neither-neighbour
	test('Due this week shows the case due in three days and neither neighbour', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await narrowToThisRun(page)

		await chip(page, CHIPS.dueThisWeek).click()
		await listSettled(page, 'mine-open')
		// The window is half-open on both sides, and each neighbour proves
		// one of them: `mine-far` is due in thirty days, past `@today+7d`,
		// and `mine-overdue` was due two days ago, before `@today`. Assert
		// both, because a chip carrying only the far edge would list every
		// overdue case as well and still read as a plausible list.
		await expect(row(page, 'mine-far')).toHaveCount(0)
		await expect(row(page, 'mine-overdue')).toHaveCount(0)
	})

	// @e2e openspec/specs/signalering-widgets/spec.md#the-overdue-tile-keeps-its-filter
	test('the Overdue stat tile links to exactly the Overdue chip filter', async ({
		page,
	}) => {
		// The stat tile is the widget whose COUNT the Overdue chip
		// reproduces, so its link is the one that must carry that chip's
		// filter, key for key.
		await visit(page, APP_URL)

		const tile = page.locator('[aria-label="kpi-overdue"]')
		await expect(tile).toBeVisible({ timeout: 30_000 })
		await tile.click()

		await casesTable(page)
		await expect(page).toHaveURL(/\/cases\?/, { timeout: 15_000 })
		const query = new URL(page.url()).searchParams
		expect(query.get('deadline[lt]')).toMatch(dateTokenPattern('@today'))
		expect(query.get('isFinalStatus')).toBe('false')

		await listSettled(page, 'mine-overdue')
		await expect(row(page, 'closed-overdue')).toHaveCount(0)
		await expect(row(page, 'mine-open')).toHaveCount(0)
	})

	// ---------------------------------------------------------------------
	// task-management — the same six chips on Tasks
	//
	// The Tasks index is the ENGINE's inbox since dossiq#2408
	// (`entitySource: "tasks"`), so what each chip means changed underneath
	// even though the six labels did not: the lenses send `scope`,
	// `isTerminal`, `overdue` and the two due-window predicates rather than
	// object filters over a `caseTask` schema. `scope` is set explicitly on
	// every one of them, because the endpoint defaults to `scope=assigned`
	// and a lens that left it off would quietly mean "my closed tasks" where
	// this list has always meant "closed tasks".
	// ---------------------------------------------------------------------

	// @e2e openspec/specs/task-management/spec.md#all-is-the-default-lens-on-tasks-mine-is-one-click-away
	test('the Tasks index lands on All and offers Mine', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await expect(chip(page, CHIPS.all)).toHaveAttribute('aria-selected', 'true')
		await listSettled(page, 'task-other')

		await chip(page, CHIPS.mine).click()
		// Both halves. Mine is `scope=assigned&isTerminal=false`, and a lens
		// that answered nothing at all would satisfy the absence on its own.
		await listSettled(page, 'task-mine')
		await expect(row(page, 'task-other')).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#unclaimed-shows-tasks-nobody-holds
	test('Unclaimed on Tasks shows the open task nobody holds', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.unclaimed).click()
		await listSettled(page, 'task-unclaimed')
		// `scope=pooled` is "unassigned AND I am in its candidate pool", so a
		// task somebody holds is out whoever that somebody is.
		await expect(row(page, 'task-done')).toHaveCount(0)
		await expect(row(page, 'task-mine')).toHaveCount(0)
		await expect(row(page, 'task-other')).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#all-shows-every-task
	test('All on Tasks shows the completed task too', async ({ page }) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.all).click()
		await listSettled(page, 'task-other')
		// `scope=all` with no `isTerminal`, so a finished task is still here.
		await expect(row(page, 'task-done').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e openspec/specs/task-management/spec.md#closed-shows-the-completed-task
	test('Closed on Tasks shows the completed task and not the open one', async ({
		page,
	}) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.closed).click()
		await listSettled(page, 'task-done')
		await expect(row(page, 'task-mine')).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#a-task-due-later-today-is-not-overdue
	test('Overdue on Tasks leaves out the task due later today', async ({
		page,
	}) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.overdue).click()
		await listSettled(page, 'task-overdue')
		// The boundary, and the reason this file seeds a task due at the end
		// of today at all. The engine derives `overdue` as `dueAt < now` on
		// INSTANTS, so "later today" is genuinely not late yet, and if that
		// derivation is ever replaced by a date comparison this is the
		// assertion that says so.
		await expect(row(page, 'task-due-today')).toHaveCount(0)
		await expect(row(page, 'task-next-month')).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#due-this-week-holds-both-edges-of-the-window
	test('Due this week on Tasks holds both edges of the window', async ({
		page,
	}) => {
		test.skip(
			dueWindowSupported === false,
			'the Due this week lens sends dueAfter/dueBefore, which openregister '
				+ 'answers from 2.1.4 (openregister#3581). An older instance drops '
				+ 'them silently and the lens returns everything non-terminal, so '
				+ 'the window cannot be observed here. Probed against the live '
				+ 'endpoint in beforeAll, not read off a version string.',
		)

		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		await chip(page, CHIPS.dueThisWeek).click()
		await listSettled(page, 'task-this-week')
		// Today is inside the window and two days ago is not, which is the
		// same boundary the Overdue test reads from the other side.
		await expect(row(page, 'task-due-today').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(row(page, 'task-overdue')).toHaveCount(0)
		await expect(row(page, 'task-next-month')).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#the-task-list-shows-a-priority-column
	test('the task row shows the priority REQ-TASK-004 has always asked for', async ({
		page,
	}) => {
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })

		// `caseTask.priority` is `facetable`, so it was always reachable
		// through the sidebar and never present in the row a handler reads.
		// The column header is the whole claim.
		await expect(
			page.getByRole('columnheader', { name: /^(Priority|Prioriteit)$/ }),
		).toBeVisible({ timeout: 30_000 })
	})

	// 🔴 NO CITATION, AND THAT IS HALF THE REPAIR. This test and the one
	// below it carried an anchorless `openspec/specs/task-management/spec.md`
	// citation, read as verified on 2026-09-11 and as partial on 2026-09-12,
	// and the downgrade is right. Both called OpenRegister's flow-task
	// endpoint directly and asserted the ENGINE's own `dueAfter`, `dueBefore`
	// and `overdue` semantics, so a citation naming this app's spec file
	// credited an upstream contract.
	//
	// There is no scenario to re-anchor onto either: `task-management` has no
	// requirement about window boundaries, and its two overdue requirements
	// both carry a reason-bearing `@e2e exclude` for visual indicators
	// covered by `taskHelpers.js` unit tests. So the claim comes down rather
	// than moving.
	//
	// THE OTHER HALF: THE TEST NOW PROVES DOSSIQ'S SIDE TOO. What was wrong
	// was not only the citation. A lens that sent the wrong predicates, or
	// none, left every assertion here green, so the file's own six chips
	// rested on row assertions alone. The request the Due this week chip
	// actually makes is captured before the engine's answer is read, which
	// makes the two halves one claim: the lens asks for the window, and the
	// window means what the chips' row assertions above read.
	test('the Due this week lens sends the window, and the engine honours both edges', async ({
		page,
	}) => {
		// 🔴 THE SKIP MOVED OFF THE TEST AND ONTO THE HALF IT BELONGS TO.
		// `dueAfter`/`dueBefore` are answered by openregister from 2.1.4
		// (openregister#3581) and an older instance DROPS them silently, so
		// the engine's edges cannot be observed everywhere. What the lens
		// ASKS FOR can, on every instance, and that is the half this app
		// owns: skipping the whole test on an old engine used to take
		// dossiq's own claim down with openregister's. Probed by behaviour in
		// `beforeAll`, never off a version string.
		const asked = inboxQueries(page)
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
		await chip(page, CHIPS.dueThisWeek).click()

		// 🔴 THE REQUEST FIRST, THE ROW AFTER. See the Overdue test below for
		// what this ordering is for: a break to the lens widens the list past
		// its 25-row page, the seeded row falls off it, and the mutation then
		// reddens a row wait rather than the predicate the citation is about.
		// This test's mutation happened to survive that in the near edge, and
		// surviving by luck is not a property worth keeping.
		const windowed = await firstQueryWith(asked, 'dueAfter')
		expect(
			windowed.get('dueBefore'),
			'the lens sends both edges, not just the near one',
		).not.toBeNull()
		// `scope` is set explicitly on every lens because the endpoint
		// defaults to `assigned`; a Due this week that left it off would
		// quietly mean "my work due this week".
		expect(windowed.get('scope'), 'over every task, not just mine').toBe('all')
		expect(windowed.get('isTerminal'), 'and not the finished ones').toBe('false')
		const after = new Date(String(windowed.get('dueAfter'))).getTime()
		const before = new Date(String(windowed.get('dueBefore'))).getTime()
		expect(Number.isFinite(after) && Number.isFinite(before)).toBe(true)
		const days = (before - after) / 86_400_000
		expect(
			days,
			`the window the chip asks for is a week, not ${days} days`,
		).toBeGreaterThan(6)
		expect(days).toBeLessThan(8)

		// On an engine that drops the predicates the lens answers everything
		// non-terminal, so the seeded row is there either way; this is a
		// "the list has answered" signal and nothing more.
		await listSettled(page, 'task-this-week')

		// AND THE ENGINE'S OWN EDGES, where this instance answers them, with
		// pagination out of the way — every task this file seeds hangs off
		// one case, so `objectUuid` narrows the read to exactly them.
		if (dueWindowSupported === false) {
			test.info().annotations.push({
				type: 'half-skipped',
				description:
					'This openregister drops dueAfter/dueBefore (pre-2.1.4, '
					+ 'openregister#3581), so the edges below cannot be observed. The '
					+ 'lens assertions above ran.',
			})
			return
		}

		const inWindow = (
			await listFlowTasks(api, {
				scope: 'all',
				isTerminal: 'false',
				dueAfter: instant(0, 0),
				dueBefore: instant(7, 0),
				objectUuid: cases['mine-open'],
				limit: '200',
			})
		).map((t: any) => String(t.uuid))

		expect(inWindow, 'a task due later today is inside the week').toContain(
			tasks['task-due-today'],
		)
		expect(inWindow, 'a task due in two days is inside the week').toContain(
			tasks['task-this-week'],
		)
		expect(inWindow, 'a task due two days ago is not').not.toContain(
			tasks['task-overdue'],
		)
		expect(inWindow, 'a task due in thirty days is not').not.toContain(
			tasks['task-next-month'],
		)
	})

	// No citation, for the reason written above its sibling. Same repair to
	// the body: the Overdue chip's own request is read before the engine's
	// answer, because a broken chip used to survive this test untouched.
	test('the Overdue lens asks for the projection, and a task due later today is not late', async ({
		page,
	}) => {
		const asked = inboxQueries(page)
		await visit(page, TASKS_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
		await chip(page, CHIPS.overdue).click()

		// 🔴 THE REQUEST IS READ BEFORE ANY ROW IS WAITED FOR, AND THE ORDER
		// IS THE POINT. A mutation check on this test twice reddened
		// `listSettled` instead of the assertion below, because ANY break to
		// this lens widens the result set: `scope=all` alone is 69 tasks and
		// `scope=all&isTerminal=false` is 27, both past the 25-row page, so
		// the seeded row falls off page one and the test fails before it ever
		// reads the predicate. A red on a setup line looks exactly like a
		// proof and is just as empty. What this test claims is about the
		// REQUEST, which exists the moment the chip is clicked, so it is
		// asserted first and the row is waited for afterwards.
		const asksLate = await firstQueryWith(asked, 'overdue')
		expect(asksLate.get('overdue'), 'the lens asks the engine, not a date').toBe(
			'true',
		)
		expect(asksLate.get('scope'), 'over every task, not just mine').toBe('all')
		// 🔴 AND NO DATE WINDOW. `overdue` is `dueAt < now` on instants,
		// derived by the engine; a lens that expressed it as `dueBefore=@today`
		// would answer a DIFFERENT question — everything due before midnight,
		// including the task due at 17:00 today — and would still look like a
		// plausible Overdue list.
		expect(
			asksLate.get('dueBefore'),
			'the Overdue lens must not express lateness as a date window',
		).toBeNull()

		// And the list answered, which is what makes the API assertions below
		// about a page a reader could be looking at.
		await listSettled(page, 'task-overdue')

		// The other side of the same boundary, and deliberately NOT gated on
		// the due-window predicates: `overdue` is the engine's own derived
		// projection and every version answers it, so this half of the
		// claim keeps running where the window half cannot.
		const late = (
			await listFlowTasks(api, {
				scope: 'all',
				overdue: 'true',
				objectUuid: cases['mine-open'],
				limit: '200',
			})
		).map((t: any) => String(t.uuid))

		expect(late, 'a task due two days ago is overdue').toContain(
			tasks['task-overdue'],
		)
		expect(late, 'a task due later today is not overdue').not.toContain(
			tasks['task-due-today'],
		)
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
			await tickCheckbox(row(page, key).first().getByRole('checkbox'))
		}

		const strip = page.locator('[data-testid="cn-selection-strip"]')
		await expect(strip).toBeVisible({ timeout: 15_000 })
		await strip.locator(`[data-testid="cn-bulk-action-${actionId}"]`).click()

		const dialog = page.locator('[data-testid="bulk-dialog"]')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		return dialog
	}

	// @e2e openspec/specs/case-bulk-status-transition/spec.md
	//
	// 🔴 STILL NO ANCHOR, AND THE MISSING HALF IS NAMED RATHER THAN GLOSSED.
	// `#transition-moves-the-selection-with-a-reason` ends "AND each case
	// SHALL show the new status ON ITS PAGE". This reads the STORED status of
	// both cases, which is a stronger fact about the engine and a weaker one
	// about the surface: a case page that rendered no status at all would
	// leave every assertion here green. Anchoring would credit that clause to
	// a test that cannot see it. To close this, open one of the two cases and
	// assert the status on the page, then anchor.
	test('Transition moves the selection, with a reason', async ({ page }) => {
		const dialog = await openBulkAction(page, ['bulk-a', 'bulk-b'], 'transition')

		await dialog.getByRole('combobox').first().click()
		// By TEXT, and accepting `.vs__dropdown-option` beside `role="option"`.
		// The transition list renders and the option is plainly on screen —
		// measured off this test's own failure screenshot, "Start behandeling"
		// visible in an open dropdown — but `getByRole('option')` matched
		// nothing for the whole budget, so the failure read as an engine that
		// offered no transitions rather than as a name the a11y tree does not
		// carry. The sibling pattern is `case-timeline.spec.ts`, which pairs
		// the two selectors for exactly this reason.
		await page
			.locator('[role="option"], .vs__dropdown-option')
			.filter({ hasText: /Start behandeling/ })
			.first()
			.click()
		await dialog
			.locator('[data-testid="bulk-reason"]')
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

	// @e2e openspec/specs/case-bulk-status-transition/spec.md#no-reason-no-execute
	test('no reason, no execute', async ({ page }) => {
		const dialog = await openBulkAction(page, ['suspend-me'], 'suspend')

		// The preview has come back and the case is ready; the only thing
		// missing is the reason, and that alone keeps Execute disabled.
		await expect(
			dialog.locator('[data-testid="bulk-preview-summary"]'),
		).toBeVisible({ timeout: 30_000 })
		await expect(dialog.locator('[data-testid="bulk-execute"]')).toBeDisabled()

		await dialog
			.locator('[data-testid="bulk-reason"]')
			.fill('Awaiting documents')
		await expect(dialog.locator('[data-testid="bulk-execute"]')).toBeEnabled()
	})

	// @e2e openspec/specs/case-bulk-status-transition/spec.md
	//
	// 🔴 STILL NO ANCHOR. `#suspend-and-resume-through-the-term` says "the
	// CASE PAGE SHALL show the term as paused with that reason", and both
	// halves here are read off the case's stored journal instead. The comment
	// below is right that the journal is what the page reads, which is
	// exactly why the page is worth asserting separately: a term widget that
	// stopped rendering would not redden a line of this. To close this, open
	// the case and assert the paused and running states, then anchor.
	test('Suspend then Resume, each with its reason', async ({ page }) => {
		const suspend = await openBulkAction(page, ['suspend-me'], 'suspend')
		await suspend
			.locator('[data-testid="bulk-reason"]')
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
			.locator('[data-testid="bulk-reason"]')
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

	// @e2e openspec/specs/case-bulk-status-transition/spec.md
	//
	// 🔴 STILL NO ANCHOR. `#extend-term-writes-the-new-end-date` has three
	// clauses and this proves one of them on the surface it names: the dialog
	// reporting 1 of 1. The other two, "the case page SHALL show the extended
	// term with that reason" and "the DEADLINE COLUMN SHALL be unchanged",
	// are both read off the stored object here. The deadline one is the
	// sharper miss: the claim is about a column a reader sees, and the
	// assertion is about a field. To close this, assert the case page and the
	// Deadline cell of the row, then anchor.
	test('Extend term writes the new end date, and leaves the Deadline column alone', async ({
		page,
	}) => {
		const before = await showObject(api, 'case', cases['extend-me'])
		const dialog = await openBulkAction(page, ['extend-me'], 'extend-term')

		await dialog.locator('[data-testid="bulk-new-deadline"]').fill(day(20))
		await dialog.locator('[data-testid="bulk-reason"]').fill('Complex case')
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

	// @e2e openspec/specs/case-bulk-status-transition/spec.md
	//
	// 🔴 NO ANCHOR BECAUSE THERE IS NO SCENARIO TO NAME. The requirement
	// "Bulk actions on the case index" states the five handlers in prose, and
	// every scenario under it is about what one action DOES once opened, not
	// about the strip offering them. Naming one of those would credit a
	// transition, suspend or extend claim to a test that only counts buttons.
	// The honest close is a scenario for the affordance, not an anchor.
	test('the Cases list offers all five bulk actions on a selection', async ({
		page,
	}) => {
		await visit(page, CASES_URL)
		await casesTable(page)
		await chip(page, CHIPS.mine).click()
		await listSettled(page, 'mine-far')
		await tickCheckbox(row(page, 'mine-far').first().getByRole('checkbox'))

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

	// No citation, on purpose. This test used to cite
	// openspec/specs/case-management/spec.md with no anchor, which names a
	// file and credits no scenario. The requirement it is about, REQ-CM-32 Deadline
	// before, has one scenario, and that scenario is excluded on the spec because
	// the sidebar control it drives does not exist (see the comment below).
	// A query against the object API cannot prove a control, so the test
	// stays as a guard on the query path the Overdue tiles use, uncited
	// (e2e-citation-integrity, audit group 3).
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
