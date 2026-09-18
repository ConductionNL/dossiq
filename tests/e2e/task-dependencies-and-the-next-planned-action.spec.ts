/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A milestone that moves with its predecessor, and a chain of planned actions
 * (task-dependencies-and-the-next-planned-action, REQ-MST-01, REQ-MST-02 and
 * REQ-TDP-01; gap register rows 3.27 and 3.28).
 *
 * WHAT ONLY A LIVE INSTANCE CAN SHOW
 * ----------------------------------
 * The arithmetic is pinned against fixtures in `MilestoneScheduleTest` and
 * `PlannedActionChainTest`, and the declarations in
 * `tests/vitest/caseNextAction.spec.js`. Each of those is green on its own.
 * What none of them can show is whether THE REGISTER ON THIS INSTANCE CARRIES
 * THE SCHEMAS AT ALL. A fragment under `register.d/` reaches an instance only
 * through an import, and an import that did not run leaves dossiq writing to a
 * schema nothing answers for: the write fails, the case shows no planned
 * action, and that reads exactly like a case on which nothing is planned.
 *
 * The second thing only a live instance shows is that COMPLETING IS TWO
 * WRITES. The controller closes one record and creates another; a client doing
 * that itself in two calls can leave a case with a completed action and no
 * successor, and the case would then answer "nothing is planned" for a chain
 * that has not ended.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED.
 * Completing an action is a MUTATION of what the case says happens next, and
 * the route asks `hasCaseMutationAccess`. An anonymous context holds no group
 * and is the honest read of somebody who should get nothing; a refusal there
 * is a real result, and it is asserted on the REFUSAL rather than on a page.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance. Everything here is matched
 * on data this spec seeded (RUN_PREFIX) or on an API response, never on a
 * translated label.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'

/** dossiq's own routes for the planned action. */
const PLANNED_BASE = `/index.php/apps/${REGISTER}/api/cases`

let api: APIRequestContext
let token: string
let caseTypeId = ''
let caseId = ''

/** The two action types this run authors, and the action planned from them. */
const CONFIRM = `${RUN_PREFIX}-confirm`
const FOLLOW_UP = `${RUN_PREFIX}-follow-up`

/** Whether this instance carries the plannedAction schemas at all. */
let schemasPresent = false

test.describe('The case says what happens next', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])

		caseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Planned actions`,
				caseType: caseTypeId,
			}),
		)

		// The import is what this probes: a fragment that never reached the
		// instance leaves every write below failing for a reason no assertion
		// downstream would name.
		const probe = await api.post(
			`/index.php/apps/openregister/api/objects/${REGISTER}/plannedActionType`,
			{
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
				},
				data: {
					identifier: CONFIRM,
					label: `${RUN_PREFIX} Send the confirmation`,
					successor: FOLLOW_UP,
					successorOffsetWorkingDays: 10,
					caseType: caseTypeId,
				},
			},
		)
		schemasPresent = probe.ok()
		if (schemasPresent === false) {
			return
		}

		await createObject(api, token, 'plannedActionType', {
			identifier: FOLLOW_UP,
			label: `${RUN_PREFIX} Call after ten days`,
			successor: '',
		})
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md#the-case-answers-what-happens-next
	// @e2e task-management::the-case-answers-what-happens-next
	test('the planned action schemas reached this instance', async () => {
		expect(
			schemasPresent,
			'plannedActionType could not be written on this instance: the register.d fragment has not been imported, so every planned action below would fail for a reason nothing else names',
		).toBe(true)
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md#the-case-answers-what-happens-next
	// @e2e task-management::the-case-answers-what-happens-next
	test('the case answers what happens next, without its history', async () => {
		test.skip(schemasPresent === false, 'the plannedAction schemas are absent')

		const action = await createObject(api, token, 'plannedAction', {
			case: caseId,
			actionType: CONFIRM,
			label: `${RUN_PREFIX} Send the confirmation`,
			plannedFor: '2026-03-02',
			state: 'planned',
		})

		const response = await api.get(
			`${PLANNED_BASE}/${caseId}/planned-actions/next`,
		)
		expect(
			response.ok(),
			`the next action must be readable, got ${response.status()}`,
		).toBeTruthy()

		const body = await response.json()
		expect(body.next).not.toBeNull()
		expect(String(body.next.actionType)).toBe(CONFIRM)
		expect(String(body.next.plannedFor)).toContain('2026-03-02')
		expect(objectId(action)).toBeTruthy()
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md#completing-one-action-plans-the-next
	// @e2e task-management::completing-one-action-plans-the-next
	test('completing one action plans the one its type declares', async () => {
		test.skip(schemasPresent === false, 'the plannedAction schemas are absent')

		const first = await createObject(api, token, 'plannedAction', {
			case: caseId,
			caseType: caseTypeId,
			actionType: CONFIRM,
			label: `${RUN_PREFIX} Send the confirmation`,
			plannedFor: '2026-03-02',
			state: 'planned',
		})
		const firstId = objectId(first)

		const response = await api.post(
			`${PLANNED_BASE}/${caseId}/planned-actions/${firstId}/complete`,
			{ headers: { requesttoken: token } },
		)
		expect(
			response.ok(),
			`completing must be accepted, got ${response.status()} ${await response.text()}`,
		).toBeTruthy()

		const body = await response.json()
		expect(body.next, 'the successor was planned').not.toBeNull()
		expect(String(body.next.actionType)).toBe(FOLLOW_UP)

		// BOTH writes, read back from the register rather than from the
		// response. A completion that only wrote the successor would leave the
		// case with two open actions and the response above would look
		// identical.
		const completed = await showObject(api, 'plannedAction', firstId)
		expect(String(completed.state)).toBe('completed')
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md#completing-one-action-plans-the-next
	// @e2e task-management::completing-one-action-plans-the-next
	test('a chain ends where the type declares no successor', async () => {
		test.skip(schemasPresent === false, 'the plannedAction schemas are absent')

		const last = await createObject(api, token, 'plannedAction', {
			case: caseId,
			caseType: caseTypeId,
			actionType: FOLLOW_UP,
			label: `${RUN_PREFIX} Call after ten days`,
			plannedFor: '2026-03-16',
			state: 'planned',
		})

		const response = await api.post(
			`${PLANNED_BASE}/${caseId}/planned-actions/${objectId(last)}/complete`,
			{ headers: { requesttoken: token } },
		)
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		expect(body.next, 'nothing follows the last link').toBeNull()

		const completed = await showObject(api, 'plannedAction', objectId(last))
		expect(String(completed.state)).toBe('completed')
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md#the-case-answers-what-happens-next
	// @e2e task-management::the-case-answers-what-happens-next
	test('an ungranted principal cannot complete an action', async ({
		playwright,
		baseURL,
	}) => {
		test.skip(schemasPresent === false, 'the plannedAction schemas are absent')

		const action = await createObject(api, token, 'plannedAction', {
			case: caseId,
			caseType: caseTypeId,
			actionType: CONFIRM,
			label: `${RUN_PREFIX} Send the confirmation`,
			plannedFor: '2026-04-01',
			state: 'planned',
		})
		const id = objectId(action)

		const anonymous = await playwright.request.newContext({ baseURL })
		const response = await anonymous.post(
			`${PLANNED_BASE}/${caseId}/planned-actions/${id}/complete`,
			{},
		)
		expect(
			response.ok(),
			`an ungranted principal must not complete an action, got ${response.status()}`,
		).toBeFalsy()
		await anonymous.dispose()

		// The refusal is asserted on the RECORD too, not only on the status.
		// A 403 that had already written the completion would be a refusal in
		// name only.
		const unchanged = await showObject(api, 'plannedAction', id)
		expect(String(unchanged.state)).toBe('planned')
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md#the-hearing-moves-and-the-timeline-follows
	// @e2e milestone-tracking::the-hearing-moves-and-the-timeline-follows
	test('a milestone declares the item it waits for, and it is stored', async () => {
		// The projection itself is arithmetic and is pinned in
		// `MilestoneScheduleTest`. What is asserted here is the half that runs
		// through the register: `dependsOn` written by an administrator comes
		// back as a list, because the reader falls back to the old cumulative
		// behaviour without a word when it does not.
		const hearing = await createObject(api, token, 'milestoneDefinition', {
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-hearing`,
			label: `${RUN_PREFIX} Hearing`,
			order: 1,
			expectedDurationWorkingDays: 20,
		})
		expect(objectId(hearing)).toBeTruthy()

		const decision = await createObject(api, token, 'milestoneDefinition', {
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-decision`,
			label: `${RUN_PREFIX} Decision`,
			order: 2,
			expectedDurationWorkingDays: 10,
			dependsOn: [`${RUN_PREFIX}-hearing`],
			ownerRole: 'assignee',
		})

		const readBack = await showObject(
			api,
			'milestoneDefinition',
			objectId(decision),
		)
		const dependsOn = Array.isArray(readBack.dependsOn)
			? readBack.dependsOn
			: JSON.parse(String(readBack.dependsOn || '[]'))
		expect(dependsOn).toContain(`${RUN_PREFIX}-hearing`)
		expect(String(readBack.ownerRole)).toBe('assignee')
	})

	// @e2e openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md#a-cycle-is-refused-when-the-case-type-is-saved
	// @e2e milestone-tracking::a-cycle-is-refused-when-the-case-type-is-saved
	test('a milestone that waits for itself is refused', async () => {
		// The listener is a PRE-persist guard, so the refusal has to come back
		// as a failed write rather than as a row that is stored and ignored.
		const response = await api.post(
			`/index.php/apps/openregister/api/objects/${REGISTER}/milestoneDefinition`,
			{
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
				},
				data: {
					caseType: caseTypeId,
					identifier: `${RUN_PREFIX}-loop`,
					label: `${RUN_PREFIX} Loop`,
					order: 9,
					expectedDurationWorkingDays: 1,
					dependsOn: [`${RUN_PREFIX}-loop`],
				},
			},
		)

		expect(
			response.ok(),
			`a milestone depending on itself must be refused, got ${response.status()}`,
		).toBeFalsy()
	})
})
