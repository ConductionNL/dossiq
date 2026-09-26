/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A refusal reaches the caller with a status, over the wire.
 *
 * A unit test can show that the controller translates a RefusedException. Only
 * a real request shows what a client actually receives: the status on the
 * response line, the rule in `error`, and the case still on the status it
 * started on. Those three together are the claim row Q10.14 makes, and no
 * layer below this one can make it.
 *
 * THE FIXTURE IS `seedStateMachine`, and the case stays on its FIRST status.
 * That machine declares two moves: t1 from Ontvangen to In behandeling, and t2
 * from In behandeling to Afgehandeld (final). Asking for t2 while the case is
 * still on Ontvangen is exactly the spec's "a case in a status with no
 * transition to Closed": the engine refuses on the from-status rule rather
 * than on a guard, which is the refusal this change converted.
 *
 * WHY THE STATUS-UNCHANGED HALF IS NOT DECORATION. A refusal that answers 409
 * and writes anyway is worse than one that answers 200, because the status
 * says the write did not happen. Reading the case back after the refusal is
 * the only assertion that can tell those apart.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	executeTransition,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
} from './helpers/fixtures.ts'

/** The closing move, which only starts from In behandeling. */
const CLOSING_TRANSITION = 't2'

/** A transition id no template declares. */
const UNKNOWN_TRANSITION = 't-does-not-exist'

let caseOnFirstStatus = ''
let statusReceived = ''

test.describe('A refusal carries a status', () => {
	test.setTimeout(120_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		statusReceived = machine.statusReceived

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} refusal status`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		caseOnFirstStatus = objectId(seeded)

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md#a-refused-transition-tells-the-caller
	test('a closing move the case cannot take answers 409 naming the rule', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const refused = await executeTransition(
			api,
			token,
			caseOnFirstStatus,
			CLOSING_TRANSITION,
			'e2e: closing from the wrong status',
		)

		expect(
			refused.status,
			"a move that does not start from the case's status is a conflict, not a bad request",
		).toBe(409)
		expect(
			refused.body?.error,
			'`error` carries the rule slug, not a sentence (ADR-050)',
		).toBe('transition-from-status-mismatch')
		expect(
			String(refused.body?.message ?? ''),
			'`message` carries a sentence a handler can read',
		).not.toBe('')
		expect(
			String(refused.body?.message ?? ''),
			'the sentence is prose, never the rule slug repeated',
		).not.toBe(refused.body?.error)

		// The write did not happen. A 409 on a case that moved anyway would be
		// a worse defect than the one this change fixes.
		const after = await showObject(api, 'case', caseOnFirstStatus)
		expect(String(after.status ?? ''), 'the refused case has not moved').toBe(
			statusReceived,
		)

		await api.dispose()
	})

	// @e2e openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md#a-refused-transition-tells-the-caller
	test('a move the template never declared is a 404, not the same 409', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const missing = await executeTransition(
			api,
			token,
			caseOnFirstStatus,
			UNKNOWN_TRANSITION,
		)

		// The control that keeps the test above honest: if every refusal
		// answered 409, the assertion there would pass without measuring
		// anything. A transition that does not exist is not a refusal, and the
		// engine still says so with a different status.
		expect(missing.status, 'an undeclared transition is not found').toBe(404)

		await api.dispose()
	})
})
