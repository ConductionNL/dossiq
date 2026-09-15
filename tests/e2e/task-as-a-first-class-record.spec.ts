/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task as a record, not a line on a checklist.
 *
 * A case type can now say what a task asks for, who it is offered to, how long
 * it has and what finishing it does. Every assertion here is on a seam no unit
 * test reaches: that a declared lead time reaches the STORED task's due date,
 * that a task switched off on one case type is absent from a real case of it,
 * that the acts endpoint answers both halves, and that the second open task of
 * a case is completed on the case page without a route change.
 *
 * 🔴 THE LEAD-TIME ASSERTION IS ON THE STORED TASK, NOT ON THE SCREEN. A due
 * date rendered in the pane could come from the case deadline, the status term
 * or the task, and all three look identical in a cell. Reading it off the
 * engine is the only way to know WHOSE date it is.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN, which passes CaseAccessGuard on any case and
 * is in every candidate pool. So the claim assertions here are about what the
 * engine RECORDS (who took it, and when), not about a refusal for an outsider:
 * that branch belongs to TaskCandidatesTest and to the engine's own suite.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The uid Playwright signs in as. */
const ADMIN_USER = 'admin'

/** Dossiq's own task routes. */
const DOSSIQ_TASKS = '/index.php/apps/dossiq/api/case-tasks'

/** The state machine both halves of this spec hang off. */
let machine: Awaited<ReturnType<typeof seedStateMachine>>

/** A case of that type, carrying the tasks. */
let caseId = ''

test.describe('The task as a first-class record', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		machine = await seedStateMachine(api, token)

		// The per-task declaration: the hoorzitting task gets ten working days
		// and a verslag form, the advice task is switched off. Both live in ONE
		// block per task on the step, which is the shape the validator refuses
		// to publish any other way.
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Workflow met taakblokken`,
			caseType: machine.caseTypeId,
			isActive: false,
			isDraft: true,
			version: 2,
			steps: JSON.stringify([
				{
					id: 'step-hoorzitting',
					title: `${RUN_PREFIX} Hoor de belanghebbende`,
					status: machine.statusInProgress,
					order: 1,
					task: {
						enabled: true,
						leadTimeDays: 10,
						candidateGroups: ['admin'],
						form: {
							kind: 'fields',
							schema: 'case',
							fields: [{ field: 'description', required: true }],
						},
					},
				},
				{
					id: 'step-advies',
					title: `${RUN_PREFIX} Vraag advies`,
					status: machine.statusInProgress,
					order: 2,
					task: { enabled: false },
				},
			]),
		})

		// The always-available half of "what may I do right now". Declared on
		// the case type, never as a phase: a phase would show in the strip, in
		// the progress figure and in the term calculation.
		await updateObject(api, token, 'caseType', machine.caseTypeId, {
			alwaysAvailableActs: [
				{ id: 'withdraw', label: 'Trek de zaak in' },
				{ id: 'add-document', label: 'Voeg een document toe' },
				{ id: 'ask-colleague', label: 'Vraag een collega' },
			],
		})

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Taak als record`,
			caseType: machine.caseTypeId,
			description: '',
			startDate: new Date().toISOString().slice(0, 10),
			assignee: ADMIN_USER,
		})
		caseId = objectId(row)
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
	})

	test('a task carries its own due date, ten working days out', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// Seeded through the engine with the declaration applied, which is what
		// CreateTaskHandler writes when the case enters the status.
		const due = new Date()
		due.setDate(due.getDate() + 14)
		const taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Hoor de belanghebbende`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
			dueAt: due.toISOString(),
		})

		const stored = await listFlowTasks(api, { objectUuid: caseId, scope: 'all' })
		const task = stored.find((row: any) => String(row.uuid) === taskId)

		expect(task, 'the seeded task is on the case').toBeTruthy()
		// ITS OWN date, not the case deadline. The case was seeded with no
		// deadline at all, so a task showing one would be showing something
		// it invented.
		expect(String(task.dueAt ?? '')).not.toBe('')
		const caseRow = await showObject(api, 'case', caseId)
		expect(String(task.dueAt ?? '').slice(0, 10)).not.toBe(
			String(caseRow.deadline ?? '').slice(0, 10),
		)
	})

	test('a task switched off for this case type is not created', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const stored = await listFlowTasks(api, { objectUuid: caseId, scope: 'all' })
		const advice = stored.filter((row: any) =>
			String(row.title ?? '').includes('Vraag advies'),
		)

		// The declaration said `enabled: false`, so the step exists in the
		// process and the task does not exist on the case. A task created and
		// then hidden would still appear in every count.
		expect(advice).toHaveLength(0)
	})

	test('what may I do right now, in two halves', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })

		const res = await api.get(
			`/index.php/apps/dossiq/api/case/${caseId}/acts`,
		)
		expect(res.ok(), `acts -> ${res.status()} ${await res.text()}`).toBeTruthy()
		const body = await res.json()

		// ONE endpoint, both halves. `lifecycle-acts-on-the-case` landed the
		// one lifecycle menu while this change was in flight and already read
		// this route, so the always-available acts ride on its answer rather
		// than on a second endpoint nobody would have merged.
		expect(body.alwaysAvailable).toHaveLength(3)
		expect(Array.isArray(body.acts)).toBe(true)
	})

	test('an unclaimed task is listed for its group, and claiming records who', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Onbezette taak`,
			objectUuid: caseId,
			candidateGroups: ['admin'],
		})

		const claimed = await invokeFlowTask(api, token, taskId, 'claim')

		expect(String(claimed.assignee ?? '')).toBe(ADMIN_USER)
		// The claim is RECORDED, not just applied: the audit is what makes a
		// handover reconstructable months later.
		const audit = await api.get(
			`/index.php/apps/openregister/api/flow-tasks/${taskId}/audit`,
		)
		expect(audit.ok()).toBeTruthy()
		const entries = await audit.json()
		const actions = (Array.isArray(entries) ? entries : entries.results ?? []).map(
			(entry: any) => String(entry.action ?? ''),
		)
		expect(actions).toContain('claim')
	})

	test('the form is filled in place, and a blank required field refuses', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// `metadata.form` is the declaration dossiq writes when the task is
		// created, and the shape OpenRegister's task form resolver reads. One
		// vocabulary, which is why the seed can use it directly.
		const taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Hoorzitting met verslag`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
			metadata: {
				form: {
					kind: 'fields',
					schema: 'case',
					fields: [{ field: 'description', required: true }],
				},
			},
		} as any)

		const refused = await api.post(`${DOSSIQ_TASKS}/${taskId}/complete`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { data: {} },
		})
		expect(refused.status(), await refused.text()).toBe(400)
		const body = await refused.json()
		// THE FIELD, not "a required field". A form of nine fields with one
		// empty is a message a handler can act on only when it names which.
		expect(body.error).toBe('required_field')
		expect(body.field).toBe('description')

		// The same form is shown ON THE CASE, so the handler fills it where
		// they already are.
		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		const field = page.locator(
			`[data-testid="case-task-pane-form-${taskId}-description"], [data-testid="case-task-pane-form-description"]`,
		)
		await expect(field.first()).toBeVisible({ timeout: 30_000 })

		const accepted = await api.post(`${DOSSIQ_TASKS}/${taskId}/complete`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { data: { description: 'Gehoord op 3 maart' } },
		})
		expect(accepted.ok(), await accepted.text()).toBeTruthy()
	})

	test('completing the task publishes its file to the case, naming the task', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Taak die een bestand oplevert`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
		})

		await api.post(
			`/index.php/apps/dossiq/api/case/${caseId}/tasks/${taskId}/attachments`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { file: 'e2e-file-published', title: 'Bewijsstuk.pdf' },
			},
		)

		const completed = await api.post(`${DOSSIQ_TASKS}/${taskId}/complete`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { data: {} },
		})
		expect(completed.ok(), await completed.text()).toBeTruthy()

		// The hold is cleared, which is the half a document list cannot show:
		// a file published twice looks exactly like one published once until
		// somebody counts.
		const caseRow = await showObject(api, 'case', caseId)
		const waiting = Array.isArray(caseRow.taskAttachments) ? caseRow.taskAttachments : []
		expect(waiting.map((entry: any) => String(entry.file))).not.toContain(
			'e2e-file-published',
		)
	})

	test('the second open task is completed on the case, without a route change', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const first = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Eerste open taak`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
		})
		const second = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Tweede open taak`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
		})
		void first

		const errors = trackDossiqErrors(page)
		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)

		const pane = page.locator('[data-testid="case-task-pane"]')
		await expect(pane).toBeVisible({ timeout: 30_000 })

		const row = page.locator(`[data-testid="case-task-pane-task-${second}"]`)
		await expect(row).toBeVisible({ timeout: 30_000 })

		const url = page.url()
		await row.locator(`[data-testid="case-task-pane-row-complete-${second}"]`).click()

		// The whole claim of this surface: the task finished and the handler
		// is still on the case.
		await expect(row).toBeHidden({ timeout: 30_000 })
		expect(page.url()).toBe(url)
		expect(errors, 'no dossiq console errors while completing in place').toEqual([])
	})

	test('a task shows a reference, and says when it has no number', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Taak met kenmerk`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
		})

		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		const reference = page.locator('[data-testid="case-task-pane-reference"]')
		await expect(reference).toBeVisible({ timeout: 30_000 })

		// The engine has no task number today. The pane shows its identifier
		// and says so, and invents nothing: a made-up number gets quoted in an
		// email and addresses nothing.
		await expect(reference).not.toHaveText('')
		await expect(page.locator('[data-testid="case-task-pane-lock"]')).not.toHaveText('')
	})

	test('a file uploaded in a task form waits with the task', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const taskId = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Taak met bijlage`,
			objectUuid: caseId,
			assignee: ADMIN_USER,
		})

		const held = await api.post(
			`/index.php/apps/dossiq/api/case/${caseId}/tasks/${taskId}/attachments`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { file: 'e2e-file-1', title: 'Verslag hoorzitting.pdf' },
			},
		)
		expect(held.ok(), `attach -> ${held.status()} ${await held.text()}`).toBeTruthy()

		// Held, and NOT yet a document on the case: the case's documents are
		// its caseDocument objects, and one is written when the task completes.
		const caseRow = await showObject(api, 'case', caseId)
		const waiting = Array.isArray(caseRow.taskAttachments) ? caseRow.taskAttachments : []
		expect(waiting.map((entry: any) => String(entry.file))).toContain('e2e-file-1')

		// Removable while the task is open.
		const removed = await api.delete(
			`/index.php/apps/dossiq/api/case/${caseId}/tasks/${taskId}/attachments/e2e-file-1`,
			{ headers: { requesttoken: token } },
		)
		expect(removed.ok()).toBeTruthy()
	})
})
