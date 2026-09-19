/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SDC-01 to REQ-SDC-03 and REQ-SDW-01: a status declares what makes it
 * true, who the case is waiting on, and how long it may last; and the time in
 * a status is held on the case where a work list can sort and filter on it.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Three of the four requirements
 * cross a seam that only a real instance exercises, and each of them can fail
 * SILENTLY in the direction that looks like the product working:
 *
 *  - the derivation runs in an OpenRegister save-time listener. A listener
 *    that is registered but never reached leaves the case exactly where it
 *    was, which is indistinguishable from a case type that derives nothing.
 *    Only a real write to a real register shows whether the case moved;
 *  - `waitingOn` and `statusDwellBreached` are DECLARATIVE calculations,
 *    materialised by OpenRegister on save. An instance whose calculations
 *    have not run answers every filter with an empty list, and an empty list
 *    is a valid-looking answer to "which cases are waiting on the applicant";
 *  - the dwell column sorts SERVER-SIDE. A property the mapper will not order
 *    by is answered by ignoring the `_order`, which reads as a list in its
 *    default order rather than as a broken sort.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never recomputes a working
 * day count of its own and compares it with the app's. That would be a second
 * implementation of the question `WorkingDayCalculator` owns, written in a
 * test file, and it would eventually disagree with the app and be "fixed" in
 * whichever direction was easier. What it asserts is the SHAPE of the answer:
 * that the number is held on the case, that it is the number the list sorts
 * on, and that a breach is its own fact and not the deadline's.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'

test.describe('A status declares what it means', () => {
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

	test('the case becomes complete when the file is complete, and is not offered as a choice', async () => {
		const machine = await seedStateMachine(api, token)

		// The middle status of the seeded machine declares its conditions, so
		// it stops being a move anybody picks and starts being a fact about
		// the file: the case is In behandeling once its description is filled.
		await updateObject(api, token, 'statusType', machine.statusInProgress, {
			derivedWhen: [
				{
					kind: 'fieldPresent',
					field: 'description',
					label: 'the case summary',
				},
			],
		})

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} derived status`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const caseId = objectId(seeded)

		// Before: the derived status is NOT on offer, and the strip says what
		// is missing rather than leaving the handler with nothing to read.
		const before = await getAvailableTransitions(api, token, caseId)
		expect(before.status).toBe(200)
		expect(
			before.body.transitions.map((entry: any) => entry.toStatus),
		).not.toContain(machine.statusInProgress)
		expect(before.body.derivation?.unmet ?? []).toContain('the case summary')

		// The write that makes the condition true is an ordinary case edit,
		// not a transition: that is the whole point of a derived status.
		await updateObject(api, token, 'case', caseId, {
			description: 'The file is complete.',
		})

		const after = await getAvailableTransitions(api, token, caseId)
		expect(after.body.current.statusId).toBe(machine.statusInProgress)
		expect(after.body.derivation).toBeNull()
	})

	test('a status that declares no conditions is still offered', async () => {
		const machine = await seedStateMachine(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} picked status`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const answer = await getAvailableTransitions(api, token, objectId(seeded))

		expect(answer.status).toBe(200)
		expect(
			answer.body.transitions.map((entry: any) => entry.toStatus),
		).toContain(machine.statusInProgress)
	})

	test('a queue is counted by who is waited on, and an undeclared case is ours', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusReceived, {
			waitingOn: 'applicant',
		})
		await updateObject(api, token, 'statusType', machine.statusInProgress, {
			waitingOn: 'thirdParty',
		})

		const waiting = await seedCase(api, token, {
			title: `${RUN_PREFIX} waiting on the applicant`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const ours = await seedCase(api, token, {
			title: `${RUN_PREFIX} ours to move`,
			caseType: machine.caseTypeId,
			status: machine.statusDone,
		})

		// The value is MATERIALISED by OpenRegister from the linked statusType,
		// which is what lets a team count narrow on it server-side. Read back
		// off the stored case rather than asked of a dossiq endpoint, because
		// the calculation running is the thing that can silently not happen.
		const waitingRow = await updateObject(
			api,
			token,
			'case',
			objectId(waiting),
			{},
		)
		const oursRow = await updateObject(api, token, 'case', objectId(ours), {})

		expect(waitingRow.waitingOn).toBe('applicant')
		// The final status declares nothing, and an undeclared status is ours
		// to move rather than a fourth bucket.
		expect(oursRow.waitingOn).toBe('us')
	})

	test('a dwell breach is its own fact and leaves the term alone', async () => {
		const machine = await seedStateMachine(api, token)

		await updateObject(api, token, 'statusType', machine.statusReceived, {
			maximumDwell: 1,
		})

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} stuck inside a healthy term`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
			deadline: '2099-01-01',
		})
		const caseId = objectId(seeded)

		// Backdate the entry moment rather than waiting a working day. The
		// field is what the engine reads, so backdating it is the same input a
		// case that actually sat there would produce.
		const stuck = await updateObject(api, token, 'case', caseId, {
			currentStatusEnteredAt: '2020-01-01T09:00:00+01:00',
		})

		const answer = await getAvailableTransitions(api, token, caseId)
		expect(answer.body.current.dwell.breached).toBe(true)
		expect(answer.body.current.dwell.maximum).toBe(1)
		// The term is untouched: a status maximum and a beslistermijn are
		// different clocks with different consequences.
		expect(String(stuck.deadline ?? '')).toContain('2099-01-01')
		expect(String(stuck.endDate ?? '')).toBe('')
	})

	test('the work list sorts and filters on the number the case holds', async () => {
		const machine = await seedStateMachine(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} dwell sort`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const held = await updateObject(api, token, 'case', objectId(seeded), {
			currentStatusDwellDays: 42,
		})
		expect(Number(held.currentStatusDwellDays)).toBe(42)

		// The sort is the server's. A property the mapper will not order by is
		// answered by IGNORING the _order, which reads as a list in its default
		// order rather than as a broken sort, so the request is made and its
		// answer asserted rather than the column's presence in a manifest.
		const sorted = await api.get(
			'/index.php/apps/openregister/api/objects/dossiq/case'
				+ '?_limit=5&_order[currentStatusDwellDays]=desc',
			{ headers: { requesttoken: token } },
		)
		expect(sorted.status()).toBe(200)
		const rows = (await sorted.json()).results ?? []
		const values = rows
			.map((row: any) => Number(row.currentStatusDwellDays ?? 0))
			.filter((value: number) => Number.isFinite(value))
		expect(values).toEqual([...values].sort((left, right) => right - left))
	})

	test('the process mining page reads the numbers the cases hold', async () => {
		const machine = await seedStateMachine(api, token)
		await seedCase(api, token, {
			title: `${RUN_PREFIX} held totals`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		const report = await api.get(
			'/index.php/apps/dossiq/api/reports/process-mining',
			{
				headers: { requesttoken: token },
			},
		)
		expect(report.status()).toBe(200)

		const body = await report.json()
		const types = body.caseTypes ?? []
		expect(types.length).toBeGreaterThan(0)
		// Published rather than recomputed: the page and a handler's work list
		// answer the same question with the same number.
		for (const entry of types) {
			expect(entry).toHaveProperty('dwellDaysHeld')
		}
	})

	test.skip('a general holiday inside the window pushes the breach out a day', async () => {
		// @e2e exclude the breach is due on the engine's own businessDays unit
		// over the calendar the ORGANISATION administers, and this instance
		// administers none: the engine falls back to a default calendar and the
		// assertion would be about that fallback rather than about the feature.
		// Covered as a fixture pair over the seeded calendar in
		// tests/Unit/Service/Status/CaseDwellFieldsTest.php.
	})
})
