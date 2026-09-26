/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TERM-018: every statutory term computes on the engine calendar.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. The unit suite proves the
 * DECISION: given an engine calendar, each service asks it for the day its
 * term lands on. What it cannot prove is that the wiring exists, because it
 * builds every service by hand and hands it the bridge itself. A container
 * that never injects `TermijnTimerService` leaves each site's nullsafe call
 * unevaluated, every unit assertion still passes, and every statutory date in
 * production stays on the Sunday it was computed onto. Only a real POST
 * through the real app shows that the roll is reached.
 *
 * WHICH CALENDAR ANSWERS HERE. These cases run against the seeded
 * `nl-national` calendar of OpenRegister's `working-calendar-admin`, so the
 * dates below are the Dutch general holidays. That is deliberate: an
 * organisation-specific calendar is exactly what the unit pairs vary, and
 * varying it here would test the seed rather than the wiring.
 *
 * WHAT THIS SUITE DOES NOT ASSERT. The `rollToWorkingDay` false half of the
 * fixture pairs. That flag is declared by `terms-on-the-engine-calendar` and
 * is not on a definition yet, so the raw half is pinned in
 * `tests/Unit/Service/Beschikking/BezwaarTermijnSchedulerTest.php` and
 * `tests/Unit/Service/TermijnTimerRollTest.php` instead, both by name.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** The app's own API, which is the door a handler's action goes through. */
const APP_API = '/index.php/apps/dossiq/api'

/** Ids of the deadlineInstance rows this suite seeded, for teardown. */
const seededTerms: string[] = []

test.describe('Every statutory term lands on a working day', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
	})

	test.afterAll(async () => {
		for (const id of seededTerms) {
			await deleteObject(api, token, 'deadlineInstance', id)
		}
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * A running term on a fresh case, ending on the given date.
	 *
	 * @param endDateCurrent The term's current end date, `Y-m-d`.
	 * @param status The instance status the case is in.
	 * @return The deadlineInstance id.
	 */
	async function seedTerm(
		endDateCurrent: string,
		status = 'lopend',
	): Promise<string> {
		const zaak = await seedCase(api, token, {
			title: `${RUN_PREFIX} term on the working calendar`,
			caseType: caseTypeId,
		})
		const term = await createObject(api, token, 'deadlineInstance', {
			case: objectId(zaak),
			status,
			startDate: '2026-01-05T09:00:00+00:00',
			endDateCalculated: endDateCurrent,
			endDateCurrent,
		})
		const id = objectId(term)
		seededTerms.push(id)
		return id
	}

	/**
	 * POST to the app's own API with the session's request token.
	 *
	 * @param path The path under the app API.
	 * @param body The JSON payload.
	 * @return The status and the decoded body.
	 */
	async function post(
		path: string,
		body: Record<string, unknown>,
	): Promise<{ status: number; body: any }> {
		const res = await api.post(`${APP_API}${path}`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: body,
		})
		let decoded: any
		try {
			decoded = await res.json()
		} catch {
			decoded = null
		}
		return { status: res.status(), body: decoded }
	}

	// @e2e openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#a-pause-credit-lands-on-a-holiday
	//
	// Awb 4:5. Fourteen days credited onto 12 December 2026 is Tweede
	// Kerstdag, a Saturday, and the term cannot end on one.
	test('a pause credit landing on Tweede Kerstdag moves to the next ordinary day', async () => {
		const termId = await seedTerm('2026-12-12')

		const paused = await post(`/termijn/instances/${termId}/pauze`, {
			duurDagen: 14,
			rationale: `${RUN_PREFIX} aanvulling gevraagd`,
		})

		expect(
			paused.status,
			`the pause must be accepted: ${JSON.stringify(paused.body)}`,
		).toBeLessThan(300)
		expect(
			paused.body?.endDateCurrent,
			'the credited end date must be the Monday, not Tweede Kerstdag',
		).toBe('2026-12-28')
		expect(
			paused.body?.status,
			'the instance must actually be paused, so the date above is a real pause and not a refusal',
		).toBe('paused')
	})

	// @e2e openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#a-pause-credit-lands-on-a-holiday
	//
	// THE CONTROL. The same fourteen days onto a Monday two days later land
	// on the same Monday, 28 December, without any roll. A service that
	// silently added two days to every pause would pass the case above and
	// fail here.
	test('the same credit onto an ordinary end date adds exactly the days asked for', async () => {
		const termId = await seedTerm('2026-12-14')

		const paused = await post(`/termijn/instances/${termId}/pauze`, {
			duurDagen: 14,
			rationale: `${RUN_PREFIX} aanvulling gevraagd`,
		})

		expect(paused.status).toBeLessThan(300)
		expect(
			paused.body?.endDateCurrent,
			'fourteen calendar days onto 14 December is 28 December, and nothing moves it',
		).toBe('2026-12-28')
	})

	// @e2e openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#a-pause-credit-lands-on-a-holiday
	//
	// Awb 4:15, the other half of REQ-TERM-018's third scenario: the unused
	// remainder comes off and the result lands on a working day too.
	test('an unused remainder taken off lands on a working day', async () => {
		const termId = await seedTerm('2027-04-08', 'lopend')

		const paused = await post(`/termijn/instances/${termId}/pauze`, {
			duurDagen: 14,
			rationale: `${RUN_PREFIX} aanvulling gevraagd`,
		})
		expect(paused.status).toBeLessThan(300)

		const resumed = await post(`/termijn/instances/${termId}/hervat`, {
			aanvullingDatum: '2027-03-05',
		})

		expect(
			resumed.status,
			`the resume must be accepted: ${JSON.stringify(resumed.body)}`,
		).toBeLessThan(300)
		expect(
			String(resumed.body?.endDateCurrent ?? ''),
			'the recomputed end must be a day the calendar works, never Tweede Paasdag',
		).not.toBe('2027-03-29')
		expect(
			resumed.body?.status,
			'the term must be running again, so the date above is a real resume',
		).toBe('lopend')
	})

	// @e2e openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#the-ingebrekestelling-grace-ends-on-a-sunday
	//
	// Awb 4:17. Fourteen days from a receipt on Sunday 7 June 2026 is Sunday
	// 21 June, and the dwangsom cannot start running on one.
	test('the ingebrekestelling grace ending on a Sunday opens the window on the Monday', async () => {
		const termId = await seedTerm('2026-02-25', 'exceeded')

		const registered = await post('/termijn/ingebrekestellingen', {
			termijnInstanceId: termId,
			receiptDate: '2026-06-07',
			notificationChannel: 'email',
			documentLink: `${RUN_PREFIX}-ingebrekestelling`,
		})

		expect(
			registered.status,
			`the notice must be accepted: ${JSON.stringify(registered.body)}`,
		).toBeLessThan(300)
		expect(
			registered.body?.gevalideerd,
			'the notice must be valid, or no calculation is created and the date below would be undefined',
		).toBe(true)
		expect(
			registered.body?.penaltyPaymentCalculation?.startDate,
			'the dwangsom window must open on Monday 22 June, not the Sunday',
		).toBe('2026-06-22')
	})
})
