/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A status brings its checklist with it (checklist-per-status).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * The reader and the guard are covered by StatusChecklistTest and
 * StatusChecklistGuardTest. What those cannot reach is the round trip: the
 * engine writing tasks through OpenRegister on a real transition, the same
 * tasks turning up in the Tasks pane on the case page, and the transition
 * strip disabling a button and saying which item is still open. Each half
 * looks fine on its own — a reader that yields the right actions is invisible
 * if the handler never runs them, and a guard that fails correctly is
 * invisible if the button never reads its reason.
 *
 * TWO STORES, AND THE TRANSITION WRITES ONLY ONE OF THEM
 * ------------------------------------------------------
 * A task created by a transition lives in OpenRegister's task ENGINE and
 * nowhere else. dossiq#2363 removed the register write: `CreateTaskHandler`
 * calls `EngineTaskGateway::mirrorImport()` and nothing more, and says so at
 * lib/Service/Transitions/CreateTaskHandler.php:122. So the ARRIVAL half of
 * this spec reads `/api/flow-tasks`, which is a different table with its own
 * field names and its own lifecycle verbs.
 *
 * The GUARD half seeded a `caseTask` register object until dossiq#2405 moved
 * `StatusChecklist::tasksFor()` onto the engine. Both halves now read and
 * write the one store the transition writes.
 *
 * TWO CASE TYPES, ON PURPOSE
 * --------------------------
 * A required item on a status holds every case IN that status, so a case type
 * whose intake carries one can never be used to watch a transition arrive
 * somewhere else. `arrival` therefore keeps its required work in the phase the
 * cases move INTO, and `guard` puts it on the phase they sit in.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance, and the guard's message is
 * translated ("Checklist item not done: %s" / "Checklistitem nog niet
 * afgerond: %s"). Every assertion is therefore on a `data-testid` or on text
 * this spec seeded itself — the item titles carry RUN_PREFIX and read the same
 * in either locale.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	executeTransition,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/** The engine's own table. A flow task is not an OpenRegister object. */
const FLOW_TASKS_BASE = '/index.php/apps/openregister/api/flow-tasks'

/** Transition ids the two seeded workflows declare. */
const T = {
	arrivalStart: 'cps-arrival-start',
	arrivalBack: 'cps-arrival-back',
	guardStart: 'cps-guard-start',
}

/** The checklist items, prefixed so teardown and locale both cope. */
const ITEM = {
	onTime: `${RUN_PREFIX} Check the objection is on time`,
	receipt: `${RUN_PREFIX} Confirm receipt to the objector`,
	dossier: `${RUN_PREFIX} Assemble the case file`,
	inform: `${RUN_PREFIX} Inform the primary decision maker`,
}

let api: APIRequestContext
let token = ''
/** The signed-in user, who a seeded task is assigned to. */
let currentUser = ''

/** The case type whose WORKING phase carries the checklist. */
const arrival = { caseType: '', intake: '', progress: '' }

/** The case type whose INTAKE phase carries a required item. */
const guarded = { caseType: '', intake: '', progress: '' }

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/**
 * Create one statusType, optionally with a checklist.
 *
 * @param caseType  The case type it belongs to.
 * @param name      The status name.
 * @param order     Its position in the lifecycle.
 * @param checklist The items the status asks for.
 */
async function seedStatus(
	caseType: string,
	name: string,
	order: number,
	checklist: Array<{ title: string; required: boolean }> = [],
): Promise<string> {
	const row = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} ${name}`,
		caseType,
		order,
		isFinal: false,
		checklist,
	})
	return objectId(row)
}

/**
 * Seed one task as if a status had created it, IN THE ENGINE.
 *
 * Both the de-duplication and the guard read `workflowStepId`, so a task
 * seeded without it is a task neither of them can see.
 *
 * IT USED TO SEED `caseTask`, and had to. dossiq#2402 moved this file's
 * arrival half onto the engine and left this half on the register on purpose,
 * because the guard's own reader had not moved: `StatusChecklist::tasksFor()`
 * still searched the `task_schema` register that `CreateTaskHandler` stopped
 * writing at dossiq#2363, so a task seeded in the engine was invisible to the
 * thing under test. dossiq#2405 moved that reader onto
 * `EngineTaskInbox::forCase()`, which is what #2402's note said this helper
 * should follow. Left behind, the fixture wrote one table while the guard read
 * the other, and `completing the task frees the case` failed on the mismatch.
 *
 * The engine's field names differ from the register's: `case` is `objectUuid`,
 * because the case IS the object and no typed case reference exists
 * engine-side, and `status` is `state`. The id comes back as `uuid`; the
 * numeric primary key is one no route accepts.
 *
 * @param onCase       The case the task belongs to.
 * @param title        The task title.
 * @param workflowStep The statusType that asked for it.
 */
async function seedTask(
	onCase: string,
	title: string,
	workflowStep: string,
): Promise<string> {
	const res = await api.post(FLOW_TASKS_BASE, {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: {
			title,
			objectUuid: onCase,
			assignee: currentUser,
			state: 'available',
			workflowStepId: workflowStep,
			appId: 'dossiq',
		},
	})
	expect(
		res.status(),
		`seed task "${title}" -> ${res.status()} ${await res.text()}`,
	).toBe(201)

	const created = await res.json()
	expect(created.uuid, `seeded task "${title}" has no uuid`).toBeTruthy()
	// The tag is what both readers scope on. A seed that lost it would make
	// every guard assertion below pass or fail for the wrong reason.
	expect(
		String(created.workflowStepId ?? ''),
		`seeded task "${title}" did not keep its workflowStepId`,
	).toBe(workflowStep)
	return String(created.uuid)
}

/**
 * Drive an ENGINE task to `completed` through the engine's own verb.
 *
 * The engine's `complete` refuses a task that is already terminal and
 * otherwise takes it straight there, so there is no `activate` step to walk:
 * `outcome` defaults to `done` and the run's admin session clears the verb's
 * authorization. The state is read back rather than inferred from the 200,
 * because a verb that answered and changed nothing is exactly the failure
 * this whole migration keeps producing.
 *
 * @param uuid The engine task uuid. NOT a numeric id: no route accepts one.
 */
async function completeEngineTask(uuid: string): Promise<void> {
	const res = await api.post(`${FLOW_TASKS_BASE}/${uuid}/complete`, {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: {},
	})
	expect(
		res.ok(),
		`complete ${uuid} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()

	const stored = await api.get(`${FLOW_TASKS_BASE}/${uuid}`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(stored.ok(), `read back ${uuid} -> ${stored.status()}`).toBeTruthy()
	const body = await stored.json()
	expect(String((body?.results ?? body)?.state), `${uuid} after complete`).toBe(
		'completed',
	)
}

/**
 * The tasks one status put on one case, read from the ENGINE.
 *
 * 🔴 IT USED TO LIST `caseTask` OBJECTS AND THE TRANSITION STOPPED WRITING
 * THEM. dossiq#2363 made the engine the only store, so this search answered
 * `[]` on a case whose transition had just created two tasks. An empty list is
 * how a fixture aimed at the wrong table reports itself, and it reads as "the
 * checklist did nothing", which is a far more alarming thing than what
 * happened.
 *
 * Two field names change with the table: `case` becomes `objectUuid`, and
 * `status` becomes `state`, so every caller reads `state`. `workflowStepId`
 * keeps its name, mapped straight through by
 * `EngineTaskGateway::toEnginePayload()`, but the inbox route declares no
 * filter for it (openregister's `TaskController::index`), so it is filtered in
 * the READING rather than asked of the server.
 *
 * `scope=all` because the question is what work the CASE has. The default
 * scope is `assigned`, and a checklist task whose case names no handler is
 * assigned to nobody, so the default would answer `[]` for a case that has
 * two.
 *
 * @param onCase       The case.
 * @param workflowStep The statusType that asked for them.
 */
async function tasksOf(onCase: string, workflowStep: string): Promise<any[]> {
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

	const rows: any[] = (await res.json())?.results ?? []
	return rows.filter((row) => String(row.workflowStepId ?? '') === workflowStep)
}

/**
 * Cancel every engine task standing on the cases this run seeded.
 *
 * 🔴 THE ENGINE PUBLISHES NO DELETE. `cleanupRunObjects` cannot see these
 * however the prefix is spelled: they are not OpenRegister objects, so no
 * schema sweep reaches them, and `cancel` is the only removal verb the engine
 * has, and it terminates rather than erases. Left alone they would outlive their
 * cases and keep answering instance-wide counts on the shared instance.
 *
 * Failures are swallowed on purpose: a task a test already completed answers a
 * conflict to a cancel, and a teardown that threw on that would redden a run
 * whose assertions all passed.
 */
async function cancelEngineTasks(): Promise<void> {
	for (const caseId of Object.values(cases)) {
		try {
			const query = new URLSearchParams({
				objectUuid: caseId,
				scope: 'all',
				limit: '200',
			})
			const res = await api.get(`${FLOW_TASKS_BASE}?${query.toString()}`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			const rows: any[] = (await res.json())?.results ?? []
			for (const row of rows) {
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
			// Teardown is best effort; the next run's residue sweep is the
			// backstop.
		}
	}
}

/**
 * Open a case page and wait for the transition strip to have answered.
 *
 * @param page The Playwright page.
 * @param id   The case to open.
 */
async function openCase(page: Page, id: string): Promise<void> {
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await dismissSupportDialog(page)
	await expect(page.getByTestId('case-transitions')).toBeVisible({
		timeout: 30_000,
	})
	await expect(page.getByTestId('case-current-status')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Open a case's Tasks tab and return the open panel.
 *
 * `case-tasks` is a CHILD of the `case-panels` tabs widget, so the widget id
 * is not an aria-label anywhere: the open panel inside the strip is the
 * handle, and the panels are lazy — nothing queries until the tab is opened.
 *
 * @param page The Playwright page.
 * @param id   The case to open.
 */
async function openTasksTab(page: Page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	// Tasks is the first SECTION of the Work tab since the strip came down
	// from fourteen tabs to six; Appointments is the second.
	await strip.getByRole('tab', { name: 'Work', exact: true }).click()

	const panel = strip.locator('[role="tabpanel"]:not([hidden])')
	await expect(panel.locator('[data-testid="case-task-pane"]')).toBeVisible({
		timeout: 20_000,
	})
	return panel
}

test.describe('A status brings its checklist with it', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// `OCS-APIRequest` is not optional: without it Nextcloud's CSRF guard
		// answers a plain OCS GET with 412, which reads as "no session" rather
		// than as a missing header.
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// ── the case type whose WORKING phase asks for the work ──────────────
		arrival.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Checklist arrival`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cps-arrival`,
				description: 'Throwaway caseType for the checklist-per-status e2e.',
			}),
		)
		// The intake carries ONE OPTIONAL item, which is what makes it usable
		// for the "an optional item does not hold the case" scenario without
		// blocking the three that move out of it.
		arrival.intake = await seedStatus(arrival.caseType, 'Intake', 1, [
			{ title: ITEM.receipt, required: false },
		])
		arrival.progress = await seedStatus(arrival.caseType, 'In behandeling', 2, [
			{ title: ITEM.dossier, required: false },
			{ title: ITEM.inform, required: false },
		])
		await updateObject(api, token, 'caseType', arrival.caseType, {
			initialStatus: arrival.intake,
		})
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Checklist arrival workflow`,
			caseType: arrival.caseType,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: T.arrivalStart,
					label: `${RUN_PREFIX} Start behandeling`,
					fromStatus: arrival.intake,
					toStatus: arrival.progress,
					guards: [],
				},
				{
					id: T.arrivalBack,
					label: `${RUN_PREFIX} Terug naar intake`,
					fromStatus: arrival.progress,
					toStatus: arrival.intake,
					guards: [],
				},
			]),
		})

		// ── the case type whose INTAKE holds the case ────────────────────────
		guarded.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Checklist guard`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cps-guard`,
				description: 'Throwaway caseType for the checklist-per-status e2e.',
			}),
		)
		guarded.intake = await seedStatus(guarded.caseType, 'Intake', 1, [
			{ title: ITEM.onTime, required: true },
			{ title: ITEM.receipt, required: false },
		])
		guarded.progress = await seedStatus(
			guarded.caseType,
			'In behandeling',
			2,
			[],
		)
		await updateObject(api, token, 'caseType', guarded.caseType, {
			initialStatus: guarded.intake,
		})
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Checklist guard workflow`,
			caseType: guarded.caseType,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: T.guardStart,
					label: `${RUN_PREFIX} Start behandeling`,
					fromStatus: guarded.intake,
					toStatus: guarded.progress,
					guards: [],
				},
			]),
		})

		const seed = async (key: string, caseType: string, status: string) => {
			cases[key] = objectId(
				await seedCase(api, token, {
					title: `${RUN_PREFIX} ${key}`,
					caseType,
					status,
				}),
			)
		}
		await seed('arrive', arrival.caseType, arrival.intake)
		await seed('freeform', arrival.caseType, arrival.intake)
		await seed('roundtrip', arrival.caseType, arrival.intake)
		await seed('optional', arrival.caseType, arrival.intake)
		await seed('held', guarded.caseType, guarded.intake)
		await seed('freed', guarded.caseType, guarded.intake)
		await seed('refused', guarded.caseType, guarded.intake)
	})

	test.afterAll(async () => {
		// The engine tasks FIRST: they are addressed by their case, and
		// `cleanupRunObjects` is about to remove the cases.
		await cancelEngineTasks()
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#the-tasks-arrive-with-the-status
	test('the tasks arrive with the status, and show in the Tasks pane', async ({
		page,
	}) => {
		const before = await tasksOf(cases.arrive, arrival.progress)
		expect(before, 'the case starts with no tasks for that status').toEqual([])

		const moved = await executeTransition(
			api,
			token,
			cases.arrive,
			T.arrivalStart,
		)
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)

		const after = await tasksOf(cases.arrive, arrival.progress)
		expect(after.map((row) => String(row.title)).sort()).toEqual(
			[ITEM.dossier, ITEM.inform].sort(),
		)
		// `state`, not `status`: the engine's column. Reading `status` here
		// would compare undefined to 'available' and fail on a correct row.
		for (const row of after) {
			expect(String(row.state), String(row.title)).toBe('available')
		}

		// And the handler sees them where the work is done.
		const panel = await openTasksTab(page, cases.arrive)
		await expect(panel).toContainText(ITEM.dossier, { timeout: 20_000 })
		await expect(panel).toContainText(ITEM.inform, { timeout: 20_000 })
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#an-admins-free-form-move-brings-them-too
	test("an admin's free-form move brings them too", async () => {
		const res = await api.post(
			`/index.php/apps/dossiq/api/case/${cases.freeform}/transition-freeform`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: { toStatusId: arrival.progress },
			},
		)
		expect(res.status(), await res.text()).toBe(200)

		const moved = await showObject(api, 'case', cases.freeform)
		expect(String(moved.status)).toBe(arrival.progress)

		const tasks = await tasksOf(cases.freeform, arrival.progress)
		expect(tasks.map((row) => String(row.title)).sort()).toEqual(
			[ITEM.dossier, ITEM.inform].sort(),
		)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#back-to-intake-and-forward-again
	test('back to intake and forward again keeps one set of tasks', async () => {
		const first = await executeTransition(
			api,
			token,
			cases.roundtrip,
			T.arrivalStart,
		)
		expect(first.status, JSON.stringify(first.body)).toBe(200)

		const created = await tasksOf(cases.roundtrip, arrival.progress)
		expect(created).toHaveLength(2)
		const done = created.find((row) => String(row.title) === ITEM.dossier)
		// `uuid`, not `objectId()`: an engine row carries no `@self`, and the
		// verb routes take the uuid.
		await completeEngineTask(String(done.uuid))

		const back = await executeTransition(
			api,
			token,
			cases.roundtrip,
			T.arrivalBack,
		)
		expect(back.status, JSON.stringify(back.body)).toBe(200)
		const forward = await executeTransition(
			api,
			token,
			cases.roundtrip,
			T.arrivalStart,
		)
		expect(forward.status, JSON.stringify(forward.body)).toBe(200)

		// This was KNOWN TO FAIL and is not any more, and the assertion never
		// moved. The de-duplication reads `StatusChecklist::existingTitles()`,
		// which searched the `caseTask` register objects the transition
		// stopped writing at dossiq#2363: it saw no previous visit, re-created
		// both items, and this answered four. Weakening it to four would have
		// recorded the defect as the design, so it was left naming what a
		// second entry must do. dossiq#2405 moved the reader onto
		// `EngineTaskInbox::forCase()` and the two now answer two.
		const after = await tasksOf(cases.roundtrip, arrival.progress)
		expect(after, 'a second entry must not double the work').toHaveLength(2)
		const stillDone = after.find((row) => String(row.title) === ITEM.dossier)
		expect(String(stillDone.state)).toBe('completed')
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#the-button-says-which-item-is-open
	test('the button says which item is still open', async ({ page }) => {
		await seedTask(cases.held, ITEM.onTime, guarded.intake)

		const offered = await getAvailableTransitions(api, token, cases.held)
		expect(offered.status).toBe(200)
		const held = offered.body.transitions.find(
			(row: any) => String(row.id) === T.guardStart,
		)
		expect(held, 'the transition is offered, not hidden').toBeTruthy()
		expect(held.guardsPassed).toBe(false)
		expect(JSON.stringify(held.failedGuards)).toContain('statusChecklist')

		await openCase(page, cases.held)
		const button = page.getByTestId(`case-transition-${T.guardStart}`)
		await expect(button).toBeVisible({ timeout: 20_000 })
		await expect(button).toBeDisabled()

		// The reason names the item, in whichever language the instance runs.
		const reason = page.getByTestId(`case-transition-reason-${T.guardStart}`)
		await expect(reason).toContainText(ITEM.onTime, { timeout: 20_000 })
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#completing-the-task-frees-the-case
	test('completing the task frees the case', async ({ page }) => {
		const taskId = await seedTask(cases.freed, ITEM.onTime, guarded.intake)

		const before = await getAvailableTransitions(api, token, cases.freed)
		expect(
			before.body.transitions.find((row: any) => row.id === T.guardStart)
				.guardsPassed,
		).toBe(false)

		await completeEngineTask(taskId)

		const after = await getAvailableTransitions(api, token, cases.freed)
		expect(
			after.body.transitions.find((row: any) => row.id === T.guardStart)
				.guardsPassed,
		).toBe(true)

		await openCase(page, cases.freed)
		await expect(
			page.getByTestId(`case-transition-${T.guardStart}`),
		).toBeEnabled({ timeout: 20_000 })

		const moved = await executeTransition(api, token, cases.freed, T.guardStart)
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)
		expect(String((await showObject(api, 'case', cases.freed)).status)).toBe(
			guarded.progress,
		)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#an-optional-item-does-not-hold-the-case
	test('an optional item does not hold the case', async ({ page }) => {
		await seedTask(cases.optional, ITEM.receipt, arrival.intake)

		const offered = await getAvailableTransitions(api, token, cases.optional)
		expect(
			offered.body.transitions.find((row: any) => row.id === T.arrivalStart)
				.guardsPassed,
		).toBe(true)

		await openCase(page, cases.optional)
		await expect(
			page.getByTestId(`case-transition-${T.arrivalStart}`),
		).toBeEnabled({ timeout: 20_000 })
		await expect(
			page.getByTestId(`case-transition-reason-${T.arrivalStart}`),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#the-guard-fails-server-side-too
	test('the guard refuses the move posted straight to the API', async () => {
		await seedTask(cases.refused, ITEM.onTime, guarded.intake)

		const refused = await executeTransition(
			api,
			token,
			cases.refused,
			T.guardStart,
		)
		expect(refused.status, JSON.stringify(refused.body)).toBe(409)
		expect(JSON.stringify(refused.body)).toContain('statusChecklist')

		const unmoved = await showObject(api, 'case', cases.refused)
		expect(String(unmoved.status)).toBe(guarded.intake)
	})
})
