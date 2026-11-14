/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Advice on the case: the Advice on the case card on the Decisions tab lists
 * every advice request of the case with its state and deadline, shows the
 * answer once it is in, and reminds the advisor of an open one.
 *
 * Rows are addressed by their run prefix, never by position: a shared
 * instance collects other sessions' fixtures while this one runs.
 *
 * @spec openspec/changes/advice-and-consultation-on-the-case/specs/advice-management/spec.md#requirement-a-handler-asks-for-advice-from-the-case-req-adv-case-01
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`

let api: APIRequestContext
let token: string
let caseTypeId: string

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	caseTypeId = objectId(await ensureCaseType(api, token))
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

/**
 * A case with one advice request on it.
 *
 * @param subject The request's subject, after the run prefix.
 * @param deadline The answer date.
 * @return The case id and the request id.
 */
async function caseWithAdvice(
	subject: string,
	deadline: string,
): Promise<{ caseId: string; adviceId: string }> {
	const caseId = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} advice ${subject}`,
			caseType: caseTypeId,
		}),
	)
	const advice = await createObject(api, token, 'adviesAanvraag', {
		case: caseId,
		subject: `${RUN_PREFIX} ${subject}`,
		advisor: 'Welstand',
		type: 'intern',
		deadline,
		status: 'requested',
		requestedAt: new Date().toISOString(),
	})
	return { caseId, adviceId: objectId(advice) }
}

/**
 * A case opened on the tab with the advice card, and the card's row for one
 * request, found by its subject.
 *
 * @param page The page.
 * @param caseId The case.
 * @param subject The request's subject, after the run prefix.
 * @return The row.
 */
async function adviceRow(page: any, caseId: string, subject: string) {
	await page.goto(`${APP_URL}cases/${caseId}#case-decisions-panel`, PAGE_LOAD)
	await dismissSupportDialog(page)
	await expect(page.getByText('Advice on the case').first()).toBeVisible()
	return page
		.locator('li, tr, [role="row"], [role="listitem"]')
		.filter({ hasText: `${RUN_PREFIX} ${subject}` })
		.last()
}

test.describe('advice on the case', () => {
	test('asking for advice with a deadline', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		const { caseId } = await caseWithAdvice('Welstand', '2099-11-20')

		const row = await adviceRow(page, caseId, 'Welstand')
		await expect(row).toBeVisible()
		await expect(row).toContainText(/20 nov/i)
		await expect(row).toContainText(/Waiting for answer|Open/)

		expect(errors).toHaveLength(0)
	})

	test('the answer shows on the case', async ({ page }) => {
		const { caseId, adviceId } = await caseWithAdvice('Verkeer', '2099-11-20')
		await updateObject(api, token, 'adviesAanvraag', adviceId, {
			status: 'received',
			receivedAt: new Date().toISOString(),
		})

		const row = await adviceRow(page, caseId, 'Verkeer')
		await expect(row).toContainText(/Received|Ontvangen/)
	})

	test('an open request can be reminded', async ({ page }) => {
		// A deadline already past expires the request on save (AdviceTimerListener),
		// and an expired request offers no reminder, so this one is still open.
		const { caseId } = await caseWithAdvice('Milieu', '2099-01-05')

		const row = await adviceRow(page, caseId, 'Milieu')
		const reminded = page.waitForResponse(
			(response) =>
				/\/api\/advice\/[^/]+\/remind$/.test(response.url())
				&& response.request().method() === 'POST',
		)
		await row.getByRole('button', { name: /Remind|Herinneren/ }).click()
		expect((await reminded).ok()).toBeTruthy()
	})
})
