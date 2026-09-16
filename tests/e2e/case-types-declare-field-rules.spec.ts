/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SDC-04 and REQ-SDC-05: a status declares which case fields it requires,
 * hides and locks, and a case type's rules are listed and tried in the editor.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. dossiq declares and OpenRegister
 * refuses, which means every assertion worth making here spans two apps and a
 * schema write. Each half fails silently in the direction that looks like the
 * product working:
 *
 *  - the projection writes states onto the LIVE case schema, keyed by
 *    statusType uuid. A state keyed by anything else is not refused by
 *    anything: OpenRegister reads the object's `status`, finds no state of
 *    that name, and saves. The case keeps saving and the administrator keeps
 *    believing the field is locked;
 *  - `@self.fieldRules` is attached on OpenRegister's render path. An instance
 *    whose render does not attach it answers a case with no key at all, and a
 *    strip that renders nothing is indistinguishable from a status that asks
 *    nothing;
 *  - the rules inventory is a PROJECTION over four places a rule lives. A rule
 *    that is declared but not in the inventory, and a rule in the inventory
 *    that is declared nowhere, are both invisible from the editor.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never evaluates a condition
 * of its own and compares the answer with OpenRegister's. That would be the
 * second evaluator this whole change exists to avoid, written in a test file,
 * and the day the two disagreed the test would be "fixed" in whichever
 * direction was easier. What it asserts is that the platform refused, and with
 * which code.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'

test.describe('A case type declares field rules per status', () => {
	test.setTimeout(240_000)

	let api: APIRequestContext
	let token = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Publish a seeded case type, which is what writes the states.
	 *
	 * @param caseTypeId The case type to publish.
	 */
	async function publish(caseTypeId: string) {
		const res = await api.post(
			`/index.php/apps/dossiq/api/case-types/${caseTypeId}/publish`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { changeNote: 'field rules e2e' },
			},
		)
		expect(
			res.ok(),
			`publish -> ${res.status()} ${await res.text()}`,
		).toBeTruthy()
		return res.json()
	}

	test('a status that requires a field refuses a save without it', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusInProgress, {
			fieldRules: [
				{
					rule: 'required',
					field: 'description',
					message:
						'Write down what this case is about before taking it on.',
				},
			],
		})

		await publish(machine.caseTypeId)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} required field`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		// Moving into the status without the field is the refusal. The code is
		// asserted and not only the status, because a 422 from the schema
		// validator and a 422 from the state rules are the same number and a
		// very different bug.
		const refused = await api.put(
			`/index.php/apps/openregister/api/objects/dossiq/case/${objectId(seeded)}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					...seeded,
					description: '',
					status: machine.statusInProgress,
				},
			},
		)

		expect(refused.status()).toBe(422)
		const body = await refused.json()
		expect(body.errors?.code).toBe('state-field-required')
		expect(body.errors?.field).toBe('description')
		expect(body.errors?.message).toContain('Write down what this case is about')
	})

	test('the same move succeeds once the field is filled in', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusInProgress, {
			fieldRules: [{ rule: 'required', field: 'description' }],
		})
		await publish(machine.caseTypeId)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} filled field`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const accepted = await api.put(
			`/index.php/apps/openregister/api/objects/dossiq/case/${objectId(seeded)}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					...seeded,
					description: 'What this case is about.',
					status: machine.statusInProgress,
				},
			},
		)

		expect(accepted.ok()).toBeTruthy()
	})

	test('the case read carries the decision the page renders', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusReceived, {
			fieldRules: [{ rule: 'readOnly', field: 'identifier' }],
		})
		await publish(machine.caseTypeId)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} published rules`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const read = await api.get(
			`/index.php/apps/openregister/api/objects/dossiq/case/${objectId(seeded)}`,
			{ headers: { requesttoken: token } },
		)
		const body = await read.json()

		// The strip renders this and nothing else. A missing key here is the
		// silent half: it reads on screen exactly like a status that asks
		// nothing of any field.
		expect(body['@self']?.fieldRules).toBeDefined()
		expect(body['@self'].fieldRules.readOnly).toContain('identifier')
	})

	test('a property required from a status is enforced, not just displayed', async () => {
		const machine = await seedStateMachine(api, token)

		// `requiredAtStatus` has been in the Properties tab since case types
		// shipped and was enforced by nothing. This is the assertion that it
		// now means something.
		await api.post(
			'/index.php/apps/openregister/api/objects/dossiq/propertyDefinition',
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					name: 'description',
					caseType: machine.caseTypeId,
					propertyType: 'string',
					requiredAtStatus: machine.statusDone,
				},
			},
		)

		await publish(machine.caseTypeId)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} required at status`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const refused = await api.put(
			`/index.php/apps/openregister/api/objects/dossiq/case/${objectId(seeded)}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { ...seeded, description: '', status: machine.statusDone },
			},
		)

		expect(refused.status()).toBe(422)
		expect((await refused.json()).errors?.code).toBe('state-field-required')
	})

	test('the rules of the case schema are listed in evaluation order', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusInProgress, {
			fieldRules: [{ rule: 'hidden', field: 'description' }],
		})
		await publish(machine.caseTypeId)

		const inventory = await api.get(
			'/index.php/apps/openregister/api/schemas/case/rules',
			{ headers: { requesttoken: token } },
		)

		expect(inventory.ok()).toBeTruthy()
		const body = await inventory.json()
		const ids = (body.rules ?? []).map((rule: any) => String(rule.id))

		// Keyed on the id and never on the position: the order is a property
		// of the pipeline, and it moves the day a kind is added.
		expect(ids).toContain(`stateFieldRule:case:${machine.statusInProgress}`)
	})

	test('trying a rule says what decided, and writes nothing', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusReceived, {
			fieldRules: [{ rule: 'required', field: 'description' }],
		})
		await publish(machine.caseTypeId)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} rule trial`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const caseId = objectId(seeded)

		const trial = await api.post(
			`/index.php/apps/openregister/api/schemas/case/rules/${encodeURIComponent(`stateFieldRule:case:${machine.statusReceived}`)}/evaluate`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { register: 'dossiq', objectId: caseId },
			},
		)

		expect(trial.ok()).toBeTruthy()
		const body = await trial.json()
		expect(body.committed).toBe(false)
		expect(body.trace?.verdict).toBeTruthy()

		// Nothing written: the case comes back exactly as it went in.
		const after = await api.get(
			`/index.php/apps/openregister/api/objects/dossiq/case/${caseId}`,
			{ headers: { requesttoken: token } },
		)
		expect((await after.json()).title).toBe(`${RUN_PREFIX} rule trial`)
	})
})
