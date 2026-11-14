/**
 * The caseload surfaces a demo actually shows: the Tasks page, and the two
 * dashboard widgets that scope to the current user.
 *
 * WHAT THIS ASSERTS, AND WHY IT EXISTS. It pins a defect that shipped, and
 * that failed silently rather than loudly: the Tasks page must list tasks. It
 * listed none, because `task_schema` pointed at a schema in ANOTHER app's
 * register, and every task Dossiq wrote went there.
 *
 * 🔴 TWO SCENARIOS CHANGED STORES WITH THE SCHEMA, AND THEY ARE THE SAME TWO
 * QUESTIONS ASKED OF THE ENGINE.
 *
 * They were `a completed task reads as terminal` and `daysUntilDue is returned
 * when calculations are extended`, and both read MATERIALISED OpenRegister
 * CALCULATIONS off dossiq's own `caseTask` schema. Both had the same root
 * cause as the third: the calculations were installed on another app's `task`
 * schema, because both of dossiq's reconcilers resolved that slug
 * instance-wide and three schemas carried it. A completed task read
 * `isTerminalStatus: false` and every due-date column rendered empty, with no
 * error anywhere.
 *
 * remove-casetask deleted the schema, so there is no calculation of ours to
 * materialise. The engine answers both questions itself, and differently:
 * `isTerminal` is a real COLUMN it maintains through the lifecycle verbs, and
 * the deadline is a per-row PROJECTION `TaskInboxService::row()` attaches at
 * read time (`daysOverdue` counts up after the deadline, `daysUntilDue` counts
 * down before it, never both). The user-visible truth is unchanged and so are
 * the two assertions: completed work must stay out of an open-work read, and a
 * task with a deadline must come back with a number rather than a blank.
 *
 * Not the same as the lens coverage in `case-list-lenses.spec.ts`, which
 * probes the `dueAfter`/`dueBefore` FILTER. This is the per-row projection the
 * columns render, and `signedDaysUntilDue()` in `src/store/modules/engineTask.js`
 * folds the two fields into the one signed number a column shows.
 *
 * SEEDED, NOT ASSUMED. These assertions are data-dependent, which is why the
 * sibling widget scenarios are marked `@e2e exclude`. This spec seeds exactly
 * what it needs under a per-run prefix and removes it again, so it does not
 * depend on demo data being present and cannot be satisfied by somebody
 * else's rows.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Where a seeded row is missing
 * the test fails naming it rather than skipping: a skip cannot tell "not
 * seeded" from "the seeder is broken", which is exactly the confusion that
 * let the original defect sit.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/signalering-widgets/spec.md#requirement-task-due-reminders-widget-v1
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'
import { navToRoute } from './helpers/nav.ts'

/** The case every task in this spec hangs off. */
const CASE_TITLE = `${RUN_PREFIX} Caseload case`

/**
 * An OPEN task, which must appear on the Tasks page and open on its own page.
 *
 * 🔴 IT IS AN ENGINE TASK, and there is only one store now. This spec used to
 * seed two `caseTask` objects AND a third row in the engine, because its
 * calculation scenarios needed an object and its detail scenario needed an
 * engine uuid. `/tasks/{id}` is TaskDetailView since dossiq#2411 and reads the
 * engine by uuid, so handing it an object id resolved nothing and the page
 * rendered empty: that is why the two rows had two names. With the schema gone
 * there is one row and one name.
 */
const OPEN_TASK = `${RUN_PREFIX} Open task`

/** A COMPLETED task, which must appear nowhere that filters on open work. */
const DONE_TASK = `${RUN_PREFIX} Completed task`

/** A task whose deadline has NOT passed, so the projection counts down. */
const FUTURE_TASK = `${RUN_PREFIX} Future task`

/**
 * Days from now, as the ISO instant the engine stores in `dueAt`.
 *
 * 🔴 AN HOUR OF SLACK, AWAY FROM NOW, AND IT IS LOAD-BEARING. The engine's
 * projection is `intdiv(abs(deadline - now), 86400)` on an INSTANT, so a
 * deadline set exactly two days back reports 1 rather than 2 the moment a
 * single second of the run has elapsed. The slack pushes each fixture past the
 * boundary in the direction it already points, so the assertion is an exact
 * number on every run rather than a band that would pass either way.
 *
 * @param days Offset in days, negative for the past.
 */
function dueInDays(days: number): string {
	const slackHours = days < 0 ? -1 : 1
	return new Date(
		Date.now() + (days * 24 + slackHours) * 3600 * 1000,
	).toISOString()
}

test.describe('Demo caseload surfaces', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string
	let engineTaskUuid: string
	let futureTaskUuid: string
	let doneTaskUuid: string

	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext()
		api = context.request
		token = await getRequestToken(api)

		// HANG THE TASKS OFF AN EXISTING CASE RATHER THAN SEEDING ONE. The case
		// schema declares `x-openregister-archival`, so OpenRegister refuses a
		// user-driven delete, and for a long time that meant a case this spec
		// created stayed forever: measured on the dev instance, 17 of its 37 cases
		// were exactly that residue. Removing one is now possible — teardown goes
		// through `occ openregister:objects:purge --force --apply` — but it is a
		// deliberate administrative act, and this spec does not need to perform
		// one. Tasks carry no such rule and are removed in afterAll.
		const cases = await listObjects(api, 'case', { _limit: '1' })
		if (cases.length > 0) {
			caseId = objectId(cases[0])
		} else {
			// Only where the register is genuinely empty. This one case is
			// permanent, and that is better than the spec having nothing to attach
			// to and failing for a reason unrelated to what it tests.
			const caseType = await ensureCaseType(api, token)
			caseId = objectId(
				await seedCase(api, token, {
					title: CASE_TITLE,
					caseType: caseType.id,
					assignee: 'admin',
				}),
			)
		}

		// Field names are the engine's, and three of them differ from the ones
		// the register task carried: `case` is `objectUuid`, `status` is
		// `state`, `dueDate` is `dueAt`. The id is a uuid; the numeric primary
		// key is one no route accepts.
		engineTaskUuid = await seedFlowTask(api, token, {
			title: OPEN_TASK,
			objectUuid: caseId,
			assignee: 'admin',
			state: 'active',
			dueAt: dueInDays(-2),
		})

		futureTaskUuid = await seedFlowTask(api, token, {
			title: FUTURE_TASK,
			objectUuid: caseId,
			assignee: 'admin',
			state: 'active',
			dueAt: dueInDays(3),
		})

		// COMPLETED THROUGH THE VERB. The engine refuses a task born terminal
		// ("it reaches that state through a lifecycle verb"), which is also
		// the transition a person makes.
		doneTaskUuid = await seedFlowTask(api, token, {
			title: DONE_TASK,
			objectUuid: caseId,
			assignee: 'admin',
			state: 'active',
			dueAt: dueInDays(-4),
		})
		await invokeFlowTask(api, token, doneTaskUuid, 'complete')
	})

	test.afterAll(async () => {
		// The engine task is not an OpenRegister object, so the prefix sweep
		// cannot see it; `cancel` is the only removal verb the engine has.
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
	})

	test('a completed task reads as terminal, so open-work filters exclude it', async () => {
		// The engine's own column, asked for by name. When terminality was a
		// calculation installed on the wrong schema this was false for every
		// completed task and every open-work filter let it through.
		const closed = await listFlowTasks(api, {
			scope: 'all',
			isTerminal: 'true',
			objectUuid: caseId,
			limit: '200',
		})
		const open = await listFlowTasks(api, {
			scope: 'all',
			isTerminal: 'false',
			objectUuid: caseId,
			limit: '200',
		})

		const closedUuids = closed.map((t: any) => String(t.uuid))
		const openUuids = open.map((t: any) => String(t.uuid))

		// BOTH DIRECTIONS. A filter that is dropped rather than applied
		// answers everything, so "the completed task is in the closed list"
		// passes on its own while proving nothing.
		expect(
			closedUuids,
			`the completed task "${DONE_TASK}" must read as terminal`,
		).toContain(doneTaskUuid)
		expect(
			openUuids,
			'a completed task must not come back from an open-work read',
		).not.toContain(doneTaskUuid)
		expect(
			openUuids,
			`the open task "${OPEN_TASK}" must come back from an open-work read`,
		).toContain(engineTaskUuid)
		expect(
			closedUuids,
			'an active task must not read as terminal',
		).not.toContain(engineTaskUuid)
	})

	test('the deadline projection comes back per row, so a due column is never empty', async () => {
		const rows = await listFlowTasks(api, {
			scope: 'all',
			objectUuid: caseId,
			limit: '200',
		})

		const overdue = rows.find((t: any) => String(t.uuid) === engineTaskUuid)
		const upcoming = rows.find((t: any) => String(t.uuid) === futureTaskUuid)

		expect(overdue, `seeded task "${OPEN_TASK}" is missing`).toBeTruthy()
		expect(upcoming, `seeded task "${FUTURE_TASK}" is missing`).toBeTruthy()

		// The NUMBER, not merely that a key exists: the shipped defect
		// returned null and the column rendered blank. The engine reports the
		// two directions in two fields and never both, which is the shape
		// `signedDaysUntilDue()` folds.
		expect(
			overdue.daysOverdue,
			'a task two days past its deadline must report daysOverdue = 2',
		).toBe(2)
		expect(
			overdue.daysUntilDue,
			'daysUntilDue must be null once the deadline has passed',
		).toBeNull()
		expect(
			upcoming.daysUntilDue,
			'a task due in three days must report daysUntilDue = 3',
		).toBe(3)
		expect(
			upcoming.daysOverdue,
			'daysOverdue must be null before the deadline',
		).toBeNull()
	})

	test('the Tasks page lists tasks instead of an empty state', async ({
		page,
	}) => {
		await navToRoute(page, '/tasks')

		// The shipped defect rendered this page's empty state on an instance that
		// had tasks, because `task_schema` pointed at a schema in another
		// register. The page reads the engine's inbox now, so the seed above is
		// what puts a row here.
		//
		// Asserting on ROWS rather than on the seeded title on purpose: the index
		// pages at 20 rows, so a title assertion here would depend on how many
		// tasks the instance happens to hold. The seeded task's own visibility is
		// pinned by the detail test below.
		await expect(
			page.locator('tbody tr').first(),
			'the Tasks page must render at least one task row',
		).toBeVisible({ timeout: 20000 })

		await expect(
			page.getByText('No items found'),
			'the Tasks page must not show its empty state while tasks exist',
		).toHaveCount(0)
	})

	test('a seeded task opens on its own detail page', async ({ page }) => {
		// THE ENGINE'S UUID. `/tasks/{id}` is TaskDetailView since dossiq#2411
		// and it reads the task engine by uuid. It used to be handed an object
		// id as well, which resolved nothing and rendered an empty page: that
		// reads as "the detail page is broken" rather than as "that id belongs
		// to the other store".
		await navToRoute(page, `/tasks/${engineTaskUuid}`)

		await expect(
			page.getByText(OPEN_TASK, { exact: false }).first(),
			'the task detail page must show the seeded engine task',
		).toBeVisible({ timeout: 20000 })
	})
})
