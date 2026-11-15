import type { APIRequestContext } from '@playwright/test'

/**
 * A task created by a flow step, completed WITH ITS FORM, resuming the run.
 *
 * This is the middle third of `dossiq-duplication-to-abstractions` task 2.8.
 * The other two thirds have their own files: `checklist-per-status.spec.ts`
 * creates a task through a real transition, and
 * `task-completion-resumes-the-run.spec.ts` wakes a suspended run with a
 * form-less completion. What neither does is the one thing `caseTask` could
 * never do and the engine `Task` can: record the ANSWER, not merely the fact
 * that somebody answered.
 *
 * `ask-step-form.spec.ts` proves the declaration survives a save and a
 * publish. It never starts a run, so it never meets the task. This file does:
 * the ask declares `description` on the case as required, the run parks on the
 * task, and the completion goes through OpenRegister's `complete` verb with a
 * `data` object, the same key the object transition endpoint uses.
 *
 * 🔴 THE REFUSAL IS DRIVEN FIRST. A completion that leaves the required field
 * out must be refused before the one that fills it is accepted. A test that
 * only completes happily passes just as well when the requirement stops being
 * enforced, and then the run walks past a question nobody answered.
 *
 * 🔴 THE ANSWER IS READ BACK FROM THE CASE, NOT FROM THE COMPLETION RESPONSE.
 * A `fields` form over the case schema writes its values onto the case. A
 * response that echoes the payload proves nothing about the write, so the
 * case is fetched afresh and its `description` compared.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Every assertion is preceded by
 * one that the thing it needs exists. A skip cannot tell "not seeded" from
 * "the seeder is broken" and reports the second as a pass.
 *
 * NOT RUN IN THE BUILD LANE (decision 139). The live pass owns the run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 * @spec openspec/specs/task-management/spec.md
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
	showObject,
} from './helpers/fixtures.ts'
import {
	advanceFlowRun,
	createFlow,
	publishFlow,
	readFlowRun,
	removeFlow,
	runFlow,
} from './helpers/flows.ts'

test.describe.configure({ mode: 'serial', timeout: 180_000 })

/** The node id the ask carries, and therefore the one the task must name. */
const ASK_NODE = 'ask-with-a-form'

/** The question the ask puts, which becomes the task's title. */
const QUESTION = `${RUN_PREFIX} Write down what the belanghebbende said`

/** What the assignee writes into the form. */
const ANSWER = `${RUN_PREFIX} Gehoord op het gemeentehuis, bezwaar blijft staan`

/**
 * The smallest flow that asks a person for a field: a manual start, one ask
 * that declares `description` on the case as required, an end.
 *
 * The form keys are FLAT on the node config. A nested `form` block would
 * resolve to no form at all, and the refusal below would then fail for the
 * wrong reason.
 *
 * @param assignee The uid the ask is addressed to.
 * @return The flow graph.
 */
function graphFor(assignee: string) {
	return {
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
					details: 'Seeded by task-completed-with-a-form.spec.ts.',
					assignee,
					signalKey: 'answer',
					formKind: 'fields',
					formSchema: 'case',
					formFields: [{ field: 'description', required: true }],
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
}

let api: APIRequestContext
let token = ''
let currentUser = ''

const seeded = { flowId: '', run: '', caseId: '', seededCaseType: '', task: '' }

test.beforeAll(async ({ baseURL }) => {
	test.setTimeout(180_000)
	api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
	token = await getRequestToken(api)

	// The engine refuses a completion by anyone but the recorded assignee, so
	// the ask is addressed to whoever the session really is.
	const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
	currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
	expect(currentUser, 'The session must resolve to a user id.').not.toBe('')

	const caseType = await ensureCaseType(api, token)
	if (caseType.seeded === true) {
		seeded.seededCaseType = caseType.id
	}

	seeded.caseId = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} The ask that is answered with a form`,
			caseType: caseType.id,
			assignee: currentUser,
		}),
	)

	seeded.flowId = await createFlow(
		api,
		token,
		`${RUN_PREFIX} Ask with a form`,
		'Throwaway flow seeded by task-completed-with-a-form.spec.ts.',
		graphFor(currentUser),
	)
	await publishFlow(api, token, seeded.flowId)
})

test.afterAll(async () => {
	if (!api) return
	test.setTimeout(180_000)

	const survivors: string[] = []
	try {
		// A task already terminal answers a conflict on cancel, which is the
		// expected outcome once the test completed it, so that is swallowed.
		if (seeded.task !== '') {
			await api
				.post(`${FLOW_TASKS_BASE}/${seeded.task}/cancel`, {
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
				...(await removeFlow(api, token, seeded.flowId, [seeded.run])),
			)
		}

		for (const [label, id] of [
			['case', seeded.caseId],
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

test.describe('A task answered with its form records the answer and wakes the run', () => {
	test('the ask parks its run on one task for the assignee', async () => {
		const started = await runFlow(api, token, seeded.flowId, {
			uuid: seeded.caseId,
			register: 'dossiq',
			schema: 'case',
		})
		seeded.run = String(started.uuid ?? '')
		expect(
			seeded.run,
			'The run endpoint must answer with the run it made.',
		).not.toBe('')

		const run = await advanceFlowRun(api, seeded.run)
		expect(
			String(run.status ?? ''),
			`The run must park on the ask. Log: ${JSON.stringify(run.log ?? [])}`,
		).toBe('suspended')

		const rows = await listFlowTasks(api, {
			objectUuid: seeded.caseId,
			scope: 'all',
			limit: '50',
		})
		expect(
			rows.map((row: any) => String(row.title ?? '')),
			'The ask must have created exactly one engine task on its case.',
		).toHaveLength(1)

		const task = rows[0]
		expect(
			String(task.runUuid ?? ''),
			'A task that names no run can never wake one.',
		).toBe(seeded.run)
		expect(
			String(task.nodeId ?? ''),
			'The engine resolves the form through the node id.',
		).toBe(ASK_NODE)
		expect(String(task.assignee ?? '')).toBe(currentUser)

		seeded.task = String(task.uuid ?? '')
		expect(seeded.task, 'The engine task must carry a uuid.').not.toBe('')
	})

	// @e2e openspec/specs/case-flow-human-steps/spec.md#a-declared-field-reaches-the-person-who-has-to-answer-it
	test('completing it without the required field is refused, naming the field', async () => {
		expect(seeded.task, 'The previous test must have found the task.').not.toBe(
			'',
		)

		const res = await api.post(`${FLOW_TASKS_BASE}/${seeded.task}/complete`, {
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: { outcome: 'done', data: {} },
		})
		const body = await res.text()

		expect(
			res.ok(),
			`A completion that leaves a required field blank must be refused. Got ${res.status()} ${body}`,
		).toBeFalsy()
		expect(
			body,
			'The refusal must name the field the assignee left out.',
		).toContain('description')

		// Refused means the run did not move either.
		const run = await readFlowRun(api, seeded.run)
		expect(
			String(run.status ?? ''),
			'A refused completion must leave the run waiting.',
		).toBe('suspended')
	})

	test('completing it with the field records the answer on the case', async () => {
		await invokeFlowTask(api, token, seeded.task, 'complete', {
			outcome: 'done',
			data: { description: ANSWER },
		})

		const kase = await showObject(api, 'case', seeded.caseId)
		expect(
			String(kase.description ?? ''),
			'The answer must be written onto the case the form is declared over, not only onto the task.',
		).toBe(ANSWER)
	})

	test('and one worker pass carries the run past the ask to a terminal state', async () => {
		const run = await advanceFlowRun(api, seeded.run)

		// A run that ends on `openregister.end` finishes as `stopped`, which is
		// a different terminal state from `completed`; both mean nobody is
		// waiting any more.
		expect(
			['completed', 'stopped'],
			`The answered run must reach a terminal state. Log: ${JSON.stringify(run.log ?? [])}`,
		).toContain(String(run.status ?? ''))

		const steps = (run.log ?? []) as Array<Record<string, unknown>>
		const advanced = steps.filter(
			(step) =>
				String(step.transition ?? '') === ASK_NODE
				&& String(step.status ?? '') === 'completed',
		)
		expect(advanced.length, 'The ask must have advanced exactly once.').toBe(1)
	})
})
