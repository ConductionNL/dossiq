/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TRD-01 to REQ-TRD-03 and REQ-OBL-01: a transition declares what must be
 * settled before it is available, what it explains, and who may not take it;
 * and an obligation is one declared mechanism rather than one service per kind.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Each crosses a seam that only a
 * real instance exercises, and each fails SILENTLY in the direction that looks
 * like the product working:
 *
 *  - a WITHHELD transition is an absence. A backend that never evaluated the
 *    declaration produces the same list as one that evaluated it and found
 *    nothing open, and the unit tests cannot tell those apart because they
 *    hand the evaluation its own answer. Only a real open obligation, written
 *    to a real register, shows the move actually leaving the list;
 *  - the four-eyes rule reads the case's own statusRecord chain, which means
 *    it depends on `actor` being WRITTEN there by the engine. A schema that
 *    did not take the new property refuses nobody, and refusing nobody is
 *    exactly what a case with no earlier act looks like;
 *  - the obligation schema is new. An instance whose register import skipped
 *    it answers every obligation read with an empty list, and an empty list is
 *    a valid-looking answer to "what is this case waiting on".
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never decides for itself
 * whether a status closes a case and compares that with the engine. That would
 * be a second implementation of the question `CaseResultWriter` owns, written
 * in a test file, and it would eventually disagree and be "fixed" in whichever
 * direction was easier. It asserts the SHAPE: the move is gone, the reason is
 * published in its place, and settling the obligation brings the move back.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'

test.describe('A transition declares what it demands', () => {
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
	 * A case whose closing transition waits on an obligation of one kind.
	 *
	 * @param kind The obligation kind the closing move declares.
	 */
	async function seedWithDependency(kind: string) {
		const machine = await seedStateMachine(api, token)

		const transitions = [
			{
				id: 't1',
				label: 'Start behandeling',
				fromStatus: machine.statusReceived,
				toStatus: machine.statusInProgress,
				guards: [],
			},
			{
				id: 't2',
				label: 'Afhandelen',
				fromStatus: machine.statusInProgress,
				toStatus: machine.statusDone,
				guards: [],
				requiresSettled: [{ kind: 'obligationOpen', obligationKind: kind }],
			},
		]
		const wf = await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Workflow with a dependency`,
			caseType: machine.caseTypeId,
			isActive: true,
			isDraft: false,
			version: 2,
			transitions: JSON.stringify(transitions),
		})
		expect(objectId(wf)).toBeTruthy()

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} ${kind} dependency`,
			caseType: machine.caseTypeId,
			status: machine.statusInProgress,
		})

		return { machine, caseId: objectId(seeded) }
	}

	test('an open obligation withholds the closing move, and the reason is published', async () => {
		const { caseId } = await seedWithDependency('advice')

		// Before: nothing is open, so the move is on offer. This is the
		// CONTROL: without it, a move missing for an unrelated reason would
		// read as the declaration working.
		const before = await getAvailableTransitions(api, token, caseId)
		expect(before.status).toBe(200)
		expect(before.body.transitions.map((entry: any) => entry.id)).toContain('t2')
		expect(before.body.withheld ?? []).toEqual([])

		await createObject(api, token, 'obligation', {
			case: caseId,
			kind: 'advice',
			title: 'the advice request',
			state: 'open',
			blocks: ['closing'],
		})

		const withheld = await getAvailableTransitions(api, token, caseId)
		expect(withheld.body.transitions.map((entry: any) => entry.id)).not.toContain('t2')
		const entry = (withheld.body.withheld ?? []).find((row: any) => row.id === 't2')
		expect(entry, 'the withheld move is published with its reason').toBeTruthy()
		expect(entry.reasons).toContain('the advice request')
	})

	test('settling the obligation restores the transition', async () => {
		const { caseId } = await seedWithDependency('inspection')

		const obligation = await createObject(api, token, 'obligation', {
			case: caseId,
			kind: 'inspection',
			title: 'the inspection',
			state: 'open',
			blocks: ['closing'],
		})

		const blocked = await getAvailableTransitions(api, token, caseId)
		expect(blocked.body.transitions.map((entry: any) => entry.id)).not.toContain('t2')

		await updateObject(api, token, 'obligation', objectId(obligation), { state: 'met' })

		const released = await getAvailableTransitions(api, token, caseId)
		expect(released.body.transitions.map((entry: any) => entry.id)).toContain('t2')
		expect(released.body.withheld ?? []).toEqual([])
	})

	test('a withdrawn obligation releases the case and stays readable as a withdrawal', async () => {
		const { caseId } = await seedWithDependency('fee')

		const obligation = await createObject(api, token, 'obligation', {
			case: caseId,
			kind: 'fee',
			title: 'the outstanding fee',
			state: 'open',
			blocks: ['closing'],
		})

		const withdrawn = await updateObject(api, token, 'obligation', objectId(obligation), {
			state: 'withdrawn',
			withdrawalReason: 'The fee was waived.',
		})

		// Released, and NOT recorded as met: an inspection nobody carried out
		// is not an inspection that passed, and the reason survives.
		expect(withdrawn.state).toBe('withdrawn')
		expect(String(withdrawn.withdrawalReason ?? '')).toContain('waived')
		expect(String(withdrawn.metAt ?? '')).toBe('')

		const released = await getAvailableTransitions(api, token, caseId)
		expect(released.body.transitions.map((entry: any) => entry.id)).toContain('t2')
	})

	test('the handler reads the explanation at the moment of choosing', async () => {
		const machine = await seedStateMachine(api, token)
		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Workflow with an explanation`,
			caseType: machine.caseTypeId,
			isActive: true,
			isDraft: false,
			version: 2,
			transitions: JSON.stringify([
				{
					id: 't1',
					label: 'Start behandeling',
					fromStatus: machine.statusReceived,
					toStatus: machine.statusInProgress,
					guards: [],
					explanation: 'Check the drawings before you send this out.',
				},
			]),
		})

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} explanation`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const answer = await getAvailableTransitions(api, token, objectId(seeded))
		const offered = answer.body.transitions.find((entry: any) => entry.id === 't1')
		expect(offered).toBeTruthy()
		expect(offered.explanation).toBe('Check the drawings before you send this out.')
	})

	test('the status explanation reaches the case', async () => {
		const machine = await seedStateMachine(api, token)
		await updateObject(api, token, 'statusType', machine.statusReceived, {
			description: 'The application has arrived and nobody has looked at it yet.',
		})

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} status explanation`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const answer = await getAvailableTransitions(api, token, objectId(seeded))
		expect(answer.body.current.statusDescription).toContain('nobody has looked at it')
	})

	test('the engine records who made each move, which is what four eyes reads', async () => {
		// @e2e exclude the refusal itself needs a SECOND signed-in identity,
		// and this suite has one. What it can prove, and what the rule is
		// worthless without, is that the actor is written at all: a schema
		// that did not take the property refuses nobody, and refusing nobody
		// looks exactly like a case with no earlier act. The refusal and the
		// colleague who may proceed are unit-tested in
		// tests/Unit/Service/Obligations/FourEyesTransitionTest.php.
		const machine = await seedStateMachine(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} four eyes actor`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const caseId = objectId(seeded)

		const move = await api.post(
			`/index.php/apps/dossiq/api/case/${caseId}/transition`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { transitionId: 't1' },
			},
		)
		expect(move.status()).toBe(200)

		const history = await api.get(
			`/index.php/apps/dossiq/api/case/${caseId}/transition-history`,
			{ headers: { requesttoken: token } },
		)
		expect(history.status()).toBe(200)

		const records = (await history.json()).history ?? []
		const moved = records.find((row: any) => String(row.transitionLabel ?? '') !== '')
		expect(moved, 'the move was recorded').toBeTruthy()
		expect(String(moved.actor ?? ''), 'the record names who made the move').not.toBe('')
	})
})
