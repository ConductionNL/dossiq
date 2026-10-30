/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case Files tab shows a document's type, direction and sender in their
 * own columns, read from the dossier listing.
 *
 * The listing is asserted first, through the API, so a failure says whether
 * the projection or the page is wrong. The row is addressed by its run
 * prefix, never by position: a shared instance collects other sessions'
 * fixtures while this one runs.
 *
 * @spec openspec/changes/case-files-list-columns/specs/document-zaakdossier/spec.md#requirement-the-case-files-list-shows-the-document-columns-of-the-board-req-zak-col-01
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

test.describe('the case files list columns', () => {
	test('a received letter shows its sender and type', async ({ page }) => {
		const caseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} files columns`,
				caseType: caseTypeId,
			}),
		)

		const party = await api.post(`${PARTIES_BASE}/parties`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { name: `${RUN_PREFIX} J. de Vries`, kind: 'person' },
		})
		expect(party.ok(), await party.text()).toBeTruthy()
		const partyId = objectId(await party.json())

		const linked = await api.post(
			`${PARTIES_BASE}/objects/${REGISTER}/case/${caseId}/parties`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { partyUuid: partyId, role: 'afzender' },
			},
		)
		expect(linked.ok(), await linked.text()).toBeTruthy()

		const filed = await api.post(`${DOSSIER_BASE}/${caseId}/dossier`, {
			headers: { requesttoken: token },
			multipart: {
				files: {
					name: `${RUN_PREFIX}-aanvraag.md`,
					mimeType: 'text/markdown',
					buffer: Buffer.from('# Aanvraag'),
				},
				metadata: JSON.stringify({
					title: `${RUN_PREFIX} aanvraag`,
					informatieobjecttype: 'Aanvraag',
					direction: 'incoming',
					sender: partyId,
				}),
			},
		})
		expect(filed.ok(), await filed.text()).toBeTruthy()

		const listed = await api.get(`${DOSSIER_BASE}/${caseId}/dossier`)
		expect(listed.ok(), await listed.text()).toBeTruthy()
		const document = (await listed.json()).informatieobjecten.find((row: any) =>
			String(row.title || '').startsWith(RUN_PREFIX),
		)
		expect(document, 'the letter this run filed is in the dossier').toBeTruthy()
		expect(document.documentType).toBe('Aanvraag')
		expect(document.direction).toBe('incoming')
		expect(document.partyName).toBe(`${RUN_PREFIX} J. de Vries`)

		const errors = trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'Files' }).click()

		// The three columns sit in the Columns chooser, off until ticked.
		await page
			.getByTestId('cn-files-browser-columns')
			.getByRole('button')
			.first()
			.click()
		for (const key of ['documentType', 'direction', 'partyName']) {
			await page.getByTestId(`cn-files-browser-column-${key}`).click()
		}
		await page.keyboard.press('Escape')

		const row = page.getByRole('row', { name: new RegExp(RUN_PREFIX) })
		await expect(
			row.getByTestId('cn-files-browser-cell-documentType'),
		).toHaveText('Aanvraag')
		await expect(row.getByTestId('cn-files-browser-cell-direction')).toHaveText(
			/Incoming|Inkomend/,
		)
		await expect(row.getByTestId('cn-files-browser-cell-partyName')).toHaveText(
			`${RUN_PREFIX} J. de Vries`,
		)

		expect(errors).toHaveLength(0)
	})
})
