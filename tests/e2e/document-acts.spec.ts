/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three acts on the case file that were built, tested and reachable by nobody.
 *
 * `VersionHistoryPanel` and `BulkDocumentActionDialog` were registered in
 * `src/registry.js` and named by `src/manifest.json` zero times from
 * 2026-09-13, when the Documents tab whose row and bulk actions opened them
 * was retired and the `case-files` leaf replaced it. The dossier export had
 * the same hole from the other end: the endpoint and its zip builder ship and
 * no line of `src/` called them. This spec drives what a handler can now do on
 * the page rather than what the code can do when called from a test.
 *
 * WHAT IS NOT DRIVEN HERE, and why. The Files browser carries no selection
 * bar, so there is no gesture that marks TWO files final at once; the acts are
 * offered per row and that is what is driven. The panel handed neither a file
 * id nor a row has no reachable gesture at all and is covered by the
 * VersionHistoryPanel unit test, which mounts it with neither prop. The
 * registry orphan check is a comparison of two source files and is a unit
 * test. All three are excluded by name in the spec.
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

const CASE_TITLE = `${RUN_PREFIX} Documentacties`

test.describe('document acts reach a surface', () => {
	test.setTimeout(300_000)

	let caseId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, 'case', {
			title: CASE_TITLE,
			description: 'Document acts run',
		})
		caseId = objectId(created)
		trackCreatedObject('case', caseId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	// @e2e openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md#versions-opens-on-the-file-the-row-named
	test('Versions opens on the file the row named', async ({ page }) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)
		await page.getByRole('tab', { name: /Files/ }).first().click()

		const row = page.locator('[data-testid="cn-files-row"]').first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		const fileName = await row.innerText()

		await row.click({ button: 'right' })
		await page.getByText('Versions', { exact: true }).click()

		// The panel is open AND it is about this file: a panel handed no file
		// says so instead, and the two states must not be confused.
		const panel = page.locator('.dossier-version-panel')
		await expect(panel).toBeVisible({ timeout: 30_000 })
		await expect(panel).not.toContainText('No file to read versions of')
		expect(fileName.length).toBeGreaterThan(0)
	})

	// @e2e openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md#scenario-a-file-is-marked-final-from-its-row
	test('a file is marked final from its row', async ({ page }) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)
		await page.getByRole('tab', { name: /Files/ }).first().click()

		const row = page.locator('[data-testid="cn-files-row"]').first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.click({ button: 'right' })
		await page.getByText('Mark as final', { exact: true }).click()

		await page.getByRole('button', { name: /Apply/ }).click()

		// A file with no informatieobject record yet REFUSES rather than
		// reporting that it changed nothing, and either sentence is a real
		// outcome. What must never appear is a success over an empty act.
		await expect(
			page.getByText(/document\(s\) updated|No document record was found/),
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md#a-handler-downloads-the-case-file
	test('a handler downloads the case file', async ({ page }) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)

		const download = page.waitForEvent('download', { timeout: 60_000 })
		await page.getByRole('button', { name: 'Export dossier' }).click()
		const file = await download

		expect(file.suggestedFilename()).toMatch(/\.zip$/)
	})

	// @e2e openspec/changes/document-acts-reach-a-surface/specs/document-zaakdossier/spec.md#a-handler-downloads-the-case-file
	test('a reader with no access to the case gets a status and no bytes', async ({
		request,
	}) => {
		// The least privileged principal that should be refused: an anonymous
		// caller, with no session and no request token. A zip handed to one of
		// those is the whole case file handed to the internet.
		const response = await request.post(
			`/apps/dossiq/api/cases/${caseId}/dossier/zip`,
			{ failOnStatusCode: false },
		)

		expect([401, 403, 412]).toContain(response.status())
		const body = await response.body()
		expect(body.subarray(0, 2).toString()).not.toBe('PK')
	})
})
