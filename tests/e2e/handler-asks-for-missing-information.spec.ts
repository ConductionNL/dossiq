/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The handler asks the applicant for missing information from the case page
 * (REQ-AVR-05): the case header's "Ask for missing information" action opens
 * the form, the form says what the applicant receives before anything is
 * sent, and a refusal shows the server's own sentence.
 *
 * WHY THIS NEEDS A BROWSER. The modal is unit-tested to the payload and the
 * refusal sentence (`aanvullingVragenModal.spec.js`) and the endpoint to the
 * record and the suspension (`aanvullingsverzoek-as-a-record.spec.ts`). What
 * neither shows is the seam between them: that the header offers the action
 * on a case with a running term, that the action opens THIS form, and that a
 * refusal coming back from the real endpoint reaches the form verbatim.
 *
 * 🔴 NOTHING HERE SEEDS AN `aanvullingsverzoek` DIRECTLY. The open request the
 * second scenario needs is made through the endpoint, as a colleague asking
 * first would make it, after this handler opened the form: the header stops
 * offering the action once a request is open, so the refusal can only be met
 * by a form that was opened before.
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
} from './helpers/fixtures.ts'
import {
	clickHeaderAction,
	dismissSupportDialog,
	PAGE_LOAD,
	trackDossiqErrors,
} from './helpers/nav.ts'

/** The case type every seeded case takes, with a term to suspend. */
let caseType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/**
 * Every request on a case, as the read answers them.
 *
 * @param api    The request context.
 * @param caseId The case.
 */
async function requestsOn(api: any, caseId: string) {
	return (
		await api.get(
			`/index.php/apps/${REGISTER}/api/cases/${caseId}/aanvullingsverzoeken`,
		)
	).json()
}

test.describe('The handler asks for missing information from the case page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} aanvulling vragen`,
				identifier: `${RUN_PREFIX.toLowerCase()}-aanvulling-vragen`,
				description:
					'Throwaway caseType for the ask-from-the-case-page e2e layer.',
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

		for (const key of ['notice', 'refused']) {
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

	// @e2e openspec/specs/termijn-pause-extension/spec.md#the-handler-sees-what-the-applicant-receives
	test('the form says what the applicant receives before anything is sent', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)
		await page.goto(`/index.php/apps/${REGISTER}/cases/${cases.notice}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await clickHeaderAction(page, 'cn-action-request-information')

		const form = page.getByTestId('aanvulling-vragen-modal')
		await expect(form, 'the action opens the ask form').toBeVisible({
			timeout: 20_000,
		})
		// The notice is there before a single field is filled: the handler
		// reads what goes out before deciding to send it.
		await expect(form.getByTestId('aanvulling-vragen-notice')).toBeVisible()
		for (const field of [
			'aanvulling-vragen-items',
			'aanvulling-vragen-recipient',
			'aanvulling-vragen-days',
			'aanvulling-vragen-rationale',
		]) {
			await expect(form.getByTestId(field), `${field} is on the form`).toBeVisible()
		}

		// Opening the form sends nothing.
		const api = await playwright.request.newContext({ baseURL })
		const state = await requestsOn(api, cases.notice)
		expect(state.open, 'no request exists until the form is sent').toBe(0)
		await api.dispose()

		expect(errors, 'the case page logs no dossiq error').toEqual([])
	})

	// @e2e openspec/specs/termijn-pause-extension/spec.md#a-request-that-is-already-open-is-refused-with-the-servers-sentence
	test('a request opened meanwhile is refused with the server sentence, and the case keeps one', async ({
		page,
		playwright,
		baseURL,
	}) => {
		await page.goto(`/index.php/apps/${REGISTER}/cases/${cases.refused}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await clickHeaderAction(page, 'cn-action-request-information')
		const form = page.getByTestId('aanvulling-vragen-modal')
		await expect(form).toBeVisible({ timeout: 20_000 })

		// A colleague asks first, through the one endpoint that asks.
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const first = await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.refused}/information-request`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					items: ['Bankafschrift'],
					recipient: 'aanvrager@example.org',
					durationDays: 14,
					rationale: `${RUN_PREFIX} de eerste vraag`,
				},
			},
		)
		expect(first.ok(), 'the colleague’s ask must succeed').toBeTruthy()

		await form.getByTestId('aanvulling-vragen-items').locator('textarea').fill('Huurcontract')
		await form.getByTestId('aanvulling-vragen-recipient').locator('input').fill('aanvrager@example.org')
		await form.getByTestId('aanvulling-vragen-days').locator('input').fill('14')
		await form
			.getByTestId('aanvulling-vragen-rationale')
			.locator('textarea')
			.fill(`${RUN_PREFIX} de tweede vraag`)

		const refusal = page.waitForResponse(
			(response) =>
				response.url().includes(`/api/cases/${cases.refused}/information-request`)
				&& response.request().method() === 'POST',
		)
		await form.getByTestId('aanvulling-vragen-send').click()
		const answered = await refusal
		expect(answered.ok(), 'the second ask is refused').toBe(false)

		// The form shows the server's own sentence, not a general one.
		const body = await answered.json()
		const sentence = String(body.message ?? body.error ?? '')
		expect(sentence, 'the refusal names its reason').not.toBe('')
		await expect(form.getByTestId('aanvulling-vragen-error')).toContainText(sentence)

		// The case keeps its one open request: the colleague's.
		const state = await requestsOn(api, cases.refused)
		expect(state.open).toBe(1)
		expect(String(state.requests[0].rationale)).toContain('de eerste vraag')

		await api.dispose()
	})
})
