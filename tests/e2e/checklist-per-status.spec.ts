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
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

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
 * Seed one task as if a status had created it.
 *
 * The engine tags what it creates with `workflowStepId`, and both the
 * de-duplication and the guard read that tag. A task seeded without it is a
 * task neither of them can see.
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
	const row = await createObject(api, token, 'caseTask', {
		title,
		case: onCase,
		status: 'available',
		workflowStepId: workflowStep,
	})
	return objectId(row)
}

/**
 * Drive a task to `completed` through OpenRegister's lifecycle route.
 *
 * Writing `status` straight onto the object would be a claim about what the
 * store does with a lifecycle-managed field rather than a fact; this walks the
 * declared edges (available → active → completed) and reads the result back.
 *
 * @param taskId The task to complete.
 */
async function completeTask(taskId: string): Promise<void> {
	for (const action of ['activate', 'complete']) {
		const res = await api.post(
			`/index.php/apps/openregister/api/objects/${taskId}/transition`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: { action },
			},
		)
		expect(
			res.ok(),
			`${action} on ${taskId} -> ${res.status()} ${await res.text()}`,
		).toBeTruthy()
	}
	const stored = await showObject(api, 'caseTask', taskId)
	expect(String(stored.status), `${taskId} after complete`).toBe('completed')
}

/**
 * The tasks one status put on one case.
 *
 * @param onCase       The case.
 * @param workflowStep The statusType that asked for them.
 */
async function tasksOf(
	onCase: string,
	workflowStep: string,
): Promise<any[]> {
	const rows = await listObjects(api, 'caseTask', { case: onCase })
	return rows.filter(
		(row) => String(row.workflowStepId ?? '') === workflowStep,
	)
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
	await strip.getByRole('tab', { name: /^(Tasks|Taken)$/ }).click()

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
		for (const row of after) {
			expect(String(row.status), String(row.title)).toBe('available')
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
		await completeTask(objectId(done))

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

		const after = await tasksOf(cases.roundtrip, arrival.progress)
		expect(after, 'a second entry must not double the work').toHaveLength(2)
		const stillDone = after.find((row) => String(row.title) === ITEM.dossier)
		expect(String(stillDone.status)).toBe('completed')
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

		await completeTask(taskId)

		const after = await getAvailableTransitions(api, token, cases.freed)
		expect(
			after.body.transitions.find((row: any) => row.id === T.guardStart)
				.guardsPassed,
		).toBe(true)

		await openCase(page, cases.freed)
		await expect(
			page.getByTestId(`case-transition-${T.guardStart}`),
		).toBeEnabled({ timeout: 20_000 })

		const moved = await executeTransition(
			api,
			token,
			cases.freed,
			T.guardStart,
		)
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
