/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A task created on a case goes to its handler (task-defaults-to-case-handler).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * AssigneeResolverTest pins the four steps, and
 * TaskDefaultsToCaseHandlerTest pins that the transition handler and the flow
 * node land on the same principal. Neither can reach the round trip this spec
 * is about: a real transition running a `createTask` action that names nobody,
 * the engine storing what came out of it, and the task turning up under Mine
 * on the Tasks index where the handler actually looks for work. Each half is
 * invisible on its own. A resolver that answers the right uid proves nothing
 * if the handler writes a different field, and a Mine lens that filters
 * correctly proves nothing if no task ever carries an assignee.
 *
 * THE CONTROL, AND WHY IT IS NOT OPTIONAL
 * ---------------------------------------
 * 🔴 THE SIGNED-IN USER IS ALSO THE ACTOR. Every transition here is executed
 * by the same session the case names as its handler, so a task that landed on
 * the ACTOR — the one fallback this change must never introduce, because it
 * puts a passer-by's name on somebody else's work — looks exactly like a task
 * that landed on the handler. The second test is what separates them: a case
 * with no handler at all must leave the task unassigned, and specifically must
 * not carry the uid of whoever pressed the button.
 *
 * WHERE THE TASK LIVES
 * --------------------
 * In OpenRegister's task ENGINE and nowhere else: dossiq#2363 removed the
 * register write, so `CreateTaskHandler` calls `EngineTaskGateway::
 * mirrorImport()` and stops. The arrival half therefore reads
 * `/api/flow-tasks`, a different table with its own field names — `case` is
 * `objectUuid` and `status` is `state` — and `scope=all`, because the default
 * scope is `assigned` and the control case's task is assigned to nobody.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance, so the lens chips are
 * matched in either language and every other assertion is on data this spec
 * seeded itself, run-prefixed so it reads the same in both.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	executeTransition,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** The engine's own table. A flow task is not an OpenRegister object. */
const FLOW_TASKS_BASE = '/index.php/apps/openregister/api/flow-tasks'

const APP_URL = `/apps/${REGISTER}/`
const TASKS_URL = `${APP_URL}tasks`

/** The one transition both cases take. */
const TRANSITION = 'tdch-start'

/**
 * The title the `createTask` action gives every task it creates.
 *
 * One title for both cases is safe because `theTask` addresses a task by its
 * CASE as well as its title: the two tests assert opposite things, and neither
 * can ever read the other's row.
 */
const TASK_TITLE = `${RUN_PREFIX} Beoordeel het dossier`

/** The lens chips on the Tasks index, in either language. */
const CHIPS = {
	all: /^(All|Alle)$/,
	mine: /^(Mine|Van mij)$/,
}

let api: APIRequestContext
let token = ''
/** The signed-in user: the case handler AND the actor, which is the point. */
let currentUser = ''

const seeded = { caseType: '', intake: '', progress: '' }
const cases: Record<string, string> = {}

/**
 * Every engine task standing on one case.
 *
 * `scope=all` because the question is what the CASE carries. The default scope
 * is `assigned`, which would answer `[]` for the control case and read as "the
 * action created nothing" rather than as "the task is unassigned", which is
 * the assertion.
 *
 * @param onCase The case to read.
 */
async function tasksOf(onCase: string): Promise<any[]> {
	const query = new URLSearchParams({
		objectUuid: onCase,
		scope: 'all',
		limit: '200',
	})
	const res = await api.get(`${FLOW_TASKS_BASE}?${query.toString()}`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(
		res.ok(),
		`list engine tasks for ${onCase} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()

	return (await res.json())?.results ?? []
}

/**
 * The one task a case's transition created, by its title.
 *
 * Addressed by title rather than by position: the instance is shared and a
 * case can pick up work from elsewhere, and asserting about `rows[0]` would
 * make this spec fail for reasons that have nothing to do with it.
 *
 * @param onCase The case.
 * @param title  The title the action gave the task.
 */
async function theTask(onCase: string, title: string): Promise<any> {
	const rows = await tasksOf(onCase)
	const match = rows.filter((row) => String(row.title ?? '') === title)
	expect(
		match.length,
		`expected exactly one task titled "${title}" on ${onCase}, got ${rows
			.map((row) => String(row.title ?? ''))
			.join(' | ')}`,
	).toBe(1)

	return match[0]
}

/**
 * Cancel every engine task this run created.
 *
 * 🔴 THE ENGINE PUBLISHES NO DELETE, and these are not OpenRegister objects,
 * so no schema sweep reaches them however the prefix is spelled. `cancel`
 * terminates rather than erases, and it is the only removal verb there is.
 * Failures are swallowed: a teardown that threw would redden a run whose
 * assertions all passed.
 */
async function cancelEngineTasks(): Promise<void> {
	for (const caseId of Object.values(cases)) {
		try {
			for (const row of await tasksOf(caseId)) {
				await api.post(`${FLOW_TASKS_BASE}/${String(row.uuid)}/cancel`, {
					headers: {
						requesttoken: token,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					data: {},
				})
			}
		} catch {
			// Best effort; the next run's residue sweep is the backstop.
		}
	}
}

/**
 * Open the Tasks index and wait for its table.
 *
 * @param page The Playwright page.
 */
async function openTasks(page: Page): Promise<void> {
	await page.goto(TASKS_URL, PAGE_LOAD)
	await dismissSupportDialog(page)
	await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
}

test.describe('A task created on a case goes to the case handler', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// 🔴 `test.setTimeout` ON THE DESCRIBE DOES NOT REACH THIS HOOK: it
		// sets the budget for TESTS, and a hook keeps the 30s default until it
		// is called inside. Seeding a case type, two statuses, a workflow and
		// two cases is past 30s on a loaded rig, and the whole file then reads
		// as a broken feature rather than a slow fixture.
		test.setTimeout(180_000)

		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// `OCS-APIRequest` is not optional: without it Nextcloud's CSRF guard
		// answers a plain OCS GET with 412, which reads as "no session".
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// A case type this file owns. The transition has to carry a
		// `createTask` action that names NOBODY, which is not something to
		// attach to a published type another spec reads.
		seeded.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Task defaults`,
				identifier: `${RUN_PREFIX.toLowerCase()}-task-defaults`,
				description:
					'Throwaway caseType seeded by task-defaults-to-case-handler.spec.ts.',
				// The schema defaults `isDraft` to true and `case.caseType`
				// filters on `isDraft: false`, so a type seeded without this is
				// invisible to the transition engine.
				isDraft: false,
			}),
		)
		seeded.intake = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Intake`,
				caseType: seeded.caseType,
				order: 1,
				isFinal: false,
			}),
		)
		seeded.progress = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} In behandeling`,
				caseType: seeded.caseType,
				order: 2,
				isFinal: false,
			}),
		)

		// THE ACTION NAMES NOBODY. That is the whole fixture: before this
		// change it produced a task addressed to '', on a case with a named
		// handler one field away, and the task schema's `taskAssigned`
		// notification went nowhere.
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Task defaults workflow`,
			caseType: seeded.caseType,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: TRANSITION,
					label: `${RUN_PREFIX} Start behandeling`,
					fromStatus: seeded.intake,
					toStatus: seeded.progress,
					guards: [],
					automaticActions: [{ type: 'createTask', title: TASK_TITLE }],
				},
			]),
		})

		cases.handled = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Case with a handler`,
				caseType: seeded.caseType,
				status: seeded.intake,
				assignee: currentUser,
			}),
		)
		// The control: no handler, no team.
		cases.orphan = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Case with no handler`,
				caseType: seeded.caseType,
				status: seeded.intake,
			}),
		)
	})

	test.afterAll(async () => {
		// The engine tasks FIRST: they are addressed by their case, and
		// `cleanupRunObjects` is about to remove the cases.
		await cancelEngineTasks()
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/task-management/spec.md#the-handler-gets-the-task
	test('the handler gets the task, and it shows under Mine', async ({ page }) => {
		const moved = await executeTransition(api, token, cases.handled, TRANSITION)
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)

		const task = await theTask(cases.handled, TASK_TITLE)
		expect(
			String(task.assignee ?? ''),
			'the task the transition created must carry the case handler',
		).toBe(currentUser)

		// And the handler finds it where they look for work. Both halves: the
		// row is there under Mine, and All is where the run started, so a lens
		// that answered nothing at all cannot satisfy this on its own.
		await openTasks(page)
		await expect(page.getByRole('tab', { name: CHIPS.all })).toHaveAttribute(
			'aria-selected',
			'true',
		)
		await page.getByRole('tab', { name: CHIPS.mine }).click()
		await expect(
			page.getByRole('row').filter({ hasText: TASK_TITLE }).first(),
		).toBeVisible({ timeout: 30_000 })
	})

	// The control. Not a scenario of its own: it is what makes the test above
	// mean "the CASE HANDLER" rather than "whoever pressed the button", who is
	// the same person on this instance.
	test('a case with no handler leaves the task unassigned, and never names the actor', async () => {
		const moved = await executeTransition(api, token, cases.orphan, TRANSITION)
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)

		const task = await theTask(cases.orphan, TASK_TITLE)
		expect(
			String(task.assignee ?? ''),
			'a case with no handler must not hand its task to whoever moved it',
		).not.toBe(currentUser)
		expect(String(task.assignee ?? ''), 'and must leave it unassigned').toBe('')
	})
})
