import type { APIRequestContext } from '@playwright/test'

/**
 * The cutover, on the one seam nothing else isolates: a task created by a flow
 * step, completed through the ENGINE's verb, waking the run that asked for it.
 *
 * WHY THIS IS NOT ALREADY COVERED. `case-flow-live-journeys` walks the shipped
 * flow end to end and does complete a task, but it drives the worker after
 * every step, so a run that woke because `TaskCompletionResumeListener`
 * signalled it and a run that woke because its own 30-minute heartbeat came due
 * look identical from there. The heartbeat is the SAFETY NET
 * (`DossiqAskPersonNode` re-reads the task on re-entry, deliberately, so a lost
 * signal still delivers the answer eventually), which means the normal path can
 * be completely broken while every journey assertion stays green — just slower
 * by up to half an hour, on an instance where nobody is watching the clock.
 *
 * So this spec asserts the WAKE ITSELF, and it asserts it the only way that
 * separates the two: `resumeAt`. `FlowRunService::signal()` sets it to NOW;
 * `DossiqAskPersonNode::heartbeatAt()` sets it minutes into the future. A run
 * parked on a person carries a future `resumeAt`, and the instant after the
 * completion it must carry a past one. Nothing else about the run changes in
 * between, which is what makes the field the evidence.
 *
 * AND THE REFUSAL, WHICH IS THE HALF THAT FAILS QUIETLY.
 * `TaskCompletionResumeListener` fires on every terminal state — the engine
 * dispatches `TaskTerminalEvent` for `completed`, `terminated` and `disabled`
 * alike — and only a COMPLETION is an answer. The other two are the question
 * being withdrawn, and a run that carried on past a cancelled ask would proceed
 * as though somebody had answered it. A guard that let them through would be
 * invisible in any journey that never cancels anything, so a cancelled task
 * gets its own case and its own run here.
 *
 * THE RUN IS THIS SPEC'S OWN. It authors a two-step flow rather than using the
 * shipped `Case behandeling`: the shipped one reaches its ask through a
 * completeness check and three status steps, all of which would have to be
 * correct for a failure here to be readable as a failure HERE.
 *
 * IT IS ASSERTED THROUGH THE API BECAUSE THE SEAM IS ONE. The listener runs
 * server-side on an engine event and nothing renders `resumeAt`. What a user
 * CAN reach is the task the ask created, at dossiq's own `/tasks/{uuid}` — so
 * that page is opened once, on the real engine task, which is also the page
 * `remove-casetask` 2.1 rebuilt as a custom page over the engine.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Every assertion is preceded by
 * one that the thing it needs exists. A skip cannot tell "not seeded" from
 * "the seeder is broken" and reports the second as a pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/task-management/spec.md
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth.ts'
import {
	ensureCaseType,
	FLOW_TASKS_BASE,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	objectId,
	purgeObject,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import {
	advanceFlowRun,
	createFlow,
	publishFlow,
	readFlowRun,
	removeFlow,
	runFlow,
} from './helpers/flows.ts'

// Serial: each test needs the run the one before it left. A file that authored
// a flow, published it and then ran it twice does not fit the 60s default on a
// loaded runner, and each worker pass is a full occ bootstrap.
test.describe.configure({ mode: 'serial', timeout: 180_000 })

/** The uid the ask is addressed to — the account the suite runs as. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'

/** The node id the ask carries, and therefore the one the task must name. */
const ASK_NODE = 'ask-the-handler'

/** The question the ask puts, which becomes the task's title. */
const QUESTION = `${RUN_PREFIX} Answer the ask that suspends the run`

/**
 * The smallest flow that suspends on a person: a manual start, one ask, an end.
 *
 * `assignee` is a LITERAL uid, not `{{ case.assignee }}`. The shipped flow uses
 * the template because a declaration cannot name a real person, and rendering
 * it is `AssigneeResolver`'s job; here the point is the wake, so the ask names
 * the account that will answer it and no resolution can come between.
 *
 * No `heartbeatMinutes`: the default is 30 and the floor is 5, and the whole
 * assertion below is that the completion beats whatever that number is.
 */
const GRAPH = {
	nodes: [
		{
			id: 'start',
			type: 'openregister.trigger-manual',
			config: {},
			position: { x: 0, y: 0 },
		},
		{
			id: ASK_NODE,
			type: 'dossiq.askPerson',
			config: {
				question: QUESTION,
				details:
					'Seeded by task-completion-resumes-the-run.spec.ts. Completing this '
					+ 'task must wake the run before its heartbeat comes due.',
				assignee: ADMIN_USER,
				signalKey: 'answer',
			},
			position: { x: 0, y: 160 },
		},
		{
			id: 'end',
			type: 'openregister.end',
			config: {},
			position: { x: 0, y: 320 },
		},
	],
	edges: [
		{ id: 'start-ask', from: 'start', to: ASK_NODE },
		{ id: 'ask-end', from: ASK_NODE, to: 'end' },
	],
}

let api: APIRequestContext
let token = ''

/** The flow this spec authors, and the two runs it starts on it. */
const seeded = {
	flowId: '',
	answeredRun: '',
	withdrawnRun: '',
	answeredCase: '',
	withdrawnCase: '',
	seededCaseType: '',
}

/** The task each run's ask created, filled in by the test that finds it. */
const tasks = { answered: '', withdrawn: '' }

/**
 * Start the flow on one case and walk it to where it waits for a person.
 *
 * `sync: true` walks the run inside the request, so an ask normally suspends
 * before the POST returns; `advanceFlowRun` is the belt to that brace, because
 * a run that came back QUEUED would otherwise be read as one that suspended
 * without creating a task.
 *
 * @param caseId The case the run is about.
 * @return The run's uuid.
 */
async function startRunOn(caseId: string): Promise<string> {
	const started = await runFlow(api, token, seeded.flowId, {
		uuid: caseId,
		register: 'dossiq',
		schema: 'case',
	})
	const uuid = String(started.uuid ?? '')
	expect(uuid, 'The run endpoint must answer with the run it made.').not.toBe('')

	const run = await advanceFlowRun(api, uuid)
	expect(
		String(run.status ?? ''),
		`The run must park on the ask rather than finishing or failing. Log: ${JSON.stringify(run.log ?? [])}`,
	).toBe('suspended')

	return uuid
}

/**
 * The one task the ask put on a case, read from the ENGINE.
 *
 * `scope: 'all'` because the question is what work the CASE carries. The
 * default scope is `assigned`, which would answer for the ask here and stop
 * answering the day the flow addresses a group instead — a filter that happens
 * to agree with the fixture is not a filter that tests anything.
 *
 * @param caseId The case.
 * @param run    The run whose ask it must belong to.
 * @return The task's uuid.
 */
async function askedTaskOn(caseId: string, run: string): Promise<string> {
	const rows = await listFlowTasks(api, {
		objectUuid: caseId,
		scope: 'all',
		limit: '50',
	})

	expect(
		rows.map((row: any) => String(row.title ?? '')),
		'The ask must have created exactly one engine task on its case.',
	).toHaveLength(1)

	const task = rows[0]
	expect(String(task.title ?? '')).toBe(QUESTION)
	// Both are required to resume: the run alone cannot say WHICH of its
	// awaiting nodes an answer is for, which is why `DossiqAskPersonNode` is one
	// node rather than a createTask followed by an await.
	expect(
		String(task.runUuid ?? ''),
		'A task that names no run can never wake one.',
	).toBe(run)
	expect(
		String(task.nodeId ?? ''),
		'A task that names no node cannot say which ask it answers.',
	).toBe(ASK_NODE)
	// The RENDERED principal, never the authored template: OpenRegister's
	// assignee guard compares this against real uids, so a stored placeholder
	// refuses every real user and the ask is unanswerable by anybody.
	expect(String(task.assignee ?? '')).toBe(ADMIN_USER)

	const uuid = String(task.uuid ?? '')
	expect(
		uuid,
		'The engine task must carry a uuid; no route accepts its numeric id.',
	).not.toBe('')
	return uuid
}

/**
 * How far in the future a run is parked, in milliseconds. Negative means due.
 *
 * @param run The run, as `readFlowRun` answers it.
 */
function parkedForMs(run: Record<string, unknown>): number {
	expect(
		run.resumeAt,
		'A run suspended on a person must carry a resumeAt; without one no '
			+ 'heartbeat can ever wake it and only a signal can.',
	).toBeTruthy()
	return Date.parse(String(run.resumeAt)) - Date.now()
}

test.beforeAll(async ({ baseURL }) => {
	test.setTimeout(180_000)
	api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
	token = await getRequestToken(api)

	const caseType = await ensureCaseType(api, token)
	if (caseType.seeded === true) {
		seeded.seededCaseType = caseType.id
	}

	seeded.answeredCase = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} The ask that is answered`,
			caseType: caseType.id,
			assignee: ADMIN_USER,
		}),
	)
	seeded.withdrawnCase = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} The ask that is withdrawn`,
			caseType: caseType.id,
			assignee: ADMIN_USER,
		}),
	)

	seeded.flowId = await createFlow(
		api,
		token,
		`${RUN_PREFIX} Ask and wake`,
		'Throwaway flow seeded by task-completion-resumes-the-run.spec.ts.',
		GRAPH,
	)
	await publishFlow(api, token, seeded.flowId)
})

test.afterAll(async () => {
	if (!api) return
	test.setTimeout(180_000)

	const survivors: string[] = []
	try {
		// The tasks first: they hang off the cases, and `cancel` is the only
		// removal verb the engine publishes. A task already terminal answers a
		// conflict, which is the expected outcome for the two this spec drove
		// there itself, so the failure is swallowed rather than reported.
		for (const uuid of [tasks.answered, tasks.withdrawn]) {
			if (uuid === '') continue
			await api
				.post(`${FLOW_TASKS_BASE}/${uuid}/cancel`, {
					headers: {
						requesttoken: token,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					data: {},
				})
				.catch(() => undefined)
		}

		if (seeded.flowId !== '') {
			survivors.push(
				...(await removeFlow(api, token, seeded.flowId, [
					seeded.answeredRun,
					seeded.withdrawnRun,
				])),
			)
		}

		for (const [label, id] of [
			['case', seeded.answeredCase],
			['case', seeded.withdrawnCase],
			['caseType', seeded.seededCaseType],
		] as Array<[string, string]>) {
			if (id === '') continue
			if ((await purgeObject(api, token, label, id)) === false) {
				survivors.push(`${label} ${id}`)
			}
		}
	} finally {
		await api.dispose()
	}

	if (survivors.length > 0) {
		throw new Error(
			'e2e teardown left seeded rows behind, so the next run on this instance '
				+ `starts dirty: ${survivors.join(', ')}`,
		)
	}
})

test.describe('A completed task wakes the run that asked for it', () => {
	test('the ask suspends its run and parks it on a heartbeat minutes away', async () => {
		seeded.answeredRun = await startRunOn(seeded.answeredCase)
		tasks.answered = await askedTaskOn(seeded.answeredCase, seeded.answeredRun)

		const run = await readFlowRun(api, seeded.answeredRun)
		// The floor is five minutes (MIN_HEARTBEAT_MINUTES) and the default is
		// thirty. One minute is asserted rather than either, so the test pins
		// "parked on a person, not due" without pinning a number the node is
		// free to change.
		expect(
			parkedForMs(run),
			'A run waiting for a person must be parked in the FUTURE. A resumeAt '
				+ 'that is already due means the worker will pick it straight back up, '
				+ 'and the assertion below could then pass without any signal at all.',
		).toBeGreaterThan(60_000)
	})

	test("the task the ask created is reachable on dossiq's own task page", async ({
		page,
	}) => {
		// `remove-casetask` 2.1 rebuilt this page as a custom page over the
		// engine, keeping the route and the page id, so a bookmark and a
		// notification link both still land here. The engine task is not an
		// OpenRegister object, so nothing but that rebuild makes this resolve.
		await page.goto(`/index.php/apps/dossiq/tasks/${tasks.answered}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).toContainText(QUESTION, {
			timeout: 30_000,
		})
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('completing it through the engine verb makes the run due at once', async () => {
		const before = await readFlowRun(api, seeded.answeredRun)
		const parkedFor = parkedForMs(before)

		await invokeFlowTask(api, token, tasks.answered, 'complete')

		const after = await readFlowRun(api, seeded.answeredRun)
		// The wake, and the whole point of the file. A run still parked minutes
		// out means TaskCompletionResumeListener did not signal — which is
		// invisible in any test that drives the worker afterwards, because the
		// node's heartbeat re-reads the task and delivers the same answer late.
		expect(
			parkedForMs(after),
			`The completion must make the run due. It is still parked ${Math.round(
				parkedForMs(after) / 1000,
			)}s out, where it was ${Math.round(parkedFor / 1000)}s out before — so `
				+ 'nothing signalled it and only the heartbeat is left to deliver the answer.',
		).toBeLessThanOrEqual(0)
	})

	test('and one worker pass then carries the run to the end', async () => {
		const run = await advanceFlowRun(api, seeded.answeredRun)

		expect(
			String(run.status ?? ''),
			`The woken run must walk on past the ask to its end. Log: ${JSON.stringify(run.log ?? [])}`,
		).toBe('completed')
	})
})

test.describe('A withdrawn ask wakes nothing', () => {
	test('cancelling the task leaves its run parked on the heartbeat', async () => {
		seeded.withdrawnRun = await startRunOn(seeded.withdrawnCase)
		tasks.withdrawn = await askedTaskOn(
			seeded.withdrawnCase,
			seeded.withdrawnRun,
		)

		const before = await readFlowRun(api, seeded.withdrawnRun)
		expect(parkedForMs(before)).toBeGreaterThan(60_000)

		// `cancel` takes the task to `terminated`, and the engine dispatches
		// TaskTerminalEvent for that exactly as it does for a completion. The
		// listener sees both and must act on only one of them.
		const cancelled = await invokeFlowTask(api, token, tasks.withdrawn, 'cancel')
		expect(
			String(cancelled.state ?? ''),
			'The cancel verb must take the task to a terminal state that is NOT a '
				+ 'completion, or this test proves nothing about the guard.',
		).toBe('terminated')

		const after = await readFlowRun(api, seeded.withdrawnRun)
		expect(
			String(after.status ?? ''),
			'A withdrawn ask must leave the run suspended.',
		).toBe('suspended')
		expect(
			parkedForMs(after),
			'A cancelled task must NOT make the run due. A run resumed here would '
				+ 'walk past the ask as though somebody had answered it, and nobody did.',
		).toBeGreaterThan(60_000)
	})

	test("and the run that was woken is not disturbed by the other run's withdrawal", async () => {
		// One listener serves every task on the instance, and it addresses the
		// signal to the node the task names. A cancel that woke the wrong run
		// would show here as the finished run being alive again.
		const run = await readFlowRun(api, seeded.answeredRun)
		expect(String(run.status ?? '')).toBe('completed')
	})
})
