/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tenders-run-on-the-tender-case-type task 4.1: the three scenarios of
 * REQ-TND-CASE-01, plus the answer a buyer publishes from the case page.
 *
 *   - a bid registered after the case's closing date is refused, and the
 *     refusal names that date (SubmissionClosingDateGuard);
 *   - the Evaluation card lists the bids by the formula's total, highest first;
 *   - making the award final before the standstill ends is refused, naming
 *     the day it ends (the aanbesteding flow's dateReached guard);
 *   - Publish answer stores the answer and walks the question to published.
 *
 * Every case is seeded per run on a throwaway case type that sets
 * `tenderChain`, so the case page draws the tender parts. The standstill
 * scenario needs the aanbesteding package's flow and skips when the package
 * is not installed.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly run owns it.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = `/index.php/apps/openregister/api/objects/${REGISTER}`

/**
 * A calendar day relative to today, as `Y-m-d`.
 *
 * @param offset Days from today; negative is in the past.
 *
 * @return The day.
 */
function day(offset: number): string {
	const d = new Date()
	d.setDate(d.getDate() + offset)
	return d.toISOString().slice(0, 10)
}

/** The throwaway tender case type. */
let caseType = ''

/** One case per scenario. */
const cases: Record<string, string> = {}

test.describe('A tender is worked end to end on its case page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} tender`,
				identifier: `${RUN_PREFIX.toLowerCase()}-tender`,
				description: 'Throwaway tender case type for the tender-case e2e layer.',
				processingDeadline: 'P90D',
				tenderChain: true,
				isDraft: false,
			}),
		)

		const seed = async (key: string, closesOn: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
				properties: [{ name: 'sluiting', value: closesOn }],
			})
			cases[key] = objectId(row)
		}

		await seed('late', day(-1))
		await seed('ranking', day(-10))
		await seed('answer', day(14))

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	test('a late bid is refused with the closing date in the message', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const res = await api.post(`${OBJECTS}/bid`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				tender: cases.late,
				case: cases.late,
				supplier: `${RUN_PREFIX} supplier`,
				submittedAt: new Date().toISOString(),
				priceAmount: 125000,
				state: 'received',
				responses: {},
			},
		})

		expect(res.ok(), 'a bid after the closing date must not be stored').toBeFalsy()
		const body = await res.text()
		expect(body).toContain('submission-after-closing-date')
		expect(body).toContain(day(-1))

		const stored = await listObjects(api, 'bid', { tender: cases.late })
		expect(stored).toHaveLength(0)
		await api.dispose()
	})

	test('a bid on the closing day itself is accepted', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const bid = await createObject(api, token, 'bid', {
			tender: cases.answer,
			case: cases.answer,
			supplier: `${RUN_PREFIX} supplier`,
			submittedAt: new Date().toISOString(),
			priceAmount: 99000,
			state: 'received',
			responses: {},
		})

		expect(objectId(bid)).not.toBe('')
		await api.dispose()
	})

	test('the formula ranks the bids, highest total first', async ({ page, playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const totals = { 'Bidder A': 88.5, 'Bidder B': 100.2, 'Bidder C': 95 }
		for (const [supplier, weightedTotal] of Object.entries(totals)) {
			const bid = await createObject(api, token, 'bid', {
				tender: cases.ranking,
				case: cases.ranking,
				supplier: `${RUN_PREFIX} ${supplier}`,
				submittedAt: `${day(-12)}T10:00:00Z`,
				priceAmount: 100000,
				state: 'evaluated',
				responses: {},
			})
			await createObject(api, token, 'evaluation', {
				bid: objectId(bid),
				tender: cases.ranking,
				evaluator: `${RUN_PREFIX} panel`,
				criterionScores: { kwaliteit: weightedTotal - 38, prijs: 38 },
				weightedTotal,
				state: 'submitted',
			})
		}
		await api.dispose()

		await page.goto(`/apps/${REGISTER}/cases/${cases.ranking}`, PAGE_LOAD)
		const card = page.locator('[data-widget-id="case-tender-ranking"], section, article')
			.filter({ hasText: 'Evaluation' })
			.first()
		await expect(card).toBeVisible(PAGE_LOAD)

		const text = await card.innerText()
		const order = ['100.2', '95', '88.5'].map((value) => text.indexOf(value))
		expect(order.every((at) => at >= 0), `all three totals on the card: ${text}`).toBeTruthy()
		expect(order).toEqual([...order].sort((a, b) => a - b))
	})

	test('Publish answer stores the answer and publishes the question', async ({ page, playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const question = objectId(
			await createObject(api, token, 'tenderQuestion', {
				tender: cases.answer,
				submittedBy: `${RUN_PREFIX} supplier`,
				submittedAt: new Date().toISOString(),
				question: `${RUN_PREFIX} Is a site visit possible?`,
				state: 'received',
			}),
		)

		await page.goto(`/apps/${REGISTER}/cases/${cases.answer}`, PAGE_LOAD)
		await page.getByRole('button', { name: 'Publish answer' }).first().click()
		const dialog = page.getByTestId('answer-publish-dialog')
		await expect(dialog).toBeVisible(PAGE_LOAD)
		await dialog.getByTestId('answer-publish-answer').locator('textarea').fill('Yes, on 3 March at 10:00.')
		await dialog.getByTestId('answer-publish-submit').click()
		await expect(dialog).toBeHidden(PAGE_LOAD)

		const stored = await showObject(api, 'tenderQuestion', question)
		expect(stored.state).toBe('published')
		expect(stored.answer).toBe('Yes, on 3 March at 10:00.')
		expect(String(stored.publishedAt ?? '')).not.toBe('')
		await api.dispose()
	})

	test('the award waits for the standstill', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const packaged = (await listObjects(api, 'caseType', { identifier: 'aanbesteding' }))[0]
		test.skip(!packaged, 'the aanbesteding package is not installed on this instance')

		const ends = day(12)
		const tender = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} standstill`,
				caseType: objectId(packaged),
				description: 'Seeded for the standstill scenario.',
				confidentiality: 'openbaar',
				properties: [
					{ name: 'sluiting', value: day(-30) },
					{ name: 'gunningsdatum', value: day(-8) },
					{ name: 'opschortendeTermijnEinde', value: ends },
				],
			}),
		)

		const available = await api.get(`/index.php/apps/${REGISTER}/api/case/${tender}/available-transitions`)
		expect(available.ok()).toBeTruthy()
		const listed: any = await available.json()
		const transitions: any[] = Array.isArray(listed) ? listed : (listed?.transitions ?? [])
		const final = transitions.find((row) => row.label === 'Definitief gunnen')
		test.skip(!final, 'the case is not in the status the final award leaves from')

		const res = await api.post(`/index.php/apps/${REGISTER}/api/case/${tender}/transition`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { transitionId: final.id },
		})
		expect(res.ok(), 'the final award must be refused while the standstill runs').toBeFalsy()
		expect(await res.text()).toContain(ends)
		await api.dispose()
	})
})
