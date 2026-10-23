/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakContact: the reply box of "Gesprek met de inwoner" says the
 * answer goes to the message box in the portal and offers a switch,
 * "Vastleggen als contactmoment op deze zaak", on by default. The switch's
 * state travels with the reply into the portal message dialog, which then
 * also logs the sent message as an outgoing contact moment on the case.
 *
 * `residentReplyOption.spec.js` and `portalMessageDialog.spec.js` prove the
 * config and the dialog. Only a browser shows the switch is drawn on a real
 * case page and that its state reaches the dialog.
 *
 * SENDING IS A WRITE. It puts a message in a resident's portal inbox and a
 * contact moment on the case, so the send step is tagged @needs-live and
 * fixme by default; DOSSIQ_E2E_LIVE_SEND=1 runs it on a disposable rig.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/**
 * The hint and the switch are nextcloud-vue props that are not released yet.
 * Set DOSSIQ_E2E_UNRELEASED_NCV=1 on an instance built against that branch to
 * run them before the release.
 */
const NEEDS_RELEASE = 'needs nextcloud-vue feat/r23-conversation-reply-option (b514274e1), batch 24t1b, '
	+ 'not in 2.127.0. Drop this fixme when dossiq\'s lock carries that release.'

const UNRELEASED = process.env.DOSSIQ_E2E_UNRELEASED_NCV !== '1'

let api: APIRequestContext
let token = ''
let caseId = ''

/**
 * Open the case on its Contact tab and return the conversation's reply area.
 *
 * @param page The page under test.
 */
async function openConversation(page: Page) {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	await strip.getByRole('tab', { name: /^Contact/ }).first().click()
	const option = page.getByTestId('cn-conversation-reply-option')
	await expect(option, 'the reply box offers the contact-moment switch').toBeVisible({ timeout: 30_000 })
	return option
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	caseId = objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} Antwoord aan de inwoner`,
			caseType: machine.caseTypeId,
			description: 'Seeded by the reply-logs-a-contact-moment layer.',
		}),
	)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('The reply to the resident can be logged as a contact moment', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/portal-contribution/spec.md#answer-a-residents-question-from-the-case
	test('the reply box says where the answer goes and the switch is on by default', async ({ page }) => {
		test.fixme(UNRELEASED, NEEDS_RELEASE)
		const errors = trackDossiqErrors(page)
		const option = await openConversation(page)

		await expect(page.getByTestId('cn-conversation-reply-hint')).toHaveText(
			/^(Goes to the message box in the portal|Gaat naar de berichtenbox in het portaal)$/,
		)
		await expect(option).toContainText(
			/Log as a contact moment on this case|Vastleggen als contactmoment op deze zaak/,
		)
		const box = option.locator('input[type="checkbox"]')
		await expect(box).toBeChecked()
		await box.uncheck()
		await expect(box).not.toBeChecked()
		await box.check()
		await expect(box).toBeChecked()
		expect(errors).toEqual([])
	})

	// @needs-live: sends a portal message and logs a contact moment.
	// @e2e openspec/specs/portal-contribution/spec.md#answer-a-residents-question-from-the-case
	test('@needs-live sending with the switch on logs an outgoing contact moment', async ({ page }) => {
		test.fixme(UNRELEASED, NEEDS_RELEASE)
		test.fixme(
			process.env.DOSSIQ_E2E_LIVE_SEND !== '1',
			'@needs-live: sending writes a portal message and a contact moment. '
				+ 'Set DOSSIQ_E2E_LIVE_SEND=1 on a disposable rig to run it.',
		)
		await openConversation(page)
		const text = `${RUN_PREFIX} Uw aanvraag is in behandeling.`
		await page.locator('.cn-conversation-thread textarea').first().fill(text)
		await page.locator('.cn-conversation-thread').getByRole('button', { name: /^(Send|Versturen|Verstuur)/ }).first().click()

		const dialog = page.getByTestId('portal-message-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await dialog.getByTestId('portal-message-send').click()
		await expect(dialog).toHaveCount(0, { timeout: 30_000 })

		const moments = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/contactmoment`,
			{ params: { case: caseId, _limit: '50' }, headers: { requesttoken: token } },
		)
		expect(moments.ok()).toBeTruthy()
		const body = await moments.json()
		const rows = (body?.results ?? body ?? []) as any[]
		const logged = rows.find((row) => String(row.summary ?? '').includes(text))
		expect(logged, 'the sent answer is a contact moment on the case').toBeTruthy()
		expect(logged.direction).toBe('outbound')
	})
})
