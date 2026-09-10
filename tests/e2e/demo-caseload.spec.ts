/**
 * The caseload surfaces a demo actually shows: the Tasks page, and the two
 * dashboard widgets that scope to the current user.
 *
 * WHAT THIS ASSERTS, AND WHY EACH ONE EXISTS. All three pin a defect that
 * shipped, and all three failed silently rather than loudly.
 *
 * 1. A task whose status is terminal must READ as terminal. `isTerminalStatus`
 *    is a materialised OpenRegister calculation, and it was installed on
 *    another app's `task` schema instead of ours, because both of Dossiq's
 *    schema reconcilers resolved the slug `task` instance-wide and three
 *    schemas carried it. So every completed task read isTerminalStatus =
 *    false. Nothing errored. The My work widget (My Tasks before `dashboard-tiles` merged it), whose entire filter is
 *    isTerminalStatus = false, simply kept showing completed work.
 *
 * 2. `daysUntilDue` must come back when asked for. Same root cause: the
 *    calculation was declared on the foreign schema, so extending ours
 *    returned nothing and every due-date column rendered as an empty cell.
 *
 * 3. The Tasks page must list tasks. It listed none, because `task_schema`
 *    pointed at that same foreign schema and every task Dossiq wrote went
 *    into another register.
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
	createObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'
import { navToRoute } from './helpers/nav.ts'

/** The case every task in this spec hangs off. */
const CASE_TITLE = `${RUN_PREFIX} Caseload case`

/** An OPEN task, which must appear on the Tasks page and in My work. */
const OPEN_TASK = `${RUN_PREFIX} Open task`

/** A COMPLETED task, which must appear nowhere that filters on open work. */
const DONE_TASK = `${RUN_PREFIX} Completed task`

/**
 * The ENGINE task the detail page opens.
 *
 * 🔴 THE TWO STORES ARE BOTH REAL HERE, and this spec needs one row in each.
 * Its first two scenarios are about OpenRegister CALCULATIONS on the
 * `caseTask` schema (`isTerminalStatus`, `daysUntilDue`) — materialised
 * fields of an object, which only an object has. Its last scenario opens
 * `/tasks/{id}`, and dossiq#2411 retyped that route to a page that reads
 * OpenRegister's task ENGINE by uuid. An object uuid resolves to no engine
 * task, so the page rendered empty and the assertion timed out on a title
 * that was never going to arrive.
 *
 * Seeding the same task twice would be worse than two rows with two names:
 * a reader would have no way to tell which store each assertion is about.
 */
const ENGINE_TASK = `${RUN_PREFIX} Engine open task`

/**
 * Days from today, as the ISO date-time the task schema stores.
 *
 * @param days Offset in days, negative for the past.
 */
function dueInDays(days: number): string {
	const d = new Date()
	d.setDate(d.getDate() + days)
	return d.toISOString()
}

test.describe('Demo caseload surfaces', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string
	let engineTaskUuid: string

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

		await createObject(api, token, 'caseTask', {
			title: OPEN_TASK,
			case: caseId,
			assignee: 'admin',
			status: 'active',
			dueDate: dueInDays(-2),
		})

		await createObject(api, token, 'caseTask', {
			title: DONE_TASK,
			case: caseId,
			assignee: 'admin',
			status: 'completed',
			dueDate: dueInDays(-4),
		})

		// And one in the engine, for the detail page. Field names differ with
		// the table: `case` is `objectUuid`, `status` is `state`, `dueDate` is
		// `dueAt`, and the id is a uuid.
		engineTaskUuid = await seedFlowTask(api, token, {
			title: ENGINE_TASK,
			objectUuid: caseId,
			assignee: 'admin',
			state: 'active',
			dueAt: dueInDays(-2),
		})
	})

	test.afterAll(async () => {
		// The engine task is not an OpenRegister object, so the prefix sweep
		// cannot see it; `cancel` is the only removal verb the engine has.
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
	})

	test('a completed task reads as terminal, so open-work filters exclude it', async () => {
		const tasks = await listObjects(api, 'caseTask')

		const open = tasks.find((t) => t.title === OPEN_TASK)
		const done = tasks.find((t) => t.title === DONE_TASK)

		expect(open, `seeded task "${OPEN_TASK}" is missing`).toBeTruthy()
		expect(done, `seeded task "${DONE_TASK}" is missing`).toBeTruthy()

		// The calculation, not the raw status. When it is installed on the wrong
		// schema this is false and every open-work filter lets the task through.
		expect(
			done.isTerminalStatus,
			'a completed task must materialise isTerminalStatus = true',
		).toBe(true)
		expect(
			open.isTerminalStatus,
			'an active task must materialise isTerminalStatus = false',
		).toBe(false)
	})

	test('daysUntilDue is returned when calculations are extended', async () => {
		const tasks = await listObjects(api, 'caseTask', { _extend: 'calculations' })
		const open = tasks.find((t) => t.title === OPEN_TASK)

		expect(open, `seeded task "${OPEN_TASK}" is missing`).toBeTruthy()
		// Seeded two days in the past, so the signed value is negative. Asserting
		// the NUMBER, not merely that a key exists: the defect returned null.
		expect(
			open.daysUntilDue,
			'daysUntilDue must compute for a task with a due date',
		).toBe(-2)
	})

	test('the Tasks page lists tasks instead of an empty state', async ({
		page,
	}) => {
		await navToRoute(page, '/tasks')

		// The shipped defect rendered this page's empty state on an instance that
		// had tasks, because task_schema pointed at a schema in another register.
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
		// THE ENGINE'S UUID, not the object's id. `/tasks/{id}` is
		// TaskDetailView since dossiq#2411 and it reads the task engine;
		// handed a `caseTask` object id it resolves nothing and renders an
		// empty page, which reads as "the detail page is broken" rather than
		// as "that id belongs to the other store".
		await navToRoute(page, `/tasks/${engineTaskUuid}`)

		await expect(
			page.getByText(ENGINE_TASK, { exact: false }).first(),
			'the task detail page must show the seeded engine task',
		).toBeVisible({ timeout: 20000 })
	})
})
