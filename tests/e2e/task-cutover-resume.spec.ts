import type { APIRequestContext } from '@playwright/test'

/**
 * The task cutover, end to end: a task created outside the register, completed
 * through the engine's own verb, waking the flow run that was waiting on it.
 *
 * WHY THIS FILE EXISTS. `remove-casetask` moved every task in dossiq from a
 * register object onto OpenRegister's task engine, and then deleted the
 * `caseTask` schema. Six read surfaces, two pages and eleven server classes
 * moved with it, and each of those has its own coverage. One path had none:
 * the chain that starts when something creates a task and ends when a
 * suspended flow run carries on because that task was completed. That chain
 * runs through `lib/Listener/TaskCompletionResumeListener`, which listens for
 * the engine's `TaskTerminalEvent` — an event that did not exist before the
 * cutover, on a store that did not exist before the cutover.
 *
 * WHAT BREAKS WHEN IT BREAKS, AND WHY NOTHING SAYS SO. If the chain is not
 * wired, the task still completes. The verb answers 200, the task pane shows it
 * closed, the person who did the work sees exactly what they expect. The only
 * thing that does not happen is the resume: the run stays suspended until
 * `dossiq.askPerson`'s heartbeat wakes it, which is thirty minutes by default
 * and can be configured longer, and in the meantime the case does not move and
 * no log line anywhere is an error. A spec that asserted only "the task shows
 * completed" would pass against that exact instance. So the assertions below
 * are on the RUN and on the CASE.
 *
 * 🔴 WHAT THIS SPEC DOES NOT PROVE, SAID HERE RATHER THAN LEFT TO BE ASSUMED.
 * It does not prove that DOSSIQ's listener is what resumed the run, because on
 * an instance running both apps it is not the only thing that can.
 * OpenRegister registers `UserTaskTerminalListener` on the same event, and that
 * listener hands EVERY terminal task carrying a `runUuid` to
 * `FlowTaskBridge::continueRun()`, which signals the run and may even advance
 * it inside the request. Measured on a clean rig (openregister 2.0.18, dossiq
 * 0.4.10, 2026-09-11) by commenting the dossiq registration out of
 * `WorkflowListenerRegistrar` and repeating the whole chain: the run resumed
 * and the case advanced identically, and the run's `context.signal` read `[]`
 * both ways, which is OpenRegister's empty payload rather than dossiq's. A
 * temporary log line in the dossiq listener confirmed it does fire; its payload
 * is simply overwritten, because `FlowRunService::signal()` replaces
 * `context.signal` and OpenRegister's listener runs second.
 *
 * So what is pinned here is the BEHAVIOUR — a completed engine task moves the
 * case — and the provenance the chain depends on. That is worth pinning on its
 * own: the behaviour is what a caseworker relies on, and the mutation check
 * below shows the spec fails when the chain is genuinely broken. Whether
 * `TaskCompletionResumeListener` should still exist beside OpenRegister's is
 * the same question task 3.2 of this change already holds open about
 * `DossiqAskPersonNode` beside `UserTaskNode`, and it is a decision about
 * duplication rather than something a test can settle.
 *
 * 🔑 MUTATION-CHECKED by removing `flowRun` and `flowNode` from
 * `DossiqAskPersonNode::buildTask()`, which is the provenance both listeners
 * read. Tests 2 and 3 both went red: the task carried no run, so nothing
 * signalled, the run stayed suspended past a worker pass and the case kept its
 * empty description. Restored, all three pass.
 *
 * THE THREE TESTS, AND WHAT EACH ONE PINS.
 *
 *   1. A status transition's `createTask` action writes the ENGINE. This is
 *      `CreateTaskHandler` after task 3.1, which dropped the register write
 *      entirely. The task is read back off `/api/flow-tasks`, and the deleted
 *      schema is asked for as well, so "the engine has it" and "the register
 *      does not" are two answers rather than one inference.
 *
 *   2. A flow run suspended on a human step writes the ENGINE, and the task it
 *      writes names its run and its node. This is `AskPersonTaskStore` after
 *      task 3.3b. Both names matter: the listener refuses a task carrying only
 *      one, because a run holds one resume slot per node and the run uuid
 *      alone cannot say which of its awaiting steps an answer is for.
 *
 *   3. Completing that task through `/api/flow-tasks/{uuid}/complete` resumes
 *      the run, and the case moves. This is the listener. See the test body
 *      for why it is asserted in two stages.
 *
 * 🔴 THE HEARTBEAT IS SET A DAY OUT, DELIBERATELY. `dossiq.askPerson` parks on
 * a suspension that re-wakes on its own, and that safety net is the thing that
 * would make this spec pass with the listener disconnected — the worker pass in
 * test 3 would find a run that was due anyway and advance it for reasons that
 * have nothing to do with a completed task. A 1440-minute heartbeat puts the
 * node's own wake-up far outside the run, so the ONLY thing that can pull the
 * run forward within this test is the signal the listener sends.
 *
 * THE FLOW IS AUTHORED HERE, not adopted. Enabling the shipped `Case
 * behandeling` flow would start it on every case any other worker creates while
 * this file runs, which is the reason `case-detail-flow-runs.spec.ts` gives for
 * authoring its own, and it holds here too. This flow is never enabled: a
 * manual run does not consult `enabled`, so it can only ever start when this
 * spec asks for it.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT PRECONDITION. No `test.skip` appears
 * below. A skip cannot tell "this instance is not set up" from "the thing under
 * test is broken", and reports the second as a pass, which for a spec whose
 * whole subject is a silent failure would be the same defect one level up.
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
	cleanupFlowTasks,
	cleanupRunObjects,
	createObject,
	FLOW_TASKS_BASE,
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { occRun } from './helpers/occ.ts'

test.describe.configure({ mode: 'serial' })

/** OpenRegister's API root, which owns flows, runs and tasks. */
const OR_API = '/index.php/apps/openregister/api'

/** The background job that advances a run parked for resumption. */
const FLOW_WORKER_CLASS = 'OCA\\OpenRegister\\BackgroundJob\\FlowRunWorker'

/**
 * The node id the human step carries inside the authored flow.
 *
 * Named rather than inlined because it is asserted twice: once as the task's
 * provenance, and once as the node the listener addresses its signal to.
 */
const ASK_NODE = 'ask-the-handler'

/**
 * How long `dossiq.askPerson` waits before waking itself. A day, for the
 * reason the file header gives.
 */
const HEARTBEAT_MINUTES = 1440

/** What the flow writes onto the case once the human step has been answered. */
const ADVANCED_MARKER = `${RUN_PREFIX} the handler answered and the case moved on`

/** The title the status transition's own `createTask` action gives its task. */
const TRANSITION_TASK_TITLE = `${RUN_PREFIX} task from a status transition`

/** The question the human step asks, which becomes its task's title. */
const ASK_QUESTION = `${RUN_PREFIX} is this case ready to close?`

/** How long a poll for an asynchronously written row may take. */
const SETTLE_MS = 20_000

type Json = Record<string, any>

/** The headers a CSRF-protected OpenRegister write needs. */
function writeHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * One GET against OpenRegister, failing with the server's own answer.
 *
 * @param api  Authenticated request context.
 * @param path Path below the OpenRegister API root.
 */
async function orGet(api: APIRequestContext, path: string): Promise<Json> {
	const res = await api.get(`${OR_API}${path}`)
	expect(
		res.ok(),
		`GET ${path} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return (await res.json()) as Json
}

/**
 * One POST against OpenRegister, failing with the server's own answer.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param path  Path below the OpenRegister API root.
 * @param data  The request body.
 */
async function orPost(
	api: APIRequestContext,
	token: string,
	path: string,
	data: Json = {},
): Promise<Json> {
	const res = await api.post(`${OR_API}${path}`, {
		headers: writeHeaders(token),
		data,
	})
	expect(
		res.ok(),
		`POST ${path} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return (await res.json()) as Json
}

/**
 * Every engine task hanging off one case, whatever its state.
 *
 * `scope: all` rather than the default `assigned`, because the point is what
 * the CASE carries and not what the reader has been given.
 *
 * @param api    Authenticated request context.
 * @param caseId The case uuid, which the engine stores as `objectUuid`.
 */
async function tasksOnCase(
	api: APIRequestContext,
	caseId: string,
): Promise<Json[]> {
	return listFlowTasks(api, { scope: 'all', objectUuid: caseId, limit: '50' })
}

/**
 * Poll until a task with `title` hangs off `caseId`, or fail naming the wait.
 *
 * A task written by a transition action or by a flow node is written inside
 * the request that triggered it, but the read is a second round trip and the
 * engine's own inbox query is not the same statement. Polling rather than
 * reading once removes a race that would otherwise be intermittent, which is
 * the worst thing a guard test can be.
 *
 * @param api    Authenticated request context.
 * @param caseId The case the task must hang off.
 * @param title  The task title to wait for.
 * @param what   What is being waited for, for the failure message.
 */
async function taskTitled(
	api: APIRequestContext,
	caseId: string,
	title: string,
	what: string,
): Promise<Json> {
	let seen: string[] = []
	const deadline = Date.now() + SETTLE_MS
	while (Date.now() < deadline) {
		const tasks = await tasksOnCase(api, caseId)
		seen = tasks.map((task) => String(task.title ?? ''))
		const found = tasks.find((task) => String(task.title ?? '') === title)
		if (found !== undefined) {
			return found
		}
		await new Promise((resolve) => setTimeout(resolve, 1000))
	}

	throw new Error(
		`${what}: no engine task titled "${title}" appeared on case ${caseId} `
			+ `within ${SETTLE_MS}ms. The engine answered with ${seen.length} task(s) `
			+ `on that case: ${JSON.stringify(seen)}. A task written to the deleted `
			+ '`caseTask` schema instead of the engine looks exactly like this.',
	)
}

/**
 * Advance every run that is due, by running the worker's job once.
 *
 * `signal()` does NOT walk the run. It records the payload, pulls `resumeAt`
 * forward to now and leaves the status at `suspended`; the walk belongs to
 * `FlowRunWorker`, which cron fires and a test cannot wait for. So the worker
 * is invoked directly, exactly as `case-flow-live-journeys.spec.ts` does
 * through `FLOW_WORKER_CMD`, except that the job id is discovered rather than
 * configured, so this spec needs no rig-specific environment variable.
 *
 * A job that cannot be found is a hard failure with the listing in hand. The
 * alternative is a test that reports "the run did not advance" when the truth
 * is that nothing tried to advance it.
 */
async function runFlowWorker(): Promise<void> {
	const listing = await occRun([
		'background-job:list',
		'--class',
		FLOW_WORKER_CLASS,
		'--output',
		'json',
	])
	expect(
		listing.code,
		`occ background-job:list for ${FLOW_WORKER_CLASS} exited ${listing.code}: ${listing.output}`,
	).toBe(0)

	const jobs: Json[] = (() => {
		try {
			const parsed = JSON.parse(listing.output.trim())
			return Array.isArray(parsed) ? parsed : Object.values(parsed as object)
		} catch {
			throw new Error(
				`occ background-job:list did not answer JSON for ${FLOW_WORKER_CLASS}. `
					+ `It said: ${listing.output}`,
			)
		}
	})()

	expect(
		jobs.length,
		`OpenRegister registers no ${FLOW_WORKER_CLASS} job on this instance, so no `
			+ 'parked run can ever be advanced. The listing was: '
			+ JSON.stringify(jobs),
	).toBeGreaterThan(0)

	for (const job of jobs) {
		const id = String(job.id ?? '')
		if (id === '') continue
		const executed = await occRun([
			'background-job:execute',
			id,
			'--force-execute',
		])
		expect(
			executed.code,
			`occ background-job:execute ${id} exited ${executed.code}: ${executed.output}`,
		).toBe(0)
	}
}

test.describe('Task cutover — a completed engine task resumes its run', () => {
	let api: APIRequestContext
	let token = ''

	/** Everything this file seeds, so afterEach can take it all down. */
	const seeded = {
		caseType: '',
		statusOpen: '',
		statusClosed: '',
		transitionCase: '',
		flowCase: '',
		flowId: '',
		runUuid: '',
	}

	/**
	 * Seeded per TEST, not once for the file.
	 *
	 * The teardown below removes everything, and it runs after every test so
	 * that a failing one still gives its fixtures back. Those two facts
	 * together rule out `beforeAll`: state built once and torn down after the
	 * first test leaves the second with a flow that answers 404, which reads as
	 * a broken run endpoint rather than as a fixture the teardown ate. Paying
	 * the seed twice is the cheaper mistake.
	 */
	test.beforeEach(async ({ baseURL }) => {
		// A case type, two statuses, a workflow template, two cases, a flow and
		// a publish. Comfortably past the default hook budget on a loaded rig.
		test.setTimeout(180_000)
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)

		// A case type this file OWNS, rather than one adopted from the
		// instance. The transition below has to carry a `createTask` action,
		// and a published type somebody else's spec is reading is not a place
		// to attach one.
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Task cutover`,
			identifier: `${RUN_PREFIX.toLowerCase()}-task-cutover`,
			description: 'Throwaway caseType seeded by task-cutover-resume.spec.ts.',
			// The schema defaults `isDraft` to true and `case.caseType` filters
			// on `isDraft: false`, so a type seeded without this is invisible to
			// the picker and to the transition engine.
			isDraft: false,
		})
		seeded.caseType = objectId(caseType)

		const open = await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Open`,
			caseType: seeded.caseType,
			order: 1,
			isFinal: false,
		})
		const closed = await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} Afgerond`,
			caseType: seeded.caseType,
			order: 2,
			isFinal: true,
		})
		seeded.statusOpen = objectId(open)
		seeded.statusClosed = objectId(closed)

		// The transition that creates a task as a side effect. `createTask` is
		// the action `CreateTaskHandler` serves, and after task 3.1 its only
		// write is `EngineTaskGateway::mirrorImport` — which is precisely what
		// test 1 goes looking for.
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Task cutover workflow`,
			caseType: seeded.caseType,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: 'close',
					label: 'Afronden',
					fromStatus: seeded.statusOpen,
					toStatus: seeded.statusClosed,
					guards: [],
					automaticActions: [
						{
							type: 'createTask',
							title: TRANSITION_TASK_TITLE,
							assignee: 'admin',
						},
					],
				},
			]),
		})

		const [transitionCase, flowCase] = await Promise.all([
			seedCase(api, token, {
				title: `${RUN_PREFIX} Case for the transition task`,
				caseType: seeded.caseType,
				status: seeded.statusOpen,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Case for the suspended run`,
				caseType: seeded.caseType,
				status: seeded.statusOpen,
				assignee: 'admin',
			}),
		])
		seeded.transitionCase = objectId(transitionCase)
		seeded.flowCase = objectId(flowCase)
		expect(seeded.transitionCase, 'The transition case must carry an id.').not.toBe('')
		expect(seeded.flowCase, 'The flow case must carry an id.').not.toBe('')

		// start -> ask a person -> mark the case -> end.
		//
		// The `dossiq.setField` step after the ask is what makes "the case
		// advanced" observable without a second worker concept: it is an
		// ordinary transition action, it runs only once the ask has produced an
		// answer, and it writes one field the test can read straight off the
		// case. A run that never resumed leaves that field untouched.
		const flow = await orPost(api, token, '/flows', {
			name: `${RUN_PREFIX} Task cutover resume`,
			description: 'Throwaway flow seeded by task-cutover-resume.spec.ts.',
			app: REGISTER,
			// Off, and it stays off. `enabled` decides whether a flow's TRIGGERS
			// are subscribed; a run asked for by hand does not consult it, so
			// this flow can never start on another worker's case.
			enabled: false,
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
						question: ASK_QUESTION,
						details: 'Seeded by the task cutover spec.',
						// A real uid, not `{{ case.assignee }}`. The engine's
						// resume guard compares this against the completer, and
						// an unrendered template refuses every real user.
						assignee: 'admin',
						heartbeatMinutes: HEARTBEAT_MINUTES,
					},
					position: { x: 0, y: 160 },
				},
				{
					id: 'mark-the-case',
					type: 'dossiq.setField',
					config: { field: 'description', value: ADVANCED_MARKER },
					position: { x: 0, y: 320 },
				},
				{
					id: 'end',
					type: 'openregister.end',
					config: {},
					position: { x: 0, y: 480 },
				},
			],
			edges: [
				{ id: 'e1', from: 'start', to: ASK_NODE },
				{ id: 'e2', from: ASK_NODE, to: 'mark-the-case' },
				{ id: 'e3', from: 'mark-the-case', to: 'end' },
			],
		})
		seeded.flowId = String(flow.uuid ?? '')
		expect(seeded.flowId, 'The created flow must carry a uuid.').not.toBe('')

		const published = await orPost(api, token, `/flows/${seeded.flowId}/publish`)
		expect(
			Number(published.version ?? 0),
			'Publishing must answer with the version it made: a run pinned to no '
				+ 'version cannot be resumed against the graph it started on.',
		).toBeGreaterThan(0)
	})

	/**
	 * Teardown, in `afterEach` rather than at the end of a test body so that a
	 * failing test still gives its fixtures back.
	 *
	 * It is idempotent and runs after every test in the file: the flow and its
	 * runs are removed once and the later passes find a 404, and
	 * `cleanupRunObjects` sweeps by prefix, so a second sweep enumerates and
	 * finds nothing. That is cheaper than the alternative, which is a
	 * teardown that only fires after the LAST test and therefore not at all
	 * when an earlier one throws.
	 */
	test.afterEach(async () => {
		if (api === undefined) return
		test.setTimeout(180_000)

		// The flow FIRST, because OpenRegister publishes no delete for a run:
		// `DELETE /api/flows/{id}` is the only route that removes one, by
		// sweeping the flow's runs, steps, state and versions.
		if (seeded.flowId !== '') {
			await api
				.delete(`${OR_API}/flows/${seeded.flowId}`, {
					headers: writeHeaders(token),
				})
				.catch(() => undefined)
		}

		// Engine tasks are not OpenRegister objects, so the prefix sweep below
		// cannot see them. `cleanupFlowTasks` cancels the ones this process
		// seeded through `seedFlowTask`; the tasks a transition and a flow node
		// wrote are cancelled here by uuid, because nothing seeded them.
		await cleanupFlowTasks(api, token)
		for (const caseId of [seeded.transitionCase, seeded.flowCase]) {
			if (caseId === '') continue
			const tasks = await tasksOnCase(api, caseId).catch(() => [])
			for (const task of tasks) {
				const uuid = String(task.uuid ?? '')
				if (uuid === '') continue
				await api
					.post(`${FLOW_TASKS_BASE}/${uuid}/cancel`, {
						headers: writeHeaders(token),
						data: {},
					})
					// A task an earlier test already completed answers 409 to a
					// cancel, and that is a fine outcome for a teardown.
					.catch(() => undefined)
			}
		}

		await cleanupRunObjects(api, token)
	})

	test('a status transition writes its task to the engine, and the register no longer holds one', async () => {
		const res = await api.post(
			`/index.php/apps/dossiq/api/case/${seeded.transitionCase}/transition`,
			{
				headers: writeHeaders(token),
				data: { transitionId: 'close', comment: `${RUN_PREFIX} closing` },
			},
		)
		expect(
			res.status(),
			`The seeded transition must be accepted: ${res.status()} ${await res.text()}`,
		).toBe(200)

		const task = await taskTitled(
			api,
			seeded.transitionCase,
			TRANSITION_TASK_TITLE,
			'the createTask action of a status transition',
		)

		// The engine stores the case as `objectUuid`, not as `case`: OpenRegister
		// has no case entity, the case IS the object. A fixture or a handler
		// still writing `case` seeds a column the inbox never filters on.
		expect(
			String(task.objectUuid ?? ''),
			'The task the transition created must hang off the case it was created for.',
		).toBe(seeded.transitionCase)
		expect(
			String(task.assignee ?? ''),
			'CreateTaskHandler resolves the assignee rather than copying it, so a '
				+ 'task created with a named assignee must carry that uid.',
		).toBe('admin')

		// And the other half of the cutover, asked rather than assumed: the
		// schema this task used to be written to is gone. A 404 here is the
		// pass. If this ever answers 200 again, something re-declared
		// `caseTask` and the two stores can start drifting.
		const register = await api.get(
			`${OR_API}/objects/${REGISTER}/caseTask?_limit=1`,
		)
		expect(
			register.ok(),
			'The `caseTask` schema was deleted by remove-casetask task 4.2. An '
				+ 'instance that still serves it holds a second task store that '
				+ 'nothing reads, which is the state the cutover existed to end.',
		).toBeFalsy()
	})

	test('completing a suspended run\'s task through the engine verb resumes the run and moves the case', async () => {
		// The seed, the completion and a worker pass, on a rig where a page
		// load is not the slow part but a flow walk can be.
		test.setTimeout(180_000)

		const run = await orPost(api, token, `/flows/${seeded.flowId}/run`, {
			subject: {
				uuid: seeded.flowCase,
				register: REGISTER,
				schema: 'case',
			},
			// Synchronous, so the walk reaches the human step and parks inside
			// this request. Without it the run sits queued and the task does not
			// exist yet, which would read as a broken node.
			sync: true,
		})
		seeded.runUuid = String(run.uuid ?? '')
		expect(seeded.runUuid, 'The run endpoint must answer with the run it made.').not.toBe('')

		expect(
			String(run.status ?? ''),
			'A run that walked onto `dossiq.askPerson` must be SUSPENDED. Any other '
				+ 'status means the human step did not park, so there is nothing for a '
				+ 'completed task to wake.',
		).toBe('suspended')

		const task = await taskTitled(
			api,
			seeded.flowCase,
			ASK_QUESTION,
			'the dossiq.askPerson node of a suspended run',
		)

		// The two fields that make this task an answer to a specific question
		// rather than a loose to-do. `TaskCompletionResumeListener` returns
		// early when either is empty, so a task missing one resumes nothing and
		// does it silently.
		expect(
			String(task.runUuid ?? ''),
			'The task must name the run it is blocking. Without it the listener '
				+ 'has no run to signal and returns without a word.',
		).toBe(seeded.runUuid)
		expect(
			String(task.nodeId ?? ''),
			'The task must name the NODE it answers. A run holds one resume slot '
				+ 'per node, so the run uuid alone cannot say which awaiting step '
				+ 'this is for, and the listener refuses to guess.',
		).toBe(ASK_NODE)
		expect(
			String(task.state ?? ''),
			'A freshly created engine task is not terminal.',
		).not.toBe('completed')

		// ── The run, as the server holds it before anything is completed. ────
		const before = await orGet(api, `/flow-runs/${seeded.runUuid}`)
		expect(
			String(before.status ?? ''),
			'The run must still be suspended before the task is completed.',
		).toBe('suspended')

		const parkedUntil = Date.parse(String(before.resumeAt ?? ''))
		expect(
			Number.isNaN(parkedUntil),
			'A suspended `dossiq.askPerson` run must carry a heartbeat to wake at.',
		).toBeFalsy()
		// The safety net is where it was configured, far outside this test. If
		// this ever fails, the rest of this test proves nothing: the worker
		// below would advance a run that was due anyway, and the resume
		// assertions would pass over a chain that never fired.
		expect(
			parkedUntil - Date.now(),
			`The heartbeat must be roughly ${HEARTBEAT_MINUTES} minutes out, so that `
				+ 'the only thing able to wake this run inside the test is the signal a '
				+ 'completed task sends.',
		).toBeGreaterThan(60 * 60 * 1000)

		const taskUuid = String(task.uuid ?? '')
		expect(taskUuid, 'The engine task must carry a uuid.').not.toBe('')

		// THE ENGINE'S OWN VERB, which is what the task pane's lifecycle button
		// calls. Writing a status field instead would exercise nothing: there is
		// no status field left to write, and `TaskTerminalEvent` is dispatched by
		// `TaskService` after the terminal write commits, not by an object update.
		await invokeFlowTask(api, token, taskUuid, 'complete')

		const stored = await orGet(api, `/flow-tasks/${taskUuid}`)
		expect(
			String((stored.results ?? stored)?.state ?? ''),
			'The complete verb must have STORED the terminal state. A verb that '
				+ 'answered and changed nothing dispatches no event, and the rest of '
				+ 'this test would then be measuring the wrong absence.',
		).toBe('completed')

		// ── Stage one: a signal landed, and the run is due. ──────────────────
		//
		// Read BEFORE the worker runs, because the worker consumes what it
		// finds: `FlowRunService` unsets the signal key from the run context as
		// it resumes, and clears `resumeAt`. This is the only point at which
		// "the completion reached the run" can be told apart from "the run
		// happened to be due".
		//
		// 🔴 THE SIGNAL'S PAYLOAD IS NOT ASSERTED, AND THAT IS A FINDING RATHER
		// THAN AN OVERSIGHT. Two listeners answer `TaskTerminalEvent` on any
		// instance running both apps: dossiq's `TaskCompletionResumeListener`,
		// which signals through the guarded `FlowRunSignalService::signalAs()`
		// with `{decision, node, taskId, completedBy}`, and OpenRegister's own
		// `UserTaskTerminalListener`, which hands every task carrying a
		// `runUuid` to `FlowTaskBridge::continueRun()` and signals it with an
		// EMPTY payload. `FlowRunService::signal()` overwrites `context.signal`
		// rather than merging, so whichever listener runs second decides what
		// the run ends up carrying — and measured on a clean rig
		// (openregister 2.0.18, dossiq 0.4.10, 2026-09-11) it is OpenRegister's:
		// the run's context reads `"signal": []` with dossiq's listener both
		// connected and disconnected. Asserting the payload here would fail on
		// a correct instance. The file header records the measurement in full.
		const signalled = await orGet(api, `/flow-runs/${seeded.runUuid}`)
		const wokenAt = Date.parse(String(signalled.resumeAt ?? ''))
		expect(
			Number.isNaN(wokenAt),
			'A signalled run must still carry a resumeAt.',
		).toBeFalsy()
		expect(
			wokenAt - Date.now(),
			'Signalling pulls `resumeAt` forward to now, which is what makes the '
				+ "worker's next pass pick the run up. A resumeAt still a day out means "
				+ 'the completion never reached the run at all, and the case would sit '
				+ 'until the heartbeat.',
		).toBeLessThan(60 * 1000)

		// ── Stage two: the run actually moved, and so did the case. ──────────
		//
		// Stage one proves the listener spoke. This proves the run heard it.
		// Both are kept: a signal recorded on a run that then never walks is a
		// different defect from a listener that never fires, and a spec that
		// asserted only the end state could not tell them apart.
		await runFlowWorker()

		const after = await orGet(api, `/flow-runs/${seeded.runUuid}`)
		expect(
			String(after.status ?? ''),
			'Once the task it was waiting on is completed and the worker has made a '
				+ 'pass, the run must have left `suspended`. A run still suspended here '
				+ 'is the exact failure this spec exists to catch: the task closed, '
				+ 'nobody was told, and the case stopped moving with no error anywhere.',
		).not.toBe('suspended')

		const caseAfter = await showObject(api, 'case', seeded.flowCase)
		expect(
			String(caseAfter.description ?? ''),
			'The step AFTER the human one must have run, so the case carries what '
				+ 'the flow wrote. This is the half a person would notice: a case that '
				+ 'moves on once its question has been answered.',
		).toBe(ADVANCED_MARKER)
	})
})
