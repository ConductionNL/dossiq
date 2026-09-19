/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leges on a case: what is owed, whether it arrived, and what waits on it.
 *
 * 🔴 THE MONEY IS SHILLINQ'S AND THIS SPEC PROVES DOSSIQ CONSUMES IT. Every
 * amount here is read from the panel shillinq renders and from the fee
 * schedule shillinq resolves; nothing asserts a figure dossiq stores, because
 * dossiq storing one is the defect. What IS asserted on the dossiq side is the
 * word on the case, the lens on the list, and the act that is refused.
 *
 * 🔴 THE REFUSAL IS PROBED ON THE WRITE PATH, NOT ONLY IN THE MENU. A greyed
 * button proves the menu; posting the transition anyway is what proves the
 * rule. The least privileged principal that should be refused is an ordinary
 * handler on a case whose fee is outstanding, and the assertion is that the
 * answer is a 4xx naming the payment rule rather than a 500 or a success.
 *
 * 🔴 A STALE STATE MUST NEVER RENDER AS PAID. The projection is refreshed by
 * an hourly job and read live at the gate, so the case that this run leaves
 * unrefreshed is the one that catches a projection defaulting to the
 * comfortable answer.
 *
 * 🔴 EVERY CASE, CASE TYPE AND REQUEST THIS SPEC RAISES IS CLEANED UP. A
 * payment request left on a shared instance joins somebody else's reconciliation.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/fees-and-payments-on-the-case/specs/financial-integration/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
const LEGES_API = '/index.php/apps/shillinq/api/payment-requests/leges'

let api: APIRequestContext
let token: string

/** The case ids this spec seeded, for the transition probes. */
const cases: Record<string, string> = {}

/**
 * Raise the leges for one case through shillinq, for the given intake channel.
 *
 * The amount is never sent: shillinq resolves it from the published schedule,
 * which is the whole point of the fee living there.
 *
 * @param caseId The case uuid.
 * @param intakeChannel `desk` or `web`.
 */
async function raiseLeges(caseId: string, intakeChannel: string) {
	return await api.post(LEGES_API, {
		headers: { requesttoken: token },
		data: {
			register: 'dossiq',
			schema: 'case',
			objectId: caseId,
			intakeChannel,
		},
	})
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('a case type declares its fee, per intake channel', () => {
	test('the balie and the portal raise their own amounts', async () => {
		cases.desk = await seedCase(api, token, {
			title: `${RUN_PREFIX} Balie aanvraag`,
			intakeChannel: 'balie',
		})
		cases.web = await seedCase(api, token, {
			title: `${RUN_PREFIX} Portaal aanvraag`,
			intakeChannel: 'website',
		})

		const desk = await raiseLeges(cases.desk, 'desk')
		const web = await raiseLeges(cases.web, 'web')

		// Both answer, and each carries the fee shillinq resolved for its own
		// channel. Equal amounts here would mean the channel was ignored,
		// which is the failure a single amount per case type always has.
		expect(desk.status(), 'the desk fee was not raised').toBeLessThan(300)
		expect(web.status(), 'the web fee was not raised').toBeLessThan(300)
		const deskFee = (await desk.json()).fee
		const webFee = (await web.json()).fee
		expect(deskFee.amount).not.toBe(webFee.amount)
	})

	test('a case type with no published fee raises nothing at all', async () => {
		const free = await seedCase(api, token, {
			title: `${RUN_PREFIX} Melding`,
			intakeChannel: 'website',
		})
		const answer = await raiseLeges(free, 'web')

		// 404, and the body says there is no published fee: a silent success
		// raising a zero-amount request would put a €0 line in the books.
		expect(answer.status()).toBe(404)
		expect(await answer.text()).toContain('no published fee')
	})

	test('the article of the legesverordening is named on the case', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${cases.desk}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// Read from shillinq's panel, which is where the citation lives. A
		// citizen may ask why they are being charged, and "because the
		// configuration says so" is not an answer a gemeente may give.
		const panel = page.getByRole('region', {
			name: /Fees and payments|Leges en betalingen/,
		})
		await expect(panel).toBeVisible()
		await expect(panel).toContainText(/artikel|article/i)
	})
})

test.describe('the payment state is on the case and in the list', () => {
	test('an outstanding fee shows on the case and on the Cases list', async ({
		page,
	}) => {
		trackDossiqErrors(page)

		await page.goto(`${APP_URL}cases/${cases.desk}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByText(/Outstanding|Openstaand/)).toBeVisible()

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page
			.getByRole('tab', { name: /^(Awaiting payment|Wacht op betaling)$/ })
			.click()
		await expect(
			page
				.getByRole('row')
				.filter({ hasText: `${RUN_PREFIX} Balie aanvraag` }),
		).toBeVisible()
	})

	test('the case record carries the state and no amount received', async () => {
		const row = await (
			await api.get(
				`/index.php/apps/openregister/api/objects/dossiq/case/${cases.desk}`,
				{
					headers: { requesttoken: token },
				},
			)
		).json()

		expect(row.paymentState).toBe('outstanding')
		// The projection is a word and a timestamp. Anything that looks like
		// an amount here is a second set of books.
		expect(row.amountReceived).toBeUndefined()
		expect(row.paymentStateCheckedAt).toBeTruthy()
	})

	test('a state that cannot be refreshed reads stale and never paid', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		// The projection is only ever written from a successful read, so a
		// case whose payment leaf answers an error keeps its last state and
		// the gate reads stale. Simulated at the boundary dossiq owns: the
		// leaf request fails while the page is open.
		await page.route('**/leaves/shillinq-payment-requests**', (route) =>
			route.fulfill({ status: 503, body: '{}' }),
		)
		await page.goto(`${APP_URL}cases/${cases.desk}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText(/Paid|Betaald/)).toHaveCount(0)
	})
})

test.describe('the case type decides whether an unpaid case proceeds', () => {
	test('an unpaid aanvraag is refused its move, and the refusal names the rule', async () => {
		// The write path, not the menu: a greyed button is a suggestion until
		// posting the move meets the same answer. Probed as an ordinary
		// handler, the least privileged principal who could try it.
		const answer = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.desk}/transition`,
			{
				headers: { requesttoken: token },
				data: { transitionId: 'in-behandeling' },
			},
		)

		expect(answer.status()).toBeGreaterThanOrEqual(400)
		expect(answer.status()).toBeLessThan(500)
		expect(await answer.text()).toMatch(/settled first/)
	})

	test('a melding does not wait for money', async () => {
		const melding = await seedCase(api, token, {
			title: `${RUN_PREFIX} Melding openbare ruimte`,
		})
		const answer = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${melding}/transition`,
			{
				headers: { requesttoken: token },
				data: { transitionId: 'in-behandeling' },
			},
		)

		// Whatever else it says, it does not say the money is in the way.
		expect(await answer.text()).not.toMatch(/settled first/)
	})
})

test.describe('a case names the contract it was raised under', () => {
	test('the case shows the contract and copies none of its terms', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${cases.web}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const panel = page.getByRole('region', { name: /Contract/ })
		await expect(panel).toBeVisible()

		const row = await (
			await api.get(
				`/index.php/apps/openregister/api/objects/dossiq/case/${cases.web}`,
				{
					headers: { requesttoken: token },
				},
			)
		).json()
		// The reference, and nothing of the contract itself: a copied term
		// goes stale and then alerts on the wrong date.
		expect(row.contractEndDate).toBeUndefined()
		expect(row.contractValue).toBeUndefined()
	})
})
