/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A document says whether anyone has checked it.
 *
 * The scan is the platform's: `files_antivirus` checks the node and records
 * what it found. dossiq shows that verdict where a handler decides whether to
 * open an attachment, beside the checksum, and an instance with no scanner
 * reads Not scanned rather than clean (company ADR-102).
 *
 * The clean case is deliberately NOT driven here. Driving it would mean
 * installing and configuring a virus scanner in CI and feeding it a file it
 * recognises, which tests the scanner rather than this change; the spec
 * excludes that scenario by name and a unit test over a stubbed reader covers
 * it instead. What is driven here is the case every instance in this fleet is
 * actually in: no scanner, and therefore no claim.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run against, so this is written and tagged, not executed, and no mutation
 * check backs it yet. The testids it targets (`document-scan-verdict`,
 * `document-hash`) are introduced by this same change in
 * `DocumentMetadataDialog.vue`.
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

const CASE_TITLE = `${RUN_PREFIX} Scanverdict`

test.describe('scan verdict on the row', () => {
	test.setTimeout(300_000)

	let caseId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, 'case', {
			title: CASE_TITLE,
			description: 'Scan verdict run',
		})
		caseId = objectId(created)
		trackCreatedObject('case', caseId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md#no-scanner-no-claim
	test('no scanner, no claim', async ({ page, request }) => {
		await page.goto(`/apps/dossiq/cases/${caseId}`)
		await page
			.getByRole('tab', { name: /Documents|Files/ })
			.first()
			.click()

		const row = page.locator('[data-testid="cn-files-row"]').first()
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.click({ button: 'right' })
		await page.getByText('Document properties').click()

		const verdict = page.locator('[data-testid="document-scan-verdict"]')
		await expect(verdict).toBeVisible({ timeout: 30_000 })
		await expect(verdict).toHaveText('Not scanned')
		await expect(page.locator('[data-testid="document-hash"]')).toBeVisible()

		// The endpoint says the same thing the dialog does, and says it about
		// the scanner rather than about the file: an instance with no scanner
		// reports one absent, so nobody can read the answer as a clean file.
		const token = await getRequestToken(request)
		const fileId = await row.getAttribute('data-file-id')
		const response = await request.get(`/apps/dossiq/api/files/${fileId}/scan`, {
			headers: { requesttoken: token },
		})
		expect(response.status()).toBe(200)
		const verdictBody = await response.json()
		expect(verdictBody.state).toBe('not-scanned')
		expect(verdictBody.scannerPresent).toBe(false)
	})

	// @e2e openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md#no-scanner-no-claim
	test('a caller who cannot see the file is told nothing about it', async ({
		request,
	}) => {
		// The least privileged principal that should be refused: a file id
		// nobody in this session can reach. A 404 and not a verdict, because a
		// verdict on an unreachable id would enumerate the instance one file
		// at a time.
		const token = await getRequestToken(request)
		const response = await request.get('/apps/dossiq/api/files/999999999/scan', {
			headers: { requesttoken: token },
		})
		expect(response.status()).toBe(404)
	})
})
