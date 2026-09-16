/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A pause names a declared reason, and the reason carries the chasing (Awb 4:5).
 *
 * WHY THIS NEEDS A REAL STORE. The rules are unit-tested to the sentence.
 * `ChaseScheduleTest` proves a 14-day pause with a 5-day interval and a budget
 * of 2 puts its reminders on day 5 and day 10 and stops there;
 * `PauseChaseServiceTest` proves each reminder is sent once, recorded on the
 * term and on the timeline, and that a rung arriving after the term resumed
 * sends nothing; `DeadlinePauseReasonTest` proves the key and the party reach
 * the instance and a stale key is refused. What none of those can show is that
 * the pieces MEET: that `caseType.pauseReasons` survives the register import as
 * a list OpenRegister stores and answers, that a pause registered through the
 * one endpoint carries the key back on the term, and that resuming clears it.
 * Each of those seams sits between two apps.
 *
 * 🔴 THE TWO CHASING SCENARIOS ARE NOT HERE, AND THE SPEC SAYS SO. A reminder
 * is due five days after a pause starts, so watching one go out means waiting
 * five days or seeding a pause that already started, and a seeded pause is a
 * row this app never produces: the exact shape of a test that cannot fail. They
 * are excluded as time-dependent and covered by the two unit files named above,
 * which drive their own `now`.
 *
 * 🔴 NOTHING HERE SEEDS A `deadlineInstance` DIRECTLY. Every pause below is
 * registered through the endpoint that also sends the letter, because that is
 * the only path production has.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** The case type, declaring two pause reasons with different schedules. */
let caseType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/** The key of the reason every scenario below suspends under. */
const REASON = 'aanvulling-aanvrager'

test.describe('A pause names a reason, and the reason chases', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} pauzereden`,
				identifier: `${RUN_PREFIX.toLowerCase()}-pauzereden`,
				description: 'Throwaway caseType for the pause-reason e2e layer.',
				processingDeadline: 'P30D',
				suspensionAllowed: true,
				isDraft: false,
				pauseReasons: [
					{
						key: REASON,
						name: `${RUN_PREFIX} Aanvulling gevraagd`,
						category: 'applicant',
						waitingOn: 'applicant',
						legalBasis: 'Awb 4:5',
						chaseIntervalDays: 5,
						chaseBudget: 2,
						countsWorkingDays: true,
						chaseText: `${RUN_PREFIX} wij hebben uw aanvulling nog niet ontvangen.`,
						escalateTo: 'handler',
					},
					{
						key: 'advies-derde',
						name: `${RUN_PREFIX} Advies gevraagd`,
						category: 'thirdParty',
						waitingOn: 'thirdParty',
						legalBasis: 'Awb 4:15',
						chaseIntervalDays: 10,
						chaseBudget: 1,
						countsWorkingDays: true,
						chaseText: `${RUN_PREFIX} wij wachten nog op uw advies.`,
						escalateTo: 'coordinator',
					},
				],
			}),
		)

		await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} In behandeling`,
			caseType,
			order: 1,
			isFinal: false,
			waitingOn: 'applicant',
		})

		for (const key of ['reason', 'resume', 'stale']) {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
			})
			cases[key] = objectId(row)
		}

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Ask the applicant for something under a named reason.
	 *
	 * @param api         The request context.
	 * @param token       The CSRF token.
	 * @param caseId      The case.
	 * @param pauseReason The declared reason key.
	 */
	const ask = async (api: any, token: string, caseId: string, pauseReason: string) =>
		api.post(`/index.php/apps/${REGISTER}/api/cases/${caseId}/information-request`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				items: ['Bankafschrift'],
				recipient: 'aanvrager@example.org',
				durationDays: 14,
				pauseReason,
				rationale: `${RUN_PREFIX} zonder dit stuk kan de aanvraag niet worden beoordeeld`,
			},
		})

	/**
	 * The clocks on a case, as the case page reads them.
	 *
	 * @param api    The request context.
	 * @param caseId The case.
	 */
	const termsOn = async (api: any, caseId: string) =>
		(await api.get(`/index.php/apps/${REGISTER}/api/cases/${caseId}/terms`)).json()

	// @e2e openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md#a-pause-is-registered-under-a-declared-reason
	test('a pause carries the declared reason and the party it waits on', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await ask(api, token, cases.reason, REASON)
		expect(response.ok(), 'the ask must succeed on a case with a running term').toBeTruthy()
		expect((await response.json()).suspended).toBe(true)

		const terms = await termsOn(api, cases.reason)
		const paused = terms.terms.find((term: any) => term.status === 'paused')
		expect(paused, 'the statutory term is suspended').toBeTruthy()
		expect(paused.pauseReason).toBe(REASON)
		expect(paused.pauseWaitingOn).toBe('applicant')
		expect(paused.chasesSent).toBe(0)

		await api.dispose()
	})

	// @e2e openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md#resume-stops-the-chasing
	test('recording the aanvulling clears the reason and the reminders', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await ask(api, token, cases.resume, REASON)

		const resumed = await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.resume}/information-request/received`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { items: ['Bankafschrift'], complete: true },
			},
		)
		expect(resumed.ok(), 'the aanvulling must be accepted').toBeTruthy()

		const terms = await termsOn(api, cases.resume)
		expect(
			terms.terms.some((term: any) => term.status === 'paused'),
			'no clock on this case is suspended any more',
		).toBe(false)
		for (const term of terms.terms) {
			expect(term.pauseReason).toBe('')
			expect(term.chasesSent).toBe(0)
		}

		await api.dispose()
	})

	// @e2e openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md#a-reason-the-case-type-does-not-declare-is-refused
	test('a reason this case type does not declare is refused, and nothing is suspended', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await ask(api, token, cases.stale, 'een-reden-die-niet-bestaat')
		expect(response.ok(), 'a stale reason key must be refused').toBeFalsy()

		const terms = await termsOn(api, cases.stale)
		expect(
			terms.terms.some((term: any) => term.status === 'paused'),
			'a refused reason leaves the clock running',
		).toBe(false)

		await api.dispose()
	})
})
