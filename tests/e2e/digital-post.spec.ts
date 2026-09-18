/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A handler can send digital post from the case, and is told the truth about
 * what happened to it.
 *
 * `BerichtenboxComposeDialog.vue` was referenced nowhere in dossiq until
 * 2026-09-18: not by `src/registry.js`, not by `src/manifest.json`, not by
 * another component. The only occurrence of its name in the repository was its
 * own `name:` line. So the compose surface worked, the routing service worked,
 * the message was stored on the case, and nobody could press anything to reach
 * any of it.
 *
 * WHAT IS DRIVEN HERE is the state every instance in this fleet is actually
 * in: integriq's live network leg is blocked on three things integriq#2062
 * names itself (Logius BBK OAuth client credentials, a PKIoverheid
 * Services-server certificate, and `CredentialBrokerService::issueSigningMaterial`
 * in OpenRegister). So a send is REFUSED here, and the refusal is exactly what
 * this change puts in front of a handler. A test that needed a real letter to
 * arrive would be a test that can never run.
 *
 * The cross-app dispatch arms are `@e2e exclude`d in the spec and covered by
 * BerichtenboxServiceRefusalTest and IntegriqAdapterTest over doubled
 * collaborators.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run against, so this is written and tagged, not executed.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const CASE_TITLE = `${RUN_PREFIX} Digitale post`

test.describe('digital post reaches integriq, or says why it did not', () => {
	test.setTimeout(300_000)

	let caseId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		// `initiatorType: person` is what the header action's `visibleWhen`
		// tests. A case without it renders no button at all, and the absence
		// would read as a broken action rather than as the gate working.
		const created = await createObject(request, token, 'case', {
			title: CASE_TITLE,
			description: 'Digital post run',
			initiatorType: 'person',
			initiatorDisplayName: 'A. Burger',
			initiatorSourceId: '123456782',
		})
		caseId = objectId(created)
		trackCreatedObject('case', caseId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md#the-compose-dialog-opens-from-the-case
	test('the compose dialog opens from the case, with the recipient filled in', async ({
		page,
	}) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)

		await page.getByRole('button', { name: 'Send digital post' }).click()

		const dialog = page.locator('.compose-dialog')
		await expect(dialog).toBeVisible({ timeout: 30_000 })
		// The recipient came off the case, not from the handler's memory: a
		// BSN typed from memory addresses a letter to somebody else.
		await expect(dialog.locator('input').first()).toHaveValue('123456782')
	})

	// @e2e openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md#the-refusal-reaches-the-handler-in-full
	test('a refusal reaches the handler in full, and the dialog stays open', async ({
		page,
	}) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)
		await page.getByRole('button', { name: 'Send digital post' }).click()

		const dialog = page.locator('.compose-dialog')
		await expect(dialog).toBeVisible({ timeout: 30_000 })
		await dialog.locator('textarea').fill('De tekst van de brief')
		await dialog.locator('input').nth(1).fill('Besluit dakkapel')
		await page.getByRole('button', { name: /^Send$/ }).click()

		// The dialog is STILL OPEN, which is half the assertion: a dialog that
		// closed would have told the handler the letter went out.
		await expect(dialog).toBeVisible()
		const note = dialog.locator('.NcNoteCard, [class*="notecard"]')
		await expect(note).toBeVisible({ timeout: 30_000 })
		// Not the word "failed" on its own: the sentence names what is missing,
		// which is what a handler can act on.
		await expect(note).not.toHaveText(/^Failed to send message$/)
	})

	// @e2e openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md#a-refusal-is-never-recorded-as-a-delivery
	test('the case does not report the message as sent', async ({ request }) => {
		const token = await getRequestToken(request)
		const response = await request.get(
			`/apps/dossiq/api/berichtenbox/messages?caseId=${caseId}`,
			{ headers: { requesttoken: token } },
		)
		expect(response.ok()).toBeTruthy()

		const messages = (await response.json())?.messages ?? []
		for (const message of messages) {
			expect(message.status).not.toBe('sent')
			expect(message.externalMessageId ?? null).toBeNull()
		}
	})

	// @e2e openspec/changes/digital-post-consumes-integriq/specs/berichtenbox-integration/spec.md#the-compose-dialog-opens-from-the-case
	test('a caller who may not change the case cannot send on it', async ({
		request,
	}) => {
		// The least privileged principal that should be refused: an anonymous
		// caller with no session and no request token. Sending a statutory
		// message into a citizen's message box is externally visible and not
		// undoable, so this is the strictest guard in the controller.
		const response = await request.post('/apps/dossiq/api/berichtenbox/send', {
			data: { caseId, bsn: '123456782', subject: 'X', body: 'Y' },
			failOnStatusCode: false,
		})

		expect([401, 403, 412]).toContain(response.status())
	})
})
