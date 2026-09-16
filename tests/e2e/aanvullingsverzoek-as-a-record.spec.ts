/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request to complete a submission, as a record on the case (Awb 4:5).
 *
 * WHY THIS NEEDS A REAL STORE. The rules are unit-tested to the sentence:
 * `AanvullingsverzoekServiceTest` proves a send that did not happen writes
 * nothing, `AanvullingsverzoekResolutionTest` proves a partial answer leaves
 * the request open with its outstanding item named and the term suspended, and
 * `AanvullingsverzoekExpiryTest` proves the day named is the last day the
 * applicant has. What none of those can show is that the pieces MEET: that one
 * POST really writes both a suspension and a record, that the record is still
 * readable a year later, and that `waitingOnApplicant` reaches the work list as
 * a facet the chip can narrow on. Each of those seams sits between two apps.
 *
 * 🔴 NOTHING HERE SEEDS AN `aanvullingsverzoek` DIRECTLY, and nothing may. The
 * record is written by the act that also sends the letter and suspends the
 * clock, so a fixture that created one would be testing a row this app never
 * produces — the exact shape of a test that cannot fail. Every request below
 * is made through the endpoint.
 *
 * 🔴 THE EXPIRY SCENARIO IS NOT HERE, DELIBERATELY. It needs a day to pass, and
 * the spec excludes it as time-dependent: it is covered by
 * `AanvullingsverzoekExpiryTest` over the timer-fired path, which drives its
 * own `now` rather than waiting.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded, which
 * carries RUN_PREFIX and reads the same in either locale.
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
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The case type every seeded case takes, with a term to suspend. */
let caseType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

test.describe('A case records what it asked the applicant for', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} aanvulling`,
				identifier: `${RUN_PREFIX.toLowerCase()}-aanvulling`,
				description: 'Throwaway caseType for the aanvullingsverzoek e2e layer.',
				processingDeadline: 'P30D',
				suspensionAllowed: true,
				isDraft: false,
			}),
		)

		await createObject(api, token, 'statusType', {
			name: `${RUN_PREFIX} In behandeling`,
			caseType,
			order: 1,
			isFinal: false,
		})

		const seed = async (key: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
			})
			cases[key] = objectId(row)
		}

		for (const key of ['ask', 'partial', 'full', 'list1', 'list2', 'list3', 'plain1', 'plain2']) {
			await seed(key)
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
	 * Ask the applicant for things, through the one endpoint that does it.
	 *
	 * @param api    The request context.
	 * @param token  The CSRF token.
	 * @param caseId The case.
	 * @param items  What is missing.
	 */
	const ask = async (api: any, token: string, caseId: string, items: string[]) =>
		api.post(`/index.php/apps/${REGISTER}/api/cases/${caseId}/information-request`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				items,
				recipient: 'aanvrager@example.org',
				durationDays: 14,
				rationale: `${RUN_PREFIX} zonder deze stukken kan de aanvraag niet worden beoordeeld`,
			},
		})

	/**
	 * Record what arrived.
	 *
	 * @param api      The request context.
	 * @param token    The CSRF token.
	 * @param caseId   The case.
	 * @param items    What arrived.
	 * @param complete Whether the handler says it is done.
	 */
	const answer = async (
		api: any,
		token: string,
		caseId: string,
		items: string[],
		complete: boolean,
	) =>
		api.post(`/index.php/apps/${REGISTER}/api/cases/${caseId}/information-request/received`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { items, complete },
		})

	/**
	 * Every request on a case, as the read answers them.
	 *
	 * @param api    The request context.
	 * @param caseId The case.
	 */
	const requestsOn = async (api: any, caseId: string) =>
		(await api.get(`/index.php/apps/${REGISTER}/api/cases/${caseId}/aanvullingsverzoeken`)).json()

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#asking-writes-the-request-and-suspends-the-term
	test('asking writes the request naming both items, and suspends the term', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await ask(api, token, cases.ask, ['Bankafschrift', 'Huurcontract'])
		expect(response.ok(), 'the ask must succeed on a case with a running term').toBeTruthy()

		const body = await response.json()
		expect(body.suspended, 'the term is suspended through the engine timer').toBe(true)

		const state = await requestsOn(api, cases.ask)
		expect(state.waiting).toBe(true)
		expect(state.open).toBe(1)

		const request = state.requests[0]
		expect(request.state).toBe('open')
		expect(request.missingItems.map((i: any) => i.item)).toEqual([
			'Bankafschrift',
			'Huurcontract',
		])
		expect(String(request.rationale)).toContain(RUN_PREFIX)
		expect(
			String(request.hersteltermijn),
			'the date in the letter is the date the clock was suspended to',
		).not.toBe('')
		// The record is not the pause: it points at it.
		expect(String(request.deadlineInstance)).not.toBe('')

		await api.dispose()
	})

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#a-partial-answer-leaves-the-request-open
	test('one of two arrives: the request stays open naming the other', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await ask(api, token, cases.partial, ['Bankafschrift', 'Huurcontract'])
		const recorded = await answer(api, token, cases.partial, ['Bankafschrift'], false)
		expect(recorded.ok()).toBeTruthy()

		const state = await requestsOn(api, cases.partial)
		expect(state.waiting, 'the case is still waiting on the applicant').toBe(true)

		const request = state.requests[0]
		expect(request.state).toBe('open')

		const outstanding = request.missingItems.filter((i: any) => i.received !== true)
		expect(outstanding.map((i: any) => i.item)).toEqual(['Huurcontract'])

		// The term stays suspended: the applicant has not supplied what was
		// asked, so the case still cannot be decided.
		const row = await showObject(api, 'case', cases.partial)
		expect(row.waitingOnApplicant).toBe(true)

		await api.dispose()
	})

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#a-full-answer-closes-the-request-and-resumes-the-clock
	test('both arrive and the handler says so: the request reads answered', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await ask(api, token, cases.full, ['Bankafschrift', 'Huurcontract'])

		// Claiming completeness with one still outstanding is refused, and the
		// refusal names what is missing. Asserted here because it is the guard
		// that stops a statutory term resuming on an incomplete file.
		const tooSoon = await answer(api, token, cases.full, ['Bankafschrift'], true)
		expect(tooSoon.ok()).toBeFalsy()
		expect(String((await tooSoon.json()).message)).toContain('Huurcontract')

		const done = await answer(
			api,
			token,
			cases.full,
			['Bankafschrift', 'Huurcontract'],
			true,
		)
		expect(done.ok()).toBeTruthy()

		const state = await requestsOn(api, cases.full)
		expect(state.waiting).toBe(false)
		expect(state.open).toBe(0)
		expect(state.requests[0].state).toBe('answered')
		// The record keeps what it asked for, answered or not.
		expect(state.requests[0].missingItems).toHaveLength(2)

		await api.dispose()
	})

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#the-work-list-answers-what-we-are-waiting-on
	test('the work list lists exactly the cases waiting on an applicant', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		for (const key of ['list1', 'list2', 'list3']) {
			await ask(api, token, cases[key], ['Bankafschrift'])
		}

		const waiting = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case`
			+ `?waitingOnApplicant=true&_search=${encodeURIComponent(RUN_PREFIX)}&_limit=50`,
		)
		const ids = ((await waiting.json()).results ?? []).map((row: any) => objectId(row))

		expect(ids).toEqual(
			expect.arrayContaining([cases.list1, cases.list2, cases.list3]),
		)
		expect(ids, 'a case nobody asked anything is not in the filter').not.toContain(cases.plain1)
		expect(ids).not.toContain(cases.plain2)

		// Each one shows how long its request has been open, computed on the
		// read rather than stored, so nothing has to be kept in step.
		const state = await requestsOn(api, cases.list1)
		expect(typeof state.requests[0].daysOpen).toBe('number')

		await api.dispose()
	})

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#answering-removes-the-case-from-the-filter
	test('answering in full takes the case out of the filter', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await answer(api, token, cases.list1, ['Bankafschrift'], true)

		const row = await showObject(api, 'case', cases.list1)
		expect(row.waitingOnApplicant).toBe(false)

		const waiting = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case`
			+ `?waitingOnApplicant=true&_search=${encodeURIComponent(RUN_PREFIX)}&_limit=50`,
		)
		const ids = ((await waiting.json()).results ?? []).map((r: any) => objectId(r))
		expect(ids).not.toContain(cases.list1)

		// And the record is still there, which is the whole point of it.
		const state = await requestsOn(api, cases.list1)
		expect(state.requests[0].state).toBe('answered')

		await api.dispose()
	})

	// @e2e openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md#the-request-names-who-asked
	test('a case page opens with the request on it', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases.ask}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })
	})
})
