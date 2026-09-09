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
 * buttons at all and looks exactly like a task with no transitions left
 * (`available-actions` answers an empty list for a case, which is the defect
 * this change deliberately does not try to solve). A pane that filters open
 * tasks client-side over a paged window shows an empty pane on a case with
 * work left. A completion that navigates away confirms nothing. So the
 * assertions are: the buttons, the toast text, the URL after the press, and
 * the identity of the task that took the completed one's place.
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
 * pane's own copy are matched in either language the app ships. The lifecycle
 * BUTTON labels are the schema's transition descriptions, which OpenRegister
 * returns verbatim from `lib/Settings/dossiq_register.json` and which are
 * English on every instance. Everything else is matched on a `data-testid`
 * or on data this spec seeded.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/**
 * The transition descriptions `caseTask`'s lifecycle declares, which is what
 * `available-actions` hands CnLifecycleActions as button labels. The trailing
 * full stop is part of the schema text, so the patterns stop short of it.
 */
const COMPLETE_LABEL = /Mark task as completed/
const TERMINATE_LABEL = /Terminate the task/
const DISABLE_LABEL = /Disable the task/
const ACTIVATE_LABEL = /Pick up the task/

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

/** The case the pane is READ on: an active task and a later open one. */
let readCaseId = ''
let readFirstTitle = ''
let readSecondTitle = ''

/** A second case of the same shape, whose first task this spec completes. */
let completeCaseId = ''
let completeFirstTitle = ''
let completeSecondTitle = ''

/** A case with exactly one open task, which is completed to empty the pane. */
let lastCaseId = ''
let lastTaskTitle = ''

/** A case whose task is opened on its own page, to follow the link back. */
let linkCaseId = ''
let linkCaseTitle = ''
let linkTaskId = ''

/**
 * Seed one task on a case.
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
 * Drive a task through OpenRegister's lifecycle route, the same endpoint the
 * pane's buttons post to, and assert the new status was actually written.
 *
 * Seeding `status: "active"` directly would be a claim about what the
 * importer does with a lifecycle-managed field rather than a fact; this both
 * seeds the state and proves the lifecycle is live before any UI is opened.
 *
 * @param taskId The task to transition.
 * @param action The transition action key.
 * @param to     The status the task must hold afterwards.
 */
async function transitionTask(
	taskId: string,
	action: string,
	to: string,
): Promise<void> {
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
		`transition ${action} on ${taskId} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()

	const stored = await showObject(api, 'caseTask', taskId)
	expect(String(stored.status), `${taskId} after ${action}`).toBe(to)
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
			seedCase(api, token, { title: linkCaseTitle, caseType: caseTypeId }),
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

		const readFirst = await seedTask(readCaseId, readFirstTitle, EARLIER_DUE)
		await seedTask(readCaseId, readSecondTitle, LATER_DUE)
		const completeFirst = await seedTask(
			completeCaseId,
			completeFirstTitle,
			EARLIER_DUE,
		)
		await seedTask(completeCaseId, completeSecondTitle, LATER_DUE)
		const lastTask = await seedTask(lastCaseId, lastTaskTitle, EARLIER_DUE)
		linkTaskId = await seedTask(
			linkCaseId,
			`${RUN_PREFIX} link task`,
			EARLIER_DUE,
		)

		// The first task of each pair is picked up, so `available-actions`
		// answers `complete` for it. The second stays available on purpose:
		// what the pane offers has to follow the task's own status.
		await transitionTask(readFirst, 'activate', 'active')
		await transitionTask(completeFirst, 'activate', 'active')
		await transitionTask(lastTask, 'activate', 'active')
	})

	test.afterAll(async () => {
		if (!api) return
		// The tasks. The cases are archival and cannot be removed by a user;
		// they carry the family prefix, so global-setup's residue sweep takes
		// them before the next run rather than this teardown failing on a 403
		// it was never going to win.
		await cleanupRunObjects(api, token, ['caseTask'])
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

		// The buttons OpenRegister answers for an ACTIVE task. Their presence
		// is the whole change: they render on the case page, not two
		// navigations away, and they are bound to the task rather than to the
		// case (a pane wired to the case id renders none of them, because
		// `available-actions` answers an empty list for a case).
		const actions = panel.locator('[data-testid="cn-lifecycle-actions"]')
		await expect(actions).toBeVisible({ timeout: 20_000 })
		await expect(
			actions.getByRole('button', { name: COMPLETE_LABEL }),
		).toBeVisible()
		await expect(
			actions.getByRole('button', { name: TERMINATE_LABEL }),
		).toBeVisible()
		await expect(
			actions.getByRole('button', { name: DISABLE_LABEL }),
		).toBeVisible()

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
		await panel.getByRole('button', { name: COMPLETE_LABEL }).click()

		// The confirmation names the task that was finished. Without it the
		// press is indistinguishable from a press that did nothing.
		await expect(page.locator(SUCCESS_TOAST)).toContainText(completeFirstTitle, {
			timeout: 30_000,
		})

		// Still on the case. A completion that navigated to the task page
		// would satisfy every other assertion here and defeat the point.
		expect(new URL(page.url()).pathname).toBe(before)

		// The next open task takes its place, with the buttons ITS status
		// allows: it was never picked up, so it offers Pick up rather than
		// Mark as completed.
		await expect(
			panel.locator('[data-testid="case-task-pane-title"]'),
		).toHaveText(completeSecondTitle, { timeout: 30_000 })
		await expect(
			panel.getByRole('button', { name: ACTIVATE_LABEL }),
		).toBeVisible({ timeout: 20_000 })
		await expect(
			panel.getByRole('button', { name: COMPLETE_LABEL }),
		).toHaveCount(0)

		// The write reached the server, not just the screen.
		const stored = await listObjects(api, 'caseTask', { _limit: '200' })
		const completed = stored.find(
			(row) => String(row.title ?? '') === completeFirstTitle,
		)
		expect(String(completed?.status)).toBe('completed')
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

		await panel.getByRole('button', { name: COMPLETE_LABEL }).click()
		await expect(page.locator(SUCCESS_TOAST)).toContainText(lastTaskTitle, {
			timeout: 30_000,
		})

		// A case with nothing open says so, rather than showing the task it
		// just finished or an empty box with no explanation.
		await expect(
			panel.locator('[data-testid="case-task-pane-empty"]'),
		).toHaveText(EMPTY_PANE, { timeout: 30_000 })

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
})
