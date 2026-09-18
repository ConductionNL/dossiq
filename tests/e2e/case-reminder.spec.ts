/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TASK-020: a reminder you set for a colleague from a case is an engine
 * task, and it behaves like one everywhere.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. The vitest suite proves what
 * the dialog builds and what the manifest declares. Neither it nor any PHP
 * test in this repository can prove the three things below, because all three
 * are decided by OpenRegister on a request this repository never makes.
 *
 *  - THE ENGINE STORES THE KIND. `kind` is a column on `openregister_tasks`
 *    since openregister#3863. An OpenRegister older than that accepts the
 *    create, answers 201, and drops the field in silence, which is exactly
 *    what a working reminder looks like until somebody filters. So the first
 *    assertion reads the kind back off the created task, and the filter test
 *    below needs it to be there.
 *  - THE INBOX FILTERS ON IT. `?kind=reminder` is a server-side predicate.
 *    A control task with no kind is seeded alongside, so "the filter answered
 *    one row" cannot pass because the filter answered everything.
 *  - COMPLETING IT TAKES IT OFF THE CASE'S OPEN WORK. The open-task list is
 *    the engine's `isTerminal` filter, not a dossiq projection.
 *
 * THE LEAST PRIVILEGED PRINCIPAL. A reminder names a colleague and lands on
 * their work list, so the write is probed from a context that never signed in.
 * A stranger who can create a task assigned to a named employee can put words
 * on that person's screen, and a 201 there is the finding. That context is
 * built with the base URL, because one without it cannot resolve a relative
 * path and would fail for a reason that has nothing to do with authorization.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own case under RUN_PREFIX, assigns
 * every reminder to the signed-in user rather than to a real colleague, and
 * cancels every task it created. A task assigned to somebody else is a
 * notification on a real person's screen, which is residue this repository has
 * been bitten by before.
 *
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	cleanupRunObjects,
	ensureCaseType,
	FLOW_TASKS_BASE,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'

/** The kind every reminder carries. One spelling, `src/utils/reminderHelpers.js`. */
const REMINDER_KIND = 'reminder'

/** The day the reminder is due, in the shape the dialog sends. */
const DUE_AT = '2026-10-03T23:59:59+00:00'

test.describe('REQ-TASK-020 a reminder is a task with a kind', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let anonymous: APIRequestContext
	let token = ''
	let uid = ''
	let caseId = ''
	let reminderUuid = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		anonymous = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		// Whoever this run signed in as. The reminder is assigned to them and
		// to nobody else: a task on a colleague's list is residue they read.
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), await whoami.text()).toBeTruthy()
		uid = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(uid, 'the session named a user').not.toBe('')

		const caseType = await ensureCaseType(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} reminder`,
			caseType: caseType.id,
		})
		caseId = objectId(seeded)

		reminderUuid = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Call the applicant`,
			objectUuid: caseId,
			assignee: uid,
			dueAt: DUE_AT,
			kind: REMINDER_KIND,
		})

		// THE CONTROL. Ordinary work on the same case, with no kind. Without
		// it "the filter answered the reminder" passes on a filter that
		// answers everything, which is what an unindexed `kind` looks like.
		await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} Assess the request`,
			objectUuid: caseId,
			assignee: uid,
		})
	})

	test.afterAll(async () => {
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
		await api.dispose()
		await anonymous.dispose()
	})

	test('the engine stores the kind, the assignee and the day', async () => {
		const read = await api.get(`${FLOW_TASKS_BASE}/${reminderUuid}`)
		expect(read.ok(), await read.text()).toBeTruthy()
		const task = await read.json()

		// An OpenRegister that predates the kind column answers 201 on the
		// create and null here, so this is the assertion that fails on one.
		expect(
			String(task?.kind ?? ''),
			'the engine kept the kind; an OpenRegister without the column answers null',
		).toBe(REMINDER_KIND)
		expect(String(task?.assignee ?? '')).toBe(uid)
		expect(String(task?.dueAt ?? '')).toContain('2026-10-03')
		expect(String(task?.title ?? '')).toContain('Call the applicant')
	})

	test('the case shows the reminder among its work, and the kind picks it out', async () => {
		const onTheCase = await listFlowTasks(api, {
			objectUuid: caseId,
			scope: 'all',
		})
		const titles = onTheCase.map((row: any) => String(row?.title ?? ''))
		expect(titles.join(' | ')).toContain('Call the applicant')
		expect(titles.join(' | ')).toContain('Assess the request')

		const reminders = await listFlowTasks(api, {
			objectUuid: caseId,
			scope: 'all',
			kind: REMINDER_KIND,
		})
		const kinded = reminders.map((row: any) => String(row?.title ?? ''))
		expect(kinded.join(' | ')).toContain('Call the applicant')
		// The control: ordinary work is NOT a reminder, so a filter that
		// narrowed nothing fails here rather than reading as a pass.
		expect(kinded.join(' | ')).not.toContain('Assess the request')
	})

	test('the assignee finds it under their own work', async () => {
		const mine = await listFlowTasks(api, {
			scope: 'assigned',
			kind: REMINDER_KIND,
			isTerminal: 'false',
		})
		const titles = mine.map((row: any) => String(row?.title ?? ''))
		expect(titles.join(' | ')).toContain('Call the applicant')
	})

	test('a stranger with no session cannot put a reminder on a colleague', async () => {
		const attempted = await anonymous.post(FLOW_TASKS_BASE, {
			headers: { 'Content-Type': 'application/json' },
			data: {
				title: `${RUN_PREFIX} reminder from nobody`,
				appId: 'dossiq',
				objectUuid: caseId,
				assignee: uid,
				kind: REMINDER_KIND,
			},
		})

		// 401 without a session, 403 when the instance answers that way, 412
		// on the missing request token. Any of the three is a refusal; a 201
		// is a stranger writing on an employee's work list.
		expect(
			[401, 403, 412],
			`anonymous create answered ${attempted.status()}: ${await attempted.text()}`,
		).toContain(attempted.status())
	})

	test('completing the reminder takes it off the case', async () => {
		await invokeFlowTask(api, token, reminderUuid, 'claim')
		await invokeFlowTask(api, token, reminderUuid, 'complete')

		const open = await listFlowTasks(api, {
			objectUuid: caseId,
			scope: 'all',
			isTerminal: 'false',
		})
		const titles = open.map((row: any) => String(row?.title ?? ''))
		expect(titles.join(' | ')).not.toContain('Call the applicant')
		// Still the ordinary task, so "the list went empty" cannot pass for
		// "the reminder closed".
		expect(titles.join(' | ')).toContain('Assess the request')
	})
})
