/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-STE-40 to REQ-STE-42: a status holds a limit, and the case past it is
 * refused.
 *
 * 🔴 A LIMIT THAT DOES NOT REFUSE IS DECORATION, and that was measured rather
 * than argued. Gap register row Q3.22: Kanboard colours a full column's header
 * and lets a second card into a column with a limit of one; Vikunja answers
 * 412. So the assertion that carries this whole change is that the fourth case
 * is refused AND is still in its old status afterwards. A test on the message
 * alone would pass against a product that says "full" and moves the case
 * anyway, which is the losing half of that comparison.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. `CapacityGuardTest` proves the
 * guard refuses; `CapacityGuardWiringTest` proves the engine appends it to both
 * assemblers. Neither can prove the guard is REACHED on a real transition
 * through a real register, and that seam is exactly where an implicit guard
 * goes dark: it exists, it passes its own tests, and it enforces nothing,
 * because the append landed on the offered-transitions path only, or because
 * the register dropped `capacity` as an unknown configuration key on import.
 *
 * THE LIMIT IS SET AFTER THE MACHINE IS SEEDED, on purpose. REQ-STE-40 asks
 * that a transition authored before the capacity existed is still bound by it,
 * and `seedStateMachine` writes its transitions first.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	executeTransition,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

/** The limit the fixture status carries. */
const CAPACITY = 3

/** The transition `seedStateMachine` writes into the capped status. */
const INTO_CAPPED = 't1'

/** The transition out of it, into the final status. */
const OUT_OF_CAPPED = 't2'

/** The fixture's case type and its two statuses of interest. */
let caseTypeId = ''
let statusReceived = ''
let statusInProgress = ''

/** The cases that fill the capped status. */
const filling: string[] = []

/** The case that arrives one too many. */
let overflowing = ''

/** An admin request context, reused across the suite. */
let api: APIRequestContext | null = null

/** The CSRF token for that context. */
let token = ''

/**
 * The status one case is sitting in right now, read back from the store.
 *
 * @param caseId The case.
 *
 * @return The status type id.
 */
async function statusOf(caseId: string): Promise<string> {
	const row = await showObject(api!, 'case', caseId)

	return String(row?.status ?? '')
}

/**
 * Seed one throwaway case in the opening status.
 *
 * @param title A label for the fixture row.
 *
 * @return The case id.
 */
async function newCase(title: string): Promise<string> {
	const seeded = await seedCase(api!, token, {
		title: `${RUN_PREFIX} ${title}`,
		caseType: caseTypeId,
		status: statusReceived,
	})

	return objectId(seeded)
}

test.describe('A status that holds a limit', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		caseTypeId = machine.caseTypeId
		statusReceived = machine.statusReceived
		statusInProgress = machine.statusInProgress

		await updateObject(api, token, 'statusType', statusInProgress, {
			capacity: CAPACITY,
		})

		for (let i = 0; i < CAPACITY; i++) {
			filling.push(await newCase(`Vulzaak ${i + 1}`))
		}

		overflowing = await newCase('Zaak te veel')
	})

	test.afterAll(async ({ request }) => {
		await api?.dispose()
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-the-status-type-stores-the-limit
	test('the status type stored the limit it was given', async () => {
		// An unknown configuration key is dropped in silence, and a register
		// whose version did not move is not imported at all. Reading the number
		// back is what separates "the property is declared" from "the property
		// reached the store", and every assertion below rests on it.
		const stored = await showObject(api!, 'statusType', statusInProgress)

		expect(Number(stored?.capacity ?? 0)).toBe(CAPACITY)
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-the-case-at-the-limit-is-allowed
	test('the cases up to the limit are allowed in', async () => {
		// The control for the refusal below. Without it a refused fourth case
		// could equally mean the transition is refused for every case, which is
		// a broken workflow rather than a working limit.
		for (const caseId of filling) {
			const result = await executeTransition(api!, token, caseId, INTO_CAPPED)
			expect(
				result.status,
				`a case within the limit must be let in; got ${result.status} ${JSON.stringify(result.body)}`,
			).toBeLessThan(400)
			expect(await statusOf(caseId)).toBe(statusInProgress)
		}
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-the-case-past-the-limit-is-refused
	test('the case past the limit is refused, and stays where it was', async () => {
		const result = await executeTransition(api!, token, overflowing, INTO_CAPPED)

		expect(
			result.status,
			'a status at its capacity must refuse the next case; a limit that lets the work '
				+ 'through is the measured losing behaviour',
		).toBeGreaterThanOrEqual(400)

		// 🔴 THE HALF THAT CANNOT BE SKIPPED. A product can answer a refusal
		// and move the case anyway, and only the stored status tells the two
		// apart.
		expect(
			await statusOf(overflowing),
			'the refused case must still be in the status it came from',
		).toBe(statusReceived)

		const said = JSON.stringify(result.body)
		expect(said, 'the refusal must say the status is full').toMatch(/full|vol/i)
		expect(said, 'the refusal must name the limit').toContain(String(CAPACITY))
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-the-refusal-is-on-the-offered-move
	test('the offered move says why, before anybody presses it', async () => {
		// Seeing the number is what stops the attempt; the refusal is the
		// backstop. The engine answers the move with `guardsPassed: false` and
		// the sentence rather than hiding it, because a move that vanishes
		// reads as a broken workflow.
		const offered = await getAvailableTransitions(api!, token, overflowing)
		expect(offered.status).toBeLessThan(400)

		const rows = offered.body?.transitions ?? []
		const move = rows.find((row: any) => String(row?.id ?? '') === INTO_CAPPED)

		expect(move, 'the move into a full status must still be OFFERED').toBeTruthy()
		expect(move.guardsPassed).toBe(false)
		expect(JSON.stringify(move.failedGuards ?? [])).toMatch(/full|vol/i)
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-a-full-status-can-always-be-emptied
	test('a full status can always be emptied, and then takes the next case', async () => {
		// The move OUT is a transition into some other status, and the guard
		// never looks where a case came from. Without that rule the first
		// status to fill up stays full for good.
		const drained = await executeTransition(
			api!,
			token,
			filling[0],
			OUT_OF_CAPPED,
			'e2e: making room',
		)
		expect(
			drained.status,
			`a case must be able to leave a full status; got ${drained.status} ${JSON.stringify(drained.body)}`,
		).toBeLessThan(400)

		const retried = await executeTransition(api!, token, overflowing, INTO_CAPPED)
		expect(
			retried.status,
			`the freed place must be usable; got ${retried.status} ${JSON.stringify(retried.body)}`,
		).toBeLessThan(400)
		expect(await statusOf(overflowing)).toBe(statusInProgress)
	})

	// @e2e openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md#scenario-a-status-without-a-capacity-is-unchanged
	test('a status carrying no capacity takes as many as arrive', async () => {
		// Every status shipped today is this one, so this is the assertion that
		// the change is additive rather than a new rule over the whole fleet.
		const stored = await showObject(api!, 'statusType', statusReceived)
		expect(
			Number(stored?.capacity ?? 0),
			'the fixture caps one status, so the opening one must carry no limit',
		).toBe(0)

		const extra = await newCase('Zaak in een ongelimiteerde status')
		expect(await statusOf(extra)).toBe(statusReceived)
	})
})
