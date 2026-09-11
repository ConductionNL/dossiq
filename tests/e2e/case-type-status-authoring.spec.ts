/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a status is authored as, and what that authoring reaches
 * (case-type-authored-not-edited, REQ-CT-10; and REQ-CT-20's deadline).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * `tests/vitest/statusTypeForm.spec.js` pins the mapping between a stored
 * status and the form, and `StatusTypeLookupTest`, `SetStatusHandlerTest` and
 * `StatusChecklistTest` pin the readers on the server. Each half is green on
 * its own. What none of them can show is the CHAIN a functional administrator
 * relies on: that the Statuses tab actually writes the colour, the role and
 * the checklist it offers, and that what it wrote is then read by the thing
 * it is for. A role nobody's flow can find, or a checklist item that never
 * becomes a task, looks exactly like a working form until a case arrives.
 *
 * So each test drives the admin page for the part a person does, and then
 * asks the PRODUCT, not the form, whether it took:
 *
 *  - the role is read back by running a flow step that names only the role
 *    (`dossiq.setStatus` with `role: in-progress` and no status name), through
 *    OpenRegister's synchronous test-run endpoint;
 *  - the checklist is read back as a task in the engine, and in the case's
 *    Tasks pane, after the case enters the status;
 *  - the deadline is read back off a case filed through the New case dialog,
 *    not off the case type's blueprint, which is only the input to it.
 *
 * WHERE THE STATUSES TAB LIVES
 * ----------------------------
 * `StatusesTab` is mounted by `CaseTypeDetail` inside the Nextcloud admin
 * settings page (`/settings/admin/dossiq`), not by the in-app case type page:
 * that one renders the read-only blueprint widget. The admin page mounts
 * fourteen OpenRegister-backed sections and has been measured between ~7s and
 * 3.2 minutes under CI's `php -S`, which is why its load gets its own budget
 * and why the tests that use it carry a five-minute timeout.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance. Controls are found by
 * `data-testid`, by text this spec seeded (every name carries RUN_PREFIX), or
 * by an accessible name matched in both English and Dutch.
 *
 * WHY THE COLOUR IS ASSERTED AS A TOKEN, NOT A PIXEL
 * --------------------------------------------------
 * The row's swatch is painted `var(--nl-color-orange, #e17000)`, and which of
 * the two a browser resolves depends on whether the instance carries the NL
 * Design System theme. The token in the inline style says which colour the
 * code CHOSE, which is what this is about.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	executeTransition,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, tickCheckbox } from './helpers/nav.ts'

/** Where `StatusesTab` is mounted. See the header. */
const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

/** OpenRegister's flow store and its synchronous test-run endpoint. */
const FLOWS_BASE = '/index.php/apps/openregister/api/flows'
const FLOW_TEST_RUN = '/index.php/apps/openregister/api/flow-runs/test'

/** The engine's own task table. A flow task is not an OpenRegister object. */
const FLOW_TASKS_BASE = '/index.php/apps/openregister/api/flow-tasks'

/** The checklist item the scenario names, verbatim. */
const CHECK_IDENTITY = 'Check identity'

/** The transition that carries a case into the status that gets a checklist. */
const TO_REVIEW = 'csa-to-toetsing'

let api: APIRequestContext
let token = ''

/** The case type whose statuses the administrator edits. */
const authored = {
	caseType: '',
	title: `${RUN_PREFIX} Statusbeheer`,
	intake: '',
	progress: '',
	review: '',
	closed: '',
}

/** The parent that sets twelve weeks, and the child that sets six. */
const bezwaar = { parent: '', child: '', intake: '' }
const CHILD_TITLE = `${RUN_PREFIX} Bezwaar (verkort)`

/** One case per scenario, so no test reads another's writes. */
const cases: Record<string, string> = {}

/** Flows this run created. The engine has its own table and its own delete. */
const flows: string[] = []

/**
 * Headers for a CSRF-protected write through the session.
 *
 * @return The headers.
 */
function writeHeaders(): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * Escape a literal for use inside a RegExp.
 *
 * @param text The literal.
 * @return The escaped literal.
 */
function escapeRegExp(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Create one statusType of the authored case type.
 *
 * @param name  The status name, without the run prefix.
 * @param order Its position in the lifecycle.
 * @param extra isFinal and the like.
 * @return The new status's id.
 */
async function seedStatus(
	name: string,
	order: number,
	extra: Record<string, unknown> = {},
): Promise<string> {
	const row = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} ${name}`,
		caseType: authored.caseType,
		order,
		isFinal: false,
		...extra,
	})
	return objectId(row)
}

/**
 * Open the authored case type's Statuses tab on the admin settings page.
 *
 * The row is found by this run's own title and CLICKED, which is how an
 * administrator reaches it: `CaseTypeList` passes every case type to
 * `CnIndexPage` in one page, so no pagination stands between the two.
 *
 * @param page The Playwright page.
 * @return The case type admin section.
 */
async function openStatusesTab(page: Page): Promise<Locator> {
	await page.goto(ADMIN_SETTINGS_URL, { timeout: 180_000 })
	await dismissSupportDialog(page)

	const admin = page.locator('.case-type-admin')
	await expect(admin).toBeVisible({ timeout: 180_000 })

	const row = admin.getByRole('row').filter({ hasText: authored.title })
	await expect(
		row,
		`the case type list should offer ${authored.title}`,
	).toBeVisible({ timeout: 60_000 })
	await row.getByText(authored.title, { exact: true }).click()

	await admin.getByRole('button', { name: /^(Statuses|Statussen)$/ }).click()
	await expect(admin.locator('.statuses-tab__list')).toBeVisible({
		timeout: 30_000,
	})
	return admin
}

/**
 * The view-mode row of one status on the Statuses tab.
 *
 * @param admin The case type admin section.
 * @param name  The status name, without the run prefix.
 * @return The row.
 */
function statusRow(admin: Locator, name: string): Locator {
	return admin
		.locator('.status-type-row:not(.status-type-row--editing)')
		.filter({ hasText: `${RUN_PREFIX} ${name}` })
}

/**
 * Put one status in edit mode and return the edit form's row.
 *
 * The edit row is found by its CLASS rather than by the status's name: in
 * edit mode the name sits in an input's value, which `hasText` cannot see.
 *
 * @param admin The case type admin section.
 * @param name  The status name, without the run prefix.
 * @return The row in edit mode.
 */
async function startEditing(admin: Locator, name: string): Promise<Locator> {
	const full = escapeRegExp(`${RUN_PREFIX} ${name}`)
	await statusRow(admin, name)
		.getByRole('button', {
			name: new RegExp(`^(Edit ${full}|${full} bewerken)$`),
		})
		.click()

	const editing = admin.locator('.status-type-row--editing')
	await expect(editing).toHaveCount(1)
	return editing
}

/**
 * Pick one option in an NcSelect, by the combobox's label.
 *
 * @param page    The Playwright page.
 * @param scope   Where the select lives.
 * @param label   The select's accessible name.
 * @param option  The option's accessible name.
 */
async function pick(
	page: Page,
	scope: Locator,
	label: RegExp,
	option: RegExp,
): Promise<void> {
	await scope.getByRole('combobox', { name: label }).click()
	// By TEXT, not by accessible name. NcSelect's default option splits its
	// label into two spans for the ellipsis, and the accessible name joins
	// them with a space: "In progress" computes as something like
	// "In progr ess" and a name match never hits. The colour options render
	// through their own slot and would match either way; the role options do
	// not. textContent has no separator, so hasText sees what a reader sees.
	await page.getByRole('option').filter({ hasText: option }).click()
}

/**
 * Save the edit form and wait for the row to fall back to view mode.
 *
 * @param editing The row in edit mode.
 * @param admin   The case type admin section.
 */
async function save(editing: Locator, admin: Locator): Promise<void> {
	await editing.getByRole('button', { name: /^(Save|Opslaan)$/ }).click()
	// Back in view mode is what a successful save looks like. A refused save
	// keeps the form open with its error, so this wait fails naming that.
	await expect(admin.locator('.status-type-row--editing')).toHaveCount(0, {
		timeout: 30_000,
	})
}

/**
 * The tasks one status put on one case, read from the ENGINE.
 *
 * `workflowStepId` is filtered in the reading: the inbox route declares no
 * filter for it. `scope=all` because a checklist task on a case with no
 * handler is assigned to nobody, and the default scope would answer `[]`.
 *
 * @param onCase       The case.
 * @param workflowStep The statusType that asked for them.
 * @return The engine rows.
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
 * Cancel every engine task standing on this run's cases.
 *
 * The engine publishes no delete, and its rows are not OpenRegister objects,
 * so `cleanupRunObjects` cannot reach them. Failures are swallowed: a task
 * already terminal answers a conflict, which is not a teardown failure.
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
					headers: writeHeaders(),
					data: {},
				})
			}
		} catch {
			// Best effort; the next run's residue sweep is the backstop.
		}
	}
}

/**
 * Add a day count to an ISO date, in UTC so no timezone shifts the answer.
 *
 * @param isoDate A `YYYY-MM-DD` date.
 * @param days    How many days to add.
 * @return The resulting `YYYY-MM-DD`.
 */
function addDays(isoDate: string, days: number): string {
	const date = new Date(`${isoDate.slice(0, 10)}T00:00:00Z`)
	date.setUTCDate(date.getUTCDate() + days)
	return date.toISOString().slice(0, 10)
}

test.describe('A status is authored on the page, and what was authored runs', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		test.setTimeout(180_000)
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// ── the case type the administrator edits ──────────────────────────
		// PUBLISHED: `case.caseType` filters on `isDraft: false`, and the
		// schema defaults the flag to true.
		authored.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: authored.title,
				identifier: `${RUN_PREFIX.toLowerCase()}-csa-authored`,
				description: 'Throwaway caseType for case-type-status-authoring.',
				isDraft: false,
			}),
		)
		// None of the statuses names a role or a checklist: those are what the
		// tests author, and a seeded value would let a form that saves nothing
		// pass.
		authored.intake = await seedStatus('Ontvangen', 1)
		authored.progress = await seedStatus('In behandeling', 2)
		authored.review = await seedStatus('Toetsing', 3)
		authored.closed = await seedStatus('Afgehandeld', 4, { isFinal: true })
		await updateObject(api, token, 'caseType', authored.caseType, {
			initialStatus: authored.intake,
		})
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Statusbeheer workflow`,
			caseType: authored.caseType,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: TO_REVIEW,
					label: `${RUN_PREFIX} Naar toetsing`,
					fromStatus: authored.intake,
					toStatus: authored.review,
					guards: [],
				},
			]),
		})

		for (const key of ['role', 'checklist']) {
			cases[key] = objectId(
				await seedCase(api, token, {
					title: `${RUN_PREFIX} Statusbeheer ${key}`,
					caseType: authored.caseType,
					status: authored.intake,
				}),
			)
		}

		// ── the parent with twelve weeks, the child with six ────────────────
		bezwaar.parent = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Bezwaar`,
				identifier: `${RUN_PREFIX.toLowerCase()}-csa-parent`,
				description:
					'Throwaway parent caseType for case-type-status-authoring.',
				processingDeadline: 'P12W',
				isDraft: false,
			}),
		)
		bezwaar.intake = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Bezwaar ontvangen`,
				caseType: bezwaar.parent,
				order: 1,
				isFinal: false,
			}),
		)
		await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Bezwaar afgehandeld`,
			caseType: bezwaar.parent,
			order: 2,
			isFinal: true,
		})
		await updateObject(api, token, 'caseType', bezwaar.parent, {
			initialStatus: bezwaar.intake,
		})
		// The child names the parent and declares no status of its own. Its
		// deadline is the ONE thing it overrides.
		bezwaar.child = objectId(
			await createObject(api, token, 'caseType', {
				title: CHILD_TITLE,
				identifier: `${RUN_PREFIX.toLowerCase()}-csa-child`,
				description:
					'Throwaway child caseType for case-type-status-authoring.',
				parentCaseType: bezwaar.parent,
				processingDeadline: 'P6W',
				isDraft: false,
			}),
		)
	})

	test.afterAll(async () => {
		test.setTimeout(180_000)
		// Engine tasks first: they are found through the cases, which the
		// sweep below is about to remove.
		await cancelEngineTasks()
		for (const id of flows) {
			await api
				.delete(`${FLOWS_BASE}/${id}`, { headers: writeHeaders() })
				.catch(() => undefined)
		}
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-types::a-functional-administrator-gives-a-status-a-colour-and-a-role
	test('a colour and a role picked on the Statuses tab reach the row and a flow', async ({
		page,
	}) => {
		const admin = await openStatusesTab(page)

		const editing = await startEditing(admin, 'In behandeling')
		await pick(page, editing, /^(Colour|Kleur)$/, /^\s*(Orange|Oranje)\s*$/)
		await pick(
			page,
			editing,
			/^(Role|Rol)$/,
			/^\s*(In progress|In behandeling)\s*$/,
		)
		await save(editing, admin)

		// THEN the status row shows that colour.
		const row = statusRow(admin, 'In behandeling')
		await expect(row.locator('.status-type-row__swatch')).toHaveAttribute(
			'style',
			/--nl-color-orange\b/,
		)
		await expect(row.locator('.status-type-row__role')).toHaveText(
			/^\s*(In progress|In behandeling)\s*$/,
		)

		// What the page wrote, which is what everything below reads.
		const stored = await showObject(api, 'statusType', authored.progress)
		expect(String(stored.colour)).toBe('orange')
		expect(String(stored.role)).toBe('in-progress')

		// AND a flow addressing the in-progress role resolves to this status.
		// The step names the ROLE and no status name, so the only way it can
		// land anywhere is through what the administrator just picked.
		const created = await api.post(FLOWS_BASE, {
			headers: writeHeaders(),
			data: {
				name: `${RUN_PREFIX} Rol in behandeling`,
				description: 'Throwaway flow for case-type-status-authoring.',
				trigger: 'manual',
				nodes: [
					{
						id: 'move',
						type: 'dossiq.setStatus',
						config: { role: 'in-progress' },
						exit: true,
					},
				],
				edges: [],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		const flowId = String((await created.json()).id ?? '')
		expect(flowId, 'the flow came back without an id').not.toBe('')
		flows.push(flowId)

		// The SYNCHRONOUS test run: a queued run walks only when cron fires,
		// and asserting on one would assert on nothing.
		const before = await showObject(api, 'case', cases.role)
		expect(String(before.status)).toBe(authored.intake)
		const ran = await api.post(FLOW_TEST_RUN, {
			headers: writeHeaders(),
			data: { flowId, seedItems: [{ json: before }] },
		})
		expect(ran.status(), await ran.text()).toBe(200)
		const run = await ran.json()
		expect(
			String(run.status),
			`the role step should complete: ${JSON.stringify(run.log ?? run)}`,
		).toBe('completed')

		const after = await showObject(api, 'case', cases.role)
		expect(
			String(after.status),
			'the flow should have moved the case to the status carrying the role',
		).toBe(authored.progress)
	})

	// @e2e case-types::a-status-asks-for-a-checklist
	test('a required checklist item saved on a status becomes a task on the case', async ({
		page,
	}) => {
		const admin = await openStatusesTab(page)

		const editing = await startEditing(admin, 'Toetsing')
		await editing.getByTestId('status-type-checklist-add').click()
		// Through the ROW rather than the per-field test ids: where a test id
		// lands on an Nc input (wrapper or control) differs per component, and
		// the row holds exactly one textbox and one checkbox.
		const item = editing.locator('.status-type-form__checklist-row')
		await expect(item).toHaveCount(1)
		await item.getByRole('textbox').fill(CHECK_IDENTITY)
		await tickCheckbox(item.getByRole('checkbox'))
		await save(editing, admin)

		// THEN the status row reports one checklist item.
		await expect(
			statusRow(admin, 'Toetsing').locator('.status-type-row__checklist'),
		).toHaveText(/^\s*1 (checklist item|checklistpunt)\s*$/)

		// What the page wrote: one item, required, under the scenario's title.
		const stored = await showObject(api, 'statusType', authored.review)
		expect(stored.checklist).toEqual([{ title: CHECK_IDENTITY, required: true }])

		// AND a case entering that status gets a task called Check identity.
		expect(await tasksOf(cases.checklist, authored.review)).toEqual([])
		const moved = await executeTransition(api, token, cases.checklist, TO_REVIEW)
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)
		expect(String((await showObject(api, 'case', cases.checklist)).status)).toBe(
			authored.review,
		)

		const tasks = await tasksOf(cases.checklist, authored.review)
		expect(tasks.map((row) => String(row.title))).toEqual([CHECK_IDENTITY])

		// And the handler sees it where the work is done.
		await page.goto(`/apps/${REGISTER}/cases/${cases.checklist}`, {
			timeout: 60_000,
		})
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip.getByRole('tab', { name: /^(Work|Werk)$/ }).click()
		const panel = strip.locator('[role="tabpanel"]:not([hidden])')
		await expect(panel.locator('[data-testid="case-task-pane"]')).toContainText(
			CHECK_IDENTITY,
			{ timeout: 30_000 },
		)
	})

	// @e2e case-types::a-child-overrides-one-deadline
	test('a case filed on a child type is due six weeks after it starts, not twelve', async ({
		page,
	}) => {
		const title = `${RUN_PREFIX} Bezwaar tegen besluit`

		await page.goto(`/apps/${REGISTER}/`, { timeout: 60_000 })
		await dismissSupportDialog(page)
		await page
			.getByRole('button', { name: /^(New case|Nieuwe zaak)$/ })
			.click({ timeout: 30_000 })
		// The dialog ROOT: NcDialog renders its buttons in a footer beside the
		// div that carries the test id.
		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		// The case type FIRST: choosing one prefills the title with the type's
		// own, which would overwrite nothing typed after it.
		const combo = dialog.getByRole('combobox', { name: /Case type|Zaaktype/ })
		await combo.click()
		// By TEXT, not by accessible name: vue-select splits a label into
		// spans and the computed name gains a space the reader never sees.
		const option = page.getByRole('option').filter({ hasText: CHILD_TITLE })
		if (!(await option.isVisible().catch(() => false))) {
			await combo.pressSequentially(RUN_PREFIX, { delay: 30 })
		}
		await expect(option).toBeVisible({ timeout: 20_000 })
		await option.click()

		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(title)
		await dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ }).click()

		let filed: any = null
		await expect(async () => {
			const rows = await listObjects(api, 'case', { caseType: bezwaar.child })
			filed = rows.find((row) => String(row.title ?? '') === title) ?? null
			expect(filed, 'the case should have been filed').toBeTruthy()
		}).toPass({ timeout: 30_000 })
		cases.deadline = objectId(filed)

		// THEN the deadline is six weeks after the start date. Read off the
		// stored case, because the deadline is materialised at save time; the
		// parent's twelve weeks would put it at 84 days.
		const stored = await showObject(api, 'case', cases.deadline)
		const startDate = String(stored.startDate ?? '')
		expect(startDate, 'a filed case should carry a start date').toMatch(
			/^\d{4}-\d{2}-\d{2}/,
		)
		expect(String(stored.deadline ?? '').slice(0, 10)).toBe(
			addDays(startDate, 42),
		)
	})
})
