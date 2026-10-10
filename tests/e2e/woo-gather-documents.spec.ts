/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-requests-gather-documents-from-sources: a handler searches the files on
 * the instance from a Woo case, adds two, and finds them on the case waiting
 * for assessment.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here. The suite is
 * written so the live pass owns it.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { journeyBudget, PAGE_LOAD } from './helpers/nav.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** A word nothing else on the instance contains, so the search finds exactly our files. */
const TERM = `stationsweg${RUN_PREFIX.replace(/[^a-z0-9]/gi, '').toLowerCase()}`

test.describe('Gather documents on a Woo case', () => {
	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	// @e2e openspec/specs/woo-case-type/spec.md#a-source-that-is-not-connected
	// @e2e openspec/specs/woo-case-type/spec.md#search-files-and-other-cases-at-once
	// @e2e openspec/specs/woo-case-type/spec.md#two-files-added-one-refused
	// @e2e openspec/specs/woo-case-type/spec.md#provenance-is-on-the-document
	test('a handler searches files, adds two, and finds them outstanding for assessment', async ({
		page,
		request,
	}) => {
		test.setTimeout(journeyBudget(2))
		const token = await getRequestToken(request)
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }

		// Two files about the subject in two folders the handler can read.
		for (const [folder, name] of [
			['Team Ruimte', `${TERM}-notulen.txt`],
			['Team Verkeer', `${TERM}-memo.txt`],
		]) {
			const dir = `/remote.php/dav/files/${encodeURIComponent(ADMIN)}/${encodeURIComponent(folder)}`
			await request.fetch(dir, { method: 'MKCOL', headers })
			const put = await request.put(`${dir}/${encodeURIComponent(name)}`, {
				headers,
				data: `Notities over ${TERM}`,
			})
			expect(put.ok(), `upload ${name} -> ${put.status()}`).toBeTruthy()
		}

		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} Woo-verzoek Stationsweg`,
			caseType: WOO_CASE_TYPE,
		})
		const caseId = objectId(created)

		const sources = await request.get(
			`/index.php/apps/dossiq/api/cases/${caseId}/woo/sources`,
			{ headers },
		)
		expect(sources.ok()).toBeTruthy()
		const ids = ((await sources.json()).sources ?? []).map(
			(source: any) => source.id,
		)
		expect(ids).toEqual(['files', 'cases', 'microsoft365'])
		// A source that cannot answer says why instead of showing no results.
		const microsoft365 = ((await sources.json()).sources ?? []).find(
			(source: any) => source.id === 'microsoft365',
		)
		expect(microsoft365.available || microsoft365.reason !== '').toBeTruthy()

		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		await page.getByRole('button', { name: 'Gather documents' }).click()
		const dialog = page.getByTestId('gather-documents-dialog')
		if (!microsoft365.available) {
			await expect(
				dialog.getByTestId('gather-source-reason-microsoft365'),
			).toContainText('Not connected')
		}
		await dialog.getByTestId('gather-terms').locator('input').fill(TERM)
		await dialog.getByTestId('gather-search').click()

		const files = dialog.getByTestId('gather-results-files')
		await expect(files.getByText(`${TERM}-notulen.txt`)).toBeVisible(PAGE_LOAD)
		await expect(files.getByText(`${TERM}-memo.txt`)).toBeVisible()
		await files.getByText(`${TERM}-notulen.txt`).click()
		await files.getByText(`${TERM}-memo.txt`).click()
		await dialog.getByTestId('gather-add').click()
		await expect(dialog).toBeHidden(PAGE_LOAD)

		// Both are documents on the case, carrying where they were found.
		const dossier = await request.get(
			`/index.php/apps/dossiq/api/cases/${caseId}/dossier`,
			{ headers },
		)
		const documents: any[] = (await dossier.json()).informatieobjecten ?? []
		const gathered = documents.filter((doc) =>
			String(doc.fileName ?? '').startsWith(TERM),
		)
		expect(gathered).toHaveLength(2)
		for (const doc of gathered) {
			expect(doc.provenance?.source).toBe('files')
			expect(doc.provenance?.terms).toBe(TERM)
			expect(doc.provenance?.searchedBy).toBe(ADMIN)
		}
	})
})
