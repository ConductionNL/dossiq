/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documents live on the case: a file in the case folder is a document, its
 * properties are edited on its row, and a case joined to a document held
 * elsewhere sees it as a linked row.
 *
 * WHAT THIS SPEC WRITES, AND WHAT IT LEAVES. Two cases (archival, purged
 * through the case purge helper), one file in case A's folder over WebDAV,
 * the informatieobject and joins the projection makes for it. The file, the
 * record and the joins are removed in afterAll; the cases are purged.
 *
 * WHAT IT DOES NOT COVER, AND WHY. The API-first move (REQ-DPR-003) needs
 * the ZGW APIs, which take a JWT this suite has no helper for; it is pinned
 * in tests/Unit/Service/Zaakdossier/DocumentJoinHomingTest.php and
 * DocumentProjectionServiceTest.php, and the spec says so.
 *
 * @spec openspec/specs/document-projection/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	getRequestToken,
	objectId,
	purgeObject,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	tryDeleteObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const FILE_NAME = `${RUN_PREFIX}-drop.pdf`
const FILE_BODY = '%PDF-1.4\n% documents live on the case\n%%EOF\n'
const ADMIN = process.env.ADMIN_USER ?? 'admin'

let api: APIRequestContext
let token: string
let caseA = ''
let caseB = ''
let caseAIdentifier = ''
let registerFolder = ''
let recordId = ''

/**
 * The register folder's name under Open Registers, as OpenRegister names it.
 *
 * @return The folder name.
 */
async function registerFolderName(): Promise<string> {
	const res = await api.get(
		'/index.php/apps/openregister/api/registers?_limit=200',
		{
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		},
	)
	const body = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body.results ?? [])
	const register = rows.find((row) => row.slug === REGISTER)
	expect(register, `register ${REGISTER} must exist`).toBeTruthy()
	const title = String(register.title ?? '')
	return /register$/i.test(title.trim()) ? title : `${title} Register`
}

/**
 * The WebDAV path of a file in a case's folder.
 *
 * @param caseId The case uuid.
 * @param name The file name.
 * @return The DAV path.
 */
function davPath(caseId: string, name: string): string {
	return `/remote.php/dav/files/${encodeURIComponent(ADMIN)}/${encodeURIComponent('Open Registers')}/${encodeURIComponent(registerFolder)}/${encodeURIComponent(caseId)}/${encodeURIComponent(name)}`
}

/**
 * Give a case its own folder, the way opening its Files tab does.
 *
 * A case seeded through the object API has no folder: OpenRegister creates
 * one the first time something asks for the object's files, and until then
 * the path this spec writes to answers 404. The Files tab asks on render, so
 * a handler never sees this; a spec that goes straight to WebDAV must ask
 * first, or it measures the absent folder rather than the projection.
 *
 * @param caseId The case uuid.
 * @return Nothing; the folder exists when it resolves.
 */
async function materialiseFolder(caseId: string): Promise<void> {
	const res = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}/files`,
		{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
	)
	expect(
		res.ok(),
		`the case's folder must be created, got ${res.status()}`,
	).toBeTruthy()
}

/**
 * The case's dossier as the app lists it.
 *
 * @param caseId The case uuid.
 * @return The informatieobjecten rows.
 */
async function dossierOf(caseId: string): Promise<any[]> {
	const res = await api.get(`/index.php/apps/dossiq/api/cases/${caseId}/dossier`, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
	if (!res.ok()) {
		return []
	}
	const body = await res.json()
	return Array.isArray(body?.informatieobjecten) ? body.informatieobjecten : []
}

test.describe('Documents live on the case', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		registerFolder = await registerFolderName()

		const caseTypes = await adoptableCaseTypes(api)
		const chosen = caseTypes[0]
		expect(
			chosen,
			'the instance must ship at least one published case type',
		).toBeTruthy()

		const a = await seedCase(api, token, {
			title: `${RUN_PREFIX} Documents on the case A`,
			caseType: objectId(chosen),
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseA = objectId(a)
		caseAIdentifier = String(
			a.identifier ?? (await showObject(api, 'case', caseA)).identifier ?? '',
		)
		const b = await seedCase(api, token, {
			title: `${RUN_PREFIX} Documents on the case B`,
			caseType: objectId(chosen),
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseB = objectId(b)

		// Both cases need their folder before anything can be dropped into it.
		await materialiseFolder(caseA)
		await materialiseFolder(caseB)
	})

	test.afterAll(async () => {
		// The file first, so the projection retires the record and the joins
		// itself (REQ-DPR-002); what it leaves is removed by hand.
		await api
			.delete(davPath(caseA, FILE_NAME), { headers: { requesttoken: token } })
			.catch(() => undefined)
		if (recordId !== '') {
			await tryDeleteObject(api, token, 'informatieobject', recordId).catch(
				() => undefined,
			)
		}
		for (const id of [caseA, caseB]) {
			if (id !== '') {
				await purgeObject(api, token, 'case', id).catch(() => undefined)
			}
		}
		await api.dispose()
	})

	// @e2e openspec/specs/document-projection/spec.md#a-drop-in-the-files-tab-creates-the-record
	test('a file dropped into the case folder is a document with derived defaults', async () => {
		// Over WebDAV, the way the Files tab's browser uploads: no dialog, no
		// metadata, just the file in the folder.
		// The folder is there (materialiseFolder ran in beforeAll): a 404 here
		// would mean the case never got one, not that the projection failed.
		const folder = await api.fetch(davPath(caseA, ''), {
			method: 'PROPFIND',
			headers: { requesttoken: token, Depth: '0' },
		})
		expect(
			folder.status(),
			`the case folder must exist before the drop, got ${folder.status()}`,
		).toBeLessThan(400)

		const put = await api.put(davPath(caseA, FILE_NAME), {
			headers: {
				requesttoken: token,
				'Content-Type': 'application/pdf',
				'If-None-Match': '*',
			},
			data: FILE_BODY,
		})
		expect(
			[201, 204],
			`the DAV put must succeed, got ${put.status()}`,
		).toContain(put.status())

		// The record appears within the request that stored the file; the
		// poll is for the list, not for a job.
		await expect
			.poll(
				async () =>
					(await dossierOf(caseA)).find(
						(row) => row.fileName === FILE_NAME,
					) ?? null,
				{
					timeout: 30_000,
					message: 'the dropped file must gain an informatieobject',
				},
			)
			.not.toBeNull()

		const record = (await dossierOf(caseA)).find(
			(row) => row.fileName === FILE_NAME,
		)
		recordId = String(record.id ?? record['@self']?.id ?? '')
		expect(recordId).not.toBe('')
		expect(record.title).toBe(FILE_NAME.replace(/\.pdf$/, ''))
		expect(record.format).toBe('application/pdf')
		expect(record.status).toBe('draft')
		expect(String(record.auteur ?? '')).not.toBe('')
		expect(String(record.informatieobjecttype ?? '')).not.toBe('')
		expect(String(record.vertrouwelijkheidaanduiding ?? '')).not.toBe('')
		expect(Number(record.fileId)).toBeGreaterThan(0)

		// A second write refreshes, never duplicates.
		const again = await api.put(davPath(caseA, FILE_NAME), {
			headers: { requesttoken: token, 'Content-Type': 'application/pdf' },
			data: FILE_BODY + '% second write\n',
		})
		expect([200, 201, 204]).toContain(again.status())
		await expect
			.poll(
				async () =>
					(await dossierOf(caseA)).filter(
						(row) => row.fileName === FILE_NAME,
					).length,
				{ timeout: 20_000 },
			)
			.toBe(1)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#a-row-edits-its-documents-properties
	test('Document properties on the row edits the record', async ({ page }) => {
		test.skip(recordId === '', 'no record was projected for the dropped file')
		await page.goto(`/apps/${REGISTER}/cases/${caseA}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip.getByRole('tab', { name: /^(Files|Bestanden)$/ }).click()

		const row = page.locator(
			`[data-testid="cn-files-browser-row"][data-name="${FILE_NAME}"]`,
		)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.locator('.cn-files-browser__col-actions button').first().click()
		await page
			.getByTestId('cn-files-browser-host-action-document-properties')
			.click()

		await expect(page.getByTestId('document-properties-file')).toHaveText(
			FILE_NAME,
			{ timeout: 15_000 },
		)
		await expect(page.getByTestId('document-properties-missing')).toHaveCount(0)
		const title = page.locator('.dossier-metadata-dialog input').first()
		await title.fill(`${RUN_PREFIX} Aanvraagformulier, herzien`)
		await page.getByTestId('document-properties-save').click()

		await expect
			.poll(
				async () =>
					(await dossierOf(caseA)).find(
						(row) => row.fileName === FILE_NAME,
					)?.title ?? '',
				{
					timeout: 20_000,
					message: 'the title must be saved onto the record',
				},
			)
			.toBe(`${RUN_PREFIX} Aanvraagformulier, herzien`)
		// The file itself is untouched: still one row under the same name.
		await expect(
			page.locator(
				`[data-testid="cn-files-browser-row"][data-name="${FILE_NAME}"]`,
			),
		).toHaveCount(1)
	})

	// @e2e openspec/specs/document-projection/spec.md#case-b-sees-as-document-as-a-linked-row
	// @e2e openspec/specs/case-dashboard-view/spec.md#a-document-from-another-case-is-a-linked-row
	test('a document joined from another case is a linked row on that case', async ({
		page,
	}) => {
		test.skip(recordId === '', 'no record was projected for the dropped file')
		const link = await api.post(
			`/index.php/apps/dossiq/api/cases/${caseB}/dossier/${recordId}/link`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: {},
			},
		)
		expect(
			link.ok(),
			`the join must be created, got ${link.status()}`,
		).toBeTruthy()

		await page.goto(`/apps/${REGISTER}/cases/${caseB}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip.getByRole('tab', { name: /^(Files|Bestanden)$/ }).click()

		const linked = page.locator(
			`[data-testid="cn-files-browser-linked-row"][data-name="${FILE_NAME}"]`,
		)
		await expect(linked).toBeVisible({ timeout: 30_000 })
		await expect(linked.locator('.cn-files-browser__note')).toContainText(
			caseAIdentifier || 'In ',
		)
		// Not a file of this folder: no own row, and nothing that changes the file.
		await expect(
			page.locator(
				`[data-testid="cn-files-browser-row"][data-name="${FILE_NAME}"]`,
			),
		).toHaveCount(0)
		await linked.locator('.cn-files-browser__col-actions button').first().click()
		// The testids sit on the action items; the href is on their anchor.
		await expect(
			page.getByTestId('cn-files-browser-linked-open').locator('a'),
		).toHaveAttribute('href', /\/f\/\d+/)
		await expect(
			page.getByTestId('cn-files-browser-linked-download').locator('a'),
		).toHaveAttribute('href', /enkelvoudiginformatieobjecten/)
		await expect(page.getByTestId('cn-files-browser-action-rename')).toHaveCount(
			0,
		)

		// Unlinking B leaves A's file alone (REQ-DPR-004).
		const unlink = await api.delete(
			`/index.php/apps/dossiq/api/cases/${caseB}/dossier/${recordId}/link`,
			{
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			},
		)
		expect(unlink.ok()).toBeTruthy()
		expect(
			(await dossierOf(caseA)).some((row) => row.fileName === FILE_NAME),
		).toBe(true)
	})
})
