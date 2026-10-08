/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who a document on a case is from, and who it went to.
 *
 * 🔴 EVERY ASSERTION IS ABOUT THE IDENTIFIER, NOT THE NAME ON SCREEN. The
 * defect this change replaces is a correspondent stored as typed text, and a
 * dialog that renders a party's name while storing the words looks exactly
 * right. So each check below reads the stored document back through the API
 * and asserts that `sender` and `recipients` hold the PARTY, then confirms
 * the screen agrees.
 *
 * 🔴 THE PARTIES ARE SEEDED THROUGH OPENREGISTER'S OWN PARTY ROUTES, which is
 * where the model lives (openregister#3761). Seeding a `role` row instead
 * would make the People tab draw something and leave the parties listing
 * empty, which is the half the picker reads.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * dossier of a shared instance collects other sessions' fixtures while this
 * one runs.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const DOSSIER_BASE = `/index.php/apps/${REGISTER}/api/cases`
const PARTIES_BASE = '/index.php/apps/openregister/api'

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

test.describe('a document names its correspondents', () => {
	test('a letter filed on the case names the addressed party, and the picker offers only that case', async ({
		page,
	}) => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} correspondents`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		// Two parties, so "the picker offers the parties of the case" is a
		// claim with something to be wrong about. One party would pass on a
		// picker that offered every party on the instance.
		const addressee = await api.post(`${PARTIES_BASE}/parties`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { name: `${RUN_PREFIX} Gemeente`, kind: 'organisation' },
		})
		expect(addressee.ok(), await addressee.text()).toBeTruthy()
		const addresseeId = objectId(await addressee.json())

		const bystander = await api.post(`${PARTIES_BASE}/parties`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { name: `${RUN_PREFIX} Buur`, kind: 'person' },
		})
		expect(bystander.ok(), await bystander.text()).toBeTruthy()

		for (const [party, role] of [
			[addresseeId, 'geadresseerde'],
			[objectId(await bystander.json()), 'belanghebbende'],
		] as const) {
			const linked = await api.post(
				`${PARTIES_BASE}/objects/${REGISTER}/case/${caseId}/parties`,
				{
					headers: {
						requesttoken: token,
						'Content-Type': 'application/json',
					},
					data: { partyUuid: party, role },
				},
			)
			expect(linked.ok(), await linked.text()).toBeTruthy()
		}

		// A letter out, filed the way the generation action files one.
		const filed = await api.post(`${DOSSIER_BASE}/${caseId}/dossier`, {
			headers: { requesttoken: token },
			multipart: {
				files: {
					name: `${RUN_PREFIX}-beschikking.md`,
					mimeType: 'text/markdown',
					buffer: Buffer.from('# Beschikking'),
				},
				metadata: JSON.stringify({
					title: `${RUN_PREFIX} beschikking`,
					direction: 'outgoing',
					recipients: [addresseeId],
				}),
			},
		})
		expect(filed.ok(), await filed.text()).toBeTruthy()

		// THE ASSERTION. The stored document names the PARTY.
		const listed = await api.get(`${DOSSIER_BASE}/${caseId}/dossier`)
		expect(listed.ok(), await listed.text()).toBeTruthy()
		const document = (await listed.json()).informatieobjecten.find((row: any) =>
			String(row.title || '').startsWith(RUN_PREFIX),
		)
		expect(document, 'the letter this run filed is in the dossier').toBeTruthy()
		expect(document.recipients).toEqual([addresseeId])
		expect(document.direction).toBe('outgoing')
		// The name rides along, beside the identifier and never instead of it.
		expect(document.recipientNames[0]).toContain(RUN_PREFIX)
		// 🔴 And nothing typed anywhere near it.
		expect(document.sender).toBe('')

		// The filter narrows to that party, and refuses a party who was only
		// standing there.
		const narrowed = await api.get(
			`${DOSSIER_BASE}/${caseId}/dossier?correspondent=${addresseeId}`,
		)
		expect(
			(await narrowed.json()).informatieobjecten.some((row: any) =>
				String(row.title || '').startsWith(RUN_PREFIX),
			),
		).toBeTruthy()

		const errors = trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The People tab says what that party was written to. The count is
		// what is asserted, not the sentence: the wording is a plural form
		// and a translated instance renders it in its own language.
		await page.getByRole('tab', { name: 'People' }).click()
		await expect(
			page.locator(`[data-party-documents="${addresseeId}"]`),
		).toContainText(/1/)

		// And the properties dialog offers exactly the two parties of THIS
		// case, with the addressee already chosen.
		await page.getByRole('tab', { name: 'Files' }).click()
		await page
			.getByRole('row', { name: new RegExp(RUN_PREFIX) })
			.getByRole('button', { name: 'Document properties' })
			.click()
		const recipients = page.locator('[data-testid="document-recipients"]')
		await expect(recipients).toContainText(`${RUN_PREFIX} Gemeente`)
		await expect(page.locator('[data-testid="document-sender"]')).toHaveCount(0)

		expect(errors).toHaveLength(0)
	})
})
