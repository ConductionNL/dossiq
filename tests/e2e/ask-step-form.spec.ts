import type { APIRequestContext } from '@playwright/test'

/**
 * An ask step asks for fields, and the engine resolves them.
 *
 * A flow asks somebody to hear a belanghebbende and the step says one thing:
 * the question. Whatever the handler writes down lands in a comment, a note, or
 * nowhere. The same instance already has the better behaviour one step to the
 * left: a task raised by a status transition carries a declared form and
 * refuses a completion that leaves a required field blank.
 *
 * 🔴 THE REFUSAL IS DRIVEN FIRST, DELIBERATELY. A test that completes the task
 * happily and never tries to skip the field passes just as well when the
 * requirement stops being enforced. So every case below asserts what is
 * REFUSED before it asserts what is accepted.
 *
 * 🔴 THE KEYS ARE FLAT, AND A NESTED `form` BLOCK IS THE TRAP. A task raised by
 * a status transition carries `task.form`. A task raised by a FLOW NODE does
 * not: the engine resolves its form from the run's pinned graph, reading
 * `node.config` through `TaskFormReader::fromConfig()`, which reads `formKind`,
 * `formSchema`, `formAction`, `formFields`, `formId` and
 * `formRequireChecklist`. A nested block would leave `formKind` absent, produce
 * a declaration with no form, and hand the assignee a task with no fields and
 * no error anywhere. The last test drives that shape and asserts the save is
 * refused.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Where a flow, a run or a task is
 * missing, these fail naming what was absent rather than skipping, because a
 * skip cannot tell "not seeded" from "the seeder is broken" and reports the
 * second as a pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth.ts'
import { getRequestToken, RUN_PREFIX } from './helpers/fixtures.ts'
import { createFlow, OR_API, publishFlow, removeFlow } from './helpers/flows.ts'

let api: APIRequestContext
let token: string

/** Every flow this spec created, removed however it ends. */
const created: string[] = []

/**
 * The graph of a one-ask flow, with whatever form keys on the ask step.
 *
 * @param form The form keys the ask step declares.
 * @return The flow graph.
 */
function askGraph(form: Record<string, unknown>) {
	return {
		nodes: [
			{
				id: 'start',
				type: 'openregister.trigger-manual',
				config: {},
				position: { x: 0, y: 0 },
			},
			{
				id: 'ask',
				type: 'dossiq.askPerson',
				config: {
					question: 'Hoor de belanghebbende',
					details: 'Leg het gesprek vast.',
					assignee: 'admin',
					...form,
				},
				position: { x: 200, y: 0 },
			},
			{
				id: 'end',
				type: 'openregister.end',
				config: {},
				position: { x: 400, y: 0 },
			},
		],
		edges: [
			{ id: 'e1', source: 'start', target: 'ask' },
			{ id: 'e2', source: 'ask', target: 'end' },
		],
	}
}

/**
 * Save a one-ask flow and say whether the instance accepted it.
 *
 * @param form The form keys the ask step declares.
 * @return The response status and body text.
 */
async function saveAsk(
	form: Record<string, unknown>,
): Promise<{ ok: boolean; body: string }> {
	const name = `${RUN_PREFIX}-ask-form-${Math.floor(Math.random() * 1e4)}`
	try {
		const id = await createFlow(
			api,
			token,
			name,
			'ask step form declaration',
			askGraph(form),
		)
		created.push(id)
		const res = await api.post(`${OR_API}/flows/${id}/validate`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {},
		})

		return { ok: res.ok(), body: await res.text() }
	} catch (e) {
		return { ok: false, body: String(e) }
	}
}

test.beforeAll(async () => {
	api = await request.newContext({ storageState: STORAGE_STATE })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	for (const id of created) {
		// No runs to read back: this suite only saves flow DECLARATIONS and
		// never starts one. The argument is explicit rather than defaulted,
		// so a suite that does make runs cannot skip its own leak check by
		// leaving it out.
		await removeFlow(api, token, id, [])
	}
	await api.dispose()
})

test.describe('a form the performer could not fill is refused at save', () => {
	// @e2e openspec/specs/case-flow-human-steps/spec.md#scenario-a-field-the-schema-does-not-have-is-refused-at-save
	test('a field the case schema does not have is refused, naming it', async () => {
		const { ok, body } = await saveAsk({
			formKind: 'fields',
			formSchema: 'case',
			formFields: [{ field: 'verslagje', required: true }],
		})

		expect(
			ok,
			'a step declaring a field the schema has no property for was accepted',
		).toBeFalsy()
		expect(body, 'the refusal does not name the field').toContain('verslagje')
		expect(body, 'the refusal does not name the schema').toContain('case')
	})

	// @e2e openspec/specs/case-flow-human-steps/spec.md#scenario-a-read-only-field-is-refused-at-save
	test('a generated field nobody can write is refused, and says so', async () => {
		const { ok, body } = await saveAsk({
			formKind: 'fields',
			formSchema: 'case',
			formFields: [{ field: 'identifier', required: true }],
		})

		expect(ok, 'a step declaring a read-only field was accepted').toBeFalsy()
		expect(body).toContain('identifier')
		expect(body, 'the refusal does not say why nobody can write it').toMatch(
			/read-only|read only/i,
		)
	})

	// @e2e openspec/specs/case-flow-human-steps/spec.md#scenario-a-field-the-schema-does-not-have-is-refused-at-save
	test('a nested form block is refused rather than silently ignored', async () => {
		const { ok } = await saveAsk({
			form: {
				kind: 'fields',
				schema: 'case',
				fields: [{ field: 'verslag', required: true }],
			},
			formSchema: 'case',
			formFields: [{ field: 'verslag', required: true }],
		})

		// `formKind` is absent, so the engine would resolve NO form. The
		// orphaned keys are what turn "read by nothing" into a refusal the
		// author can act on; without it an ask step copied from the
		// transition path ships a task with no fields.
		expect(
			ok,
			'a step whose form keys name no kind was accepted, so the assignee would get a task with no fields',
		).toBeFalsy()
	})
})

test.describe('a declared field reaches the person who has to answer it', () => {
	// @e2e openspec/specs/case-flow-human-steps/spec.md#scenario-a-declared-field-reaches-the-person-who-has-to-answer-it
	test('a writable field is accepted, published and resolved on the task', async () => {
		const name = `${RUN_PREFIX}-ask-form-ok-${Math.floor(Math.random() * 1e4)}`
		const id = await createFlow(
			api,
			token,
			name,
			'ask step form declaration',
			askGraph({
				formKind: 'fields',
				formSchema: 'case',
				formFields: [{ field: 'description', required: true }],
			}),
		)
		created.push(id)

		await publishFlow(api, token, id)

		const res = await api.get(`${OR_API}/flows/${id}`, {
			headers: { requesttoken: token },
		})
		expect(res.ok(), `reading the flow answered ${res.status()}`).toBeTruthy()
		const flow = await res.json()
		const ask = (flow.nodes ?? []).find((n: any) => n.id === 'ask')

		expect(ask, 'the published flow has no ask step').toBeTruthy()
		expect(
			ask.config.formKind,
			'the form keys did not survive the save, so the engine would resolve no form',
		).toBe('fields')
		expect(ask.config.formFields[0].field).toBe('description')
	})

	// @e2e openspec/specs/case-flow-human-steps/spec.md#scenario-a-step-with-no-form-is-unchanged
	test('a step with no form is accepted exactly as before', async () => {
		const { ok } = await saveAsk({})

		expect(
			ok,
			'a step that declares no form was refused, which would break every flow authored before this change',
		).toBeTruthy()
	})
})
