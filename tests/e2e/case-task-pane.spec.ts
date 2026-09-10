/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finishing a task without leaving the case (task-on-the-case).
 *
 * The Tasks tab on the case page used to be a five-column list that routed
 * every row to TaskDetail. It now shows the first OPEN task with the
 * lifecycle buttons OpenRegister answers for it, confirms a completion with
 * a toast, and puts the next open task in its place.
 *
 * WHAT THIS SPEC IS FOR
 * ---------------------
 * Every promise here is a sequence rather than a render, and each half fails
 * in a way that looks fine on its own. A pane bound to the CASE id renders no
 * task at all and looks exactly like a case whose work is finished. A pane
 * that filters open tasks client-side over a paged window shows an empty pane
 * on a case with work left. A completion that navigates away confirms
 * nothing. So the assertions are: the buttons, the toast text, the URL after
 * the press, and the identity of the task that took the completed one's
 * place.
 *
 * WHICH TASK STORE THIS SEEDS
 * ---------------------------
 * Two of them, on purpose. dossiq's task surfaces are mid-migration from the
 * `caseTask` register object onto OpenRegister's task ENGINE, which is a
 * separate entity behind `/api/flow-tasks` with its own table and its own
 * lifecycle verbs. The pane has moved, so the three cases it is read on are
 * seeded through `seedEngineTask`. The task DETAIL page has not, so the case
 * whose task is opened on its own page is seeded through `seedTask`, which
 * still writes a register object. Seeding either one into the other's surface
 * produces an empty screen and no error anywhere.
 *
 * ADDRESSING THE WIDGET
 * ---------------------
 * `case-tasks` is a CHILD of the `case-panels` tabs widget, and CnDetailPage
 * sets `aria-label` to the manifest widget id only on the TOP-LEVEL widgets
 * it lays out. `[aria-label="case-tasks"]` therefore matches nothing, which
 * is what cost the sibling Communication spec four of its five tests (#1905)
 * against a tab that rendered correctly. The open panel inside the strip is
 * the handle, scoped to the strip because the sidebar's own panels also carry
 * `role="tabpanel"` and hide with `aria-hidden` rather than `hidden`.
 *
 * The panels are also LAZY: a child does not mount, and therefore does not
 * query, until its tab is opened.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance, so tab labels and the
 * pane's own copy are matched in either language the app ships. The verb
 * buttons are no longer an exception: they used to carry the schema's
 * transition descriptions, which OpenRegister returned verbatim and which
 * were English on every instance, and they now carry translated labels, so
 * they are addressed by `data-testid` like everything else here that is not
 * data this spec seeded.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/** OpenRegister's task engine, the collection the pane reads and writes. */
const FLOW_TASKS = '/index.php/apps/openregister/api/flow-tasks'

/**
 * The pane's verb buttons, addressed by `data-testid` and not by label.
 *
 * They used to be the transition descriptions `caseTask`'s lifecycle declared,
 * which OpenRegister returned verbatim from `available-actions` and which
 * CnLifecycleActions rendered as button text. The pane no longer asks that
 * endpoint at all: an engine task is not an OpenRegister object, so
 * `/api/objects/{uuid}/available-actions` answers 500 for one. CaseTaskPane
 * renders a fixed pair of engine verbs instead and lets the engine rule on
 * each press (`verbs()` in src/components/tasks/CaseTaskPane.vue), and it
 * labels them through `t('dossiq', ...)`, so a label match would depend on the
 * instance locale that this spec's header explains is not forced. The testids
 * do not, which is the reason everything else here is matched on one.
 */
const COMPLETE_BUTTON = '[data-testid="case-task-pane-verb-complete"]'
const CANCEL_BUTTON = '[data-testid="case-task-pane-verb-cancel"]'

/** The pane's own empty state, in either language the app ships. */
const EMPTY_PANE = /No open tasks on this case|Geen open taken op deze zaak/

/**
 * The success toast, in either markup @nextcloud/dialogs may render.
 *
 * NOT `.toast-success`. That class belongs to the Toastify markup dialogs
 * used before v7; the instance ships 7.5.0, which renders the toast from a
 * CSS-modules stylesheet as a `role="status"` div classed
 * `_toast_<hash> _toast_success_<hash>`. The old selector matched nothing, so
 * a toast that WAS on screen read as a completion the app never made. The hash
 * moves with every dialogs build, so match the module-local name as a
 * substring and keep the pre-7 class beside it: whichever version is
 * installed, one of the two hits, and neither can match an ERROR toast.
 */
const SUCCESS_TOAST = '.toast-success, [role="status"][class*="_toast_success_"]'

const EARLIER_DUE = '2026-09-10T09:00:00+00:00'
const LATER_DUE = '2026-09-24T09:00:00+00:00'

let api: APIRequestContext
let token: string
let caseTypeId = ''
let currentUser = ''

/** The case the pane is READ on: an open task and a later open one. */
let readCaseId = ''
let readFirstTitle = ''
let readSecondTitle = ''

/** A second case of the same shape, whose first task this spec completes. */
let completeCaseId = ''
let completeFirstTitle = ''
let completeSecondTitle = ''
/** The engine uuid of that first task, read back to prove the write landed. */
let completeFirstId = ''

/** A case with exactly one open task, which is completed to empty the pane. */
let lastCaseId = ''
let lastTaskTitle = ''
let lastTaskId = ''

/** A case whose task is opened on its own page, to follow the link back. */
let linkCaseId = ''
let linkCaseTitle = ''
let linkTaskId = ''
let linkCaseTypeTitle = ''
/** The handler and deadline the link case is seeded with, asserted on the card. */
const LINK_CASE_HANDLER = 'admin'
const LINK_CASE_DEADLINE = '2026-11-02'

/** Every engine task this run created, so teardown can close them. */
const seededEngineTasks: string[] = []

/**
 * Seed one task on a case, as a `caseTask` REGISTER OBJECT.
 *
 * Kept for the task DETAIL page only. `TaskDetail` (`/tasks/:id`) is still a
 * `type: "detail"` page bound to `register: dossiq, schema: caseTask`, because
 * `entitySource` has no detail-page equivalent, and moving it is task 2.1 of
 * openspec/changes/remove-casetask/tasks.md, which is still open. Every
 * surface that HAS moved is seeded by `seedEngineTask` below.
 *
 * @param onCase The case the task belongs to.
 * @param title  The task title (carries RUN_PREFIX so teardown finds it).
 * @param dueDate The due date, which is what orders the pane.
 */
async function seedTask(
	onCase: string,
	title: string,
	dueDate: string,
): Promise<string> {
	const created = await createObject(api, token, 'caseTask', {
		title,
		case: onCase,
		assignee: currentUser,
		status: 'available',
		dueDate,
	})
	return objectId(created)
}

/**
 * Seed one task on a case, in OpenRegister's TASK ENGINE.
 *
 * WHY THIS IS NOT `createObject(api, token, 'caseTask', ...)`
 * -----------------------------------------------------------
 * A `caseTask` register object and an engine task are DIFFERENT ENTITIES: one
 * is a row in the dossiq register behind `/api/objects/dossiq/caseTask`, the
 * other is a first-class record in the engine's own table behind
 * `/api/flow-tasks`, with its own lifecycle verbs. dossiq's six task read
 * surfaces moved to the engine (openspec/changes/remove-casetask/tasks.md,
 * section 1), and CaseTaskPane is the first of them, so the pane lists engine
 * tasks and can never see a register row. Seeding one left the pane correctly
 * empty, which on screen is indistinguishable from a pane that is broken.
 *
 * THE PAYLOAD
 * -----------
 * The names are the engine's, and the map from the dossiq shape is the one
 * both halves of the app already use: `EngineTaskGateway::toEnginePayload()`
 * server-side and `useEngineTaskStore().create()` in the browser. `case`
 * becomes `objectUuid`, because the case IS the anchor object and the engine
 * holds no typed case reference; `dueDate` becomes `dueAt`; and `status`
 * becomes `state` over the SAME CMMN vocabulary, translated by nothing.
 *
 * `requester` is named on purpose, and is the one field neither of those maps
 * sends. `TaskService::create()` writes the acting identity into it only for a
 * non-administrator caller, so on an admin session it would stay null, and
 * `cancel` asserts the requester. Naming it keeps teardown working whichever
 * kind of account the suite runs as.
 *
 * @param onCase The case the task is anchored to.
 * @param title  The task title (carries RUN_PREFIX so teardown finds it).
 * @param dueAt  The due date, which is what orders the pane.
 */
async function seedEngineTask(
	onCase: string,
	title: string,
	dueAt: string,
): Promise<string> {
	const res = await api.post(FLOW_TASKS, {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: {
			title,
			appId: 'dossiq',
			state: 'available',
			objectUuid: onCase,
			assignee: currentUser,
			requester: currentUser,
			dueAt,
		},
	})
	expect(
		res.ok(),
		`creating the engine task ${title} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()

	const created = (await res.json()) as Record<string, unknown>
	const uuid = String(created?.uuid ?? '')
	expect(uuid, `the engine must answer ${title} with a uuid`).not.toBe('')
	seededEngineTasks.push(uuid)
	return uuid
}

/**
 * Read one engine task back, as the row the API serves.
 *
 * The `uuid`, not the numeric `id`: the engine is addressed by uuid on every
 * route and verb, and its `id` is a database primary key nothing accepts.
 *
 * @param uuid The engine task uuid.
 */
async function showEngineTask(uuid: string): Promise<Record<string, unknown>> {
	const res = await api.get(`${FLOW_TASKS}/${uuid}`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(
		res.ok(),
		`reading engine task ${uuid} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return (await res.json()) as Record<string, unknown>
}

/**
 * Open a case and switch to its Tasks tab, returning the open panel.
 *
 * @param page The Playwright page.
 * @param id   The case id to open.
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

	// See the header: the widget id is NOT on a tab child, so the open panel
	// inside the strip is the handle.
	const panel = strip.locator('[role="tabpanel"]:not([hidden])')
	await expect(panel).toBeVisible({ timeout: 20_000 })
	await expect(panel.locator('[data-testid="case-task-pane"]')).toBeVisible({
		timeout: 20_000,
	})
	return panel
}

test.describe('Case detail — the task pane', () => {
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
		// than as a missing header. case-parties.spec.ts sends the same header
		// against the same endpoint.
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// REUSE a seeded case type. The `case` schema is archival, so a case
		// cannot be deleted by a user; creating a case type here and removing
		// it in teardown would leave every case pointing at a type that is
		// gone, which reddens unrelated specs.
		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type — adoptableCaseTypes() excludes drafts (isDraft !== false) and fixture-owned rows',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])
		// The card resolves `case.caseType` to this TITLE. Read it off the same
		// row the case is seeded against, so the assertion cannot drift from
		// whichever published type the instance happens to ship.
		linkCaseTypeTitle = String(
			(caseTypes[0] as any)?.title
				?? (caseTypes[0] as any)?.['@self']?.title
				?? '',
		).trim()

		// FOUR cases rather than one. Two of these tests COMPLETE a task, so
		// sharing a case would make every test after them depend on the order
		// they ran in — and an order dependency is the failure that reproduces
		// only on the second run.
		linkCaseTitle = `${RUN_PREFIX} Task pane link`
		const [readCase, completeCase, lastCase, linkCase] = await Promise.all([
			seedCase(api, token, {
				title: `${RUN_PREFIX} Task pane read`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Task pane complete`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Task pane last`,
				caseType: caseTypeId,
			}),
			// Seeded WITH a handler and a deadline: the card carries both, and a
			// case without them renders neither row, so a bare case could not tell
			// a working card from a broken one.
			seedCase(api, token, {
				title: linkCaseTitle,
				caseType: caseTypeId,
				assignee: LINK_CASE_HANDLER,
				deadline: LINK_CASE_DEADLINE,
			}),
		])
		readCaseId = objectId(readCase)
		completeCaseId = objectId(completeCase)
		lastCaseId = objectId(lastCase)
		linkCaseId = objectId(linkCase)

		readFirstTitle = `${RUN_PREFIX} read first task`
		readSecondTitle = `${RUN_PREFIX} read second task`
		completeFirstTitle = `${RUN_PREFIX} complete first task`
		completeSecondTitle = `${RUN_PREFIX} complete second task`
		lastTaskTitle = `${RUN_PREFIX} last open task`

		// The three cases the PANE is read on carry engine tasks, because that
		// is what the pane lists. No task here is picked up first: the engine
		// admits `complete` on any task that is not already terminal
		// (`TaskService::openTaskFor()` checks authorization and terminality
		// and nothing else), and the pane offers its verbs without asking
		// which are available, so an intermediate claim would prove nothing
		// these three tests assert.
		await seedEngineTask(readCaseId, readFirstTitle, EARLIER_DUE)
		await seedEngineTask(readCaseId, readSecondTitle, LATER_DUE)
		completeFirstId = await seedEngineTask(
			completeCaseId,
			completeFirstTitle,
			EARLIER_DUE,
		)
		await seedEngineTask(completeCaseId, completeSecondTitle, LATER_DUE)
		lastTaskId = await seedEngineTask(lastCaseId, lastTaskTitle, EARLIER_DUE)

		// The link case keeps a REGISTER task: its two tests open the task's
		// own detail page, which has not moved off `caseTask` yet.
		linkTaskId = await seedTask(
			linkCaseId,
			`${RUN_PREFIX} link task`,
			EARLIER_DUE,
		)
	})

	test.afterAll(async () => {
		if (!api) return
		// The register tasks. The cases are archival and cannot be removed by
		// a user; they carry the family prefix, so global-setup's residue
		// sweep takes them before the next run rather than this teardown
		// failing on a 403 it was never going to win.
		await cleanupRunObjects(api, token, ['caseTask'])

		// 🔴 THE ENGINE TASKS ARE CLOSED, NOT REMOVED, AND THIS IS A LEAK.
		// OpenRegister exposes no delete for an engine task: `appinfo/routes.php`
		// registers index / show / audit and the lifecycle verbs and no DELETE,
		// there is no `occ` command for one either, and the archival purge
		// route the case fixtures use only knows OpenRegister OBJECTS. So the
		// closest teardown available is `cancel`, which puts the row in
		// `terminated` and therefore out of every open-task surface, and leaves
		// it in the table for good. Each row carries RUN_PREFIX in its title,
		// so a sweep can find them once the engine grows a way to remove one.
		//
		// Best effort per task, and never fatal: a task a test already
		// completed answers 409 (`cancel` is refused on a terminal task), which
		// is the expected outcome for two of the five, not a teardown failure.
		for (const uuid of seededEngineTasks) {
			await api
				.post(`${FLOW_TASKS}/${uuid}/cancel`, {
					headers: {
						requesttoken: token,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					data: { reason: 'e2e teardown' },
				})
				.catch(() => undefined)
		}

		await api.dispose()
	})

	// @e2e openspec/specs/task-management/spec.md#the-open-task-shows-its-buttons-on-the-case
	// @e2e task-management::the-open-task-shows-its-buttons-on-the-case
	test('the open task shows its lifecycle buttons on the case, with the next one listed under it', async ({
		page,
	}) => {
		const panel = await openTasksTab(page, readCaseId)

		// The EARLIEST due open task is the one in the pane.
		await expect(
			panel.locator('[data-testid="case-task-pane-title"]'),
		).toHaveText(readFirstTitle, { timeout: 20_000 })

		// The engine verbs the pane offers on an open task. Their presence is
		// the whole change: they render on the case page, not two navigations
		// away, and they are bound to the TASK rather than to the case. A pane
		// wired to the case id renders none of them, because the engine lists
		// by `objectUuid` and a case has no tasks anchored to another case.
		const actions = panel.locator('[data-testid="case-task-pane-actions"]')
		await expect(actions).toBeVisible({ timeout: 20_000 })
		await expect(actions.locator(COMPLETE_BUTTON)).toBeVisible()
		await expect(actions.locator(CANCEL_BUTTON)).toBeVisible()

		// The second open task is listed under the pane, and it is NOT the one
		// carrying the buttons.
		const remaining = panel.locator('[data-testid="case-task-pane-remaining"]')
		await expect(remaining).toContainText(readSecondTitle, { timeout: 20_000 })
		await expect(remaining).not.toContainText(readFirstTitle)
	})

	// @e2e openspec/specs/task-management/spec.md#completing-the-task-confirms-and-shows-the-next-one
	// @e2e task-management::completing-the-task-confirms-and-shows-the-next-one
	test('completing the task confirms it by name, stays on the case and shows the next task', async ({
		page,
	}) => {
		const panel = await openTasksTab(page, completeCaseId)
		await expect(
			panel.locator('[data-testid="case-task-pane-title"]'),
		).toHaveText(completeFirstTitle, { timeout: 20_000 })

		const before = new URL(page.url()).pathname
		await panel.locator(COMPLETE_BUTTON).click()

		// The confirmation names the task that was finished. Without it the
		// press is indistinguishable from a press that did nothing.
		await expect(page.locator(SUCCESS_TOAST)).toContainText(completeFirstTitle, {
			timeout: 30_000,
		})

		// Still on the case. A completion that navigated to the task page
		// would satisfy every other assertion here and defeat the point.
		expect(new URL(page.url()).pathname).toBe(before)

		// The next open task takes its place, ready to be worked the same way.
		//
		// This used to assert that the second task offered Pick up and NOT
		// Mark as completed, because the register lifecycle answered a
		// per-status button set through `available-actions`. The engine model
		// is deliberately different: the pane offers its verbs and the engine
		// rules on the press, refusing visibly with a message that names both
		// the verb and the reason, rather than the client pre-judging
		// availability. Pre-judging in two places is the duplicated
		// authorization this migration exists to remove, so what is asserted
		// here is what the surface now promises: the completed task is gone
		// and the next one is in its place with its verbs.
		await expect(
			panel.locator('[data-testid="case-task-pane-title"]'),
		).toHaveText(completeSecondTitle, { timeout: 30_000 })
		await expect(panel.locator(COMPLETE_BUTTON)).toBeVisible({
			timeout: 20_000,
		})

		// The write reached the server, not just the screen. Addressed by the
		// uuid the seed returned rather than found by title in a page of rows:
		// the engine's list is paged, and a title search that falls off the
		// page reads as a completion that never happened.
		const stored = await showEngineTask(completeFirstId)
		expect(String(stored.state), `${completeFirstId} after complete`).toBe(
			'completed',
		)
		expect(stored.isTerminal, `${completeFirstId} after complete`).toBe(true)
	})

	// @e2e openspec/specs/task-management/spec.md#the-last-task-leaves-an-empty-pane
	// @e2e task-management::the-last-task-leaves-an-empty-pane
	test('the last task leaves the empty text, and View all shows it completed', async ({
		page,
	}) => {
		const panel = await openTasksTab(page, lastCaseId)
		await expect(
			panel.locator('[data-testid="case-task-pane-title"]'),
		).toHaveText(lastTaskTitle, { timeout: 20_000 })

		await panel.locator(COMPLETE_BUTTON).click()
		await expect(page.locator(SUCCESS_TOAST)).toContainText(lastTaskTitle, {
			timeout: 30_000,
		})

		// A case with nothing open says so, rather than showing the task it
		// just finished or an empty box with no explanation.
		await expect(
			panel.locator('[data-testid="case-task-pane-empty"]'),
		).toHaveText(EMPTY_PANE, { timeout: 30_000 })

		// The completion landed on the engine, not only on the screen.
		const stored = await showEngineTask(lastTaskId)
		expect(String(stored.state), `${lastTaskId} after complete`).toBe(
			'completed',
		)

		// 🔴 KNOWN RED BELOW, AND DELIBERATELY NOT WEAKENED.
		//
		// The pane reads the engine; the `Tasks` index this footer leads to is
		// still `type: "index"` over `register: dossiq, schema: caseTask` in
		// src/manifest.json. So the task completed above, which is an engine
		// task, cannot appear in that list at all, and these last three
		// assertions fail for a reason that has nothing to do with the pane.
		//
		// Moving the index is task 2.2 of
		// openspec/changes/remove-casetask/tasks.md (`entitySource: "tasks"`),
		// and it is BLOCKED on nextcloud-vue#1063 and openregister#3581
		// landing plus a dossiq nc-vue bump. Rewriting the assertion to
		// something the current pair of surfaces can satisfy would turn a
		// stated gap into a test that cannot fail, which is worse than a red
		// cell that names its cause.
		//
		// View all still leads to the Tasks list scoped to THIS case, which is
		// where a finished task remains readable.
		await panel.locator('[data-testid="case-task-pane-view-all"]').click()
		await expect
			.poll(() => new URL(page.url()).pathname, { timeout: 30_000 })
			.toMatch(/\/tasks$/)

		const row = page
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: lastTaskTitle })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row).toContainText(/completed|afgerond|voltooid/i)
	})

	// @e2e openspec/specs/task-management/spec.md#the-task-names-its-case-and-leads-back-to-it
	// @e2e task-management::the-task-names-its-case-and-leads-back-to-it
	test('the task page names its case and following the link opens the case', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/tasks/${linkTaskId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		// The case's TITLE, not its uuid: `caseTask.case` is a $ref and the
		// platform renders a $ref raw, which is why a data widget over the
		// field could not answer this.
		const link = page.locator('[data-testid="task-case-link-link"]')
		await expect(link).toBeVisible({ timeout: 20_000 })
		await expect(link).toHaveText(linkCaseTitle)

		await link.click()
		await expect
			.poll(() => new URL(page.url()).pathname, { timeout: 30_000 })
			.toContain(`/cases/${linkCaseId}`)
		await expect(page.locator('.cn-detail-page')).toContainText(linkCaseTitle, {
			timeout: 30_000,
		})
	})

	// @e2e openspec/specs/task-management/spec.md#the-task-names-its-case-and-leads-back-to-it
	// @e2e task-management::the-task-names-its-case-and-leads-back-to-it
	test('the case card carries the case identity, and the case is not repeated as a raw row', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/tasks/${linkTaskId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const card = page.locator('[data-testid="task-case-card"]')
		await expect(card).toBeVisible({ timeout: 20_000 })

		// The case's own reference, and its handler, both read off the CASE
		// rather than the task: the two have separate owners and separate
		// clocks, which is the whole reason for carrying them here.
		await expect(
			card.locator('[data-testid="task-case-card-identifier"]'),
		).toContainText(RUN_PREFIX)
		await expect(
			card.locator('[data-testid="task-case-card-handler"]'),
		).toHaveText(LINK_CASE_HANDLER)

		// `case.caseType` is a $ref. A uuid here is the defect the card
		// exists to prevent, so assert the resolved TITLE.
		if (linkCaseTypeTitle !== '') {
			await expect(
				card.locator('[data-testid="task-case-card-type"]'),
			).toHaveText(linkCaseTypeTitle)
		}

		// The deadline is the CASE's, formatted by the browser's locale, and
		// it is read back from the case rather than compared to the seed.
		// The seeded value does NOT survive: a case type with a statutory
		// term recalculates `deadline` on create, so asserting the seed
		// asserted this test's assumption instead of the app's behaviour.
		// Measured in CI: seeded 2026-11-02, rendered 11/5/2026.
		const seenCase = await showObject(api, 'case', linkCaseId)
		const actualDeadline = String(seenCase?.deadline ?? '').trim()
		if (actualDeadline !== '') {
			await expect(
				card.locator('[data-testid="task-case-card-deadline"]'),
			).toHaveText(new Date(actualDeadline).toLocaleDateString())
		}

		// And the Data widget below must NOT restate it. `case` is hidden by
		// a manifest override precisely because the platform would render the
		// $ref as its uuid, and one relationship shown twice, once correctly
		// and once as a uuid, reads as broken data.
		const data = page.locator('[data-testid="task-case-card"] >> nth=0')
		await expect(data).toBeVisible()
		await expect(
			page
				.locator('.cn-object-data-widget')
				.getByText('Case', { exact: true }),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/task-management/spec.md#the-task-names-its-case-and-leads-back-to-it
	// @e2e task-management::the-task-names-its-case-and-leads-back-to-it
	test('the task carries its own notes and appointments', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/tasks/${linkTaskId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		// Both are integration leaves on the TASK, not on the parent case.
		// `notes` is an always-available OpenRegister built-in, so it renders
		// unconditionally; `calendar` requires the NC Calendar app and renders
		// its own empty state without it, which is why the assertion is on the
		// widget being present rather than on any row inside it.
		await expect(
			page.getByRole('heading', { name: 'Notes', exact: true }),
		).toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByRole('heading', { name: 'Appointments', exact: true }),
		).toBeVisible({ timeout: 20_000 })
	})
})
