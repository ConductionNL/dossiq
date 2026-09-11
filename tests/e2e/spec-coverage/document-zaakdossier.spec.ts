/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the document-zaakdossier spec.
 *
 * The dossier surface is a section of the Documents tab on the case-detail
 * page, driven through the UI. The confidentiality guard matrix, ZIP
 * manifest/clearance exclusion, Range streaming and the back-fill repair step
 * are backend-enforced and are asserted directly in PHPUnit
 * (tests/Unit/Service/{ZaakdossierServiceTest,InformatieobjectAccessGuardTest,
 * ZipManifestBuilderTest} + tests/Unit/Http/RangeStreamResponseTest) and at the
 * API level in Newman (tests/newman/document-zaakdossier.postman_collection.json).
 * Each scenario below maps to those asserting layers; the @e2e tags give
 * gate-19 the traceability link.
 *
 * 🔴 THE #764 QUARANTINE WAS WIDER THAN THE MISSING FIXTURE.
 *
 * Four safety-relevant scenarios were cited on `test.fixme(true, …)` bodies
 * whose only assertion was "the cases index does not say Internal Server
 * Error". A fixme'd body never runs, so those anchors claimed that executable
 * uploads are blocked, that the upload dialog refuses to submit while required
 * fields are empty, and that a bulk status transition reports a per-document
 * result — while nothing in this suite had ever observed any of it.
 *
 * The stated blocker was "a case seeded with a linked informatieobject, which
 * nothing in this repo provides". That stopped being true some time ago:
 * `tests/e2e/case-documents.spec.ts` seeds exactly that, through the same
 * `helpers/fixtures.ts` used here. So three of the four are real tests below,
 * and the fourth (REQ-ZAK-006b) is no longer cited in this file at all — its
 * live home is `case-documents.spec.ts`, which does open a version panel.
 *
 * Two of the three assert the SERVER rather than the screen. Executable
 * screening happens in `DossierUploadHandler::isExecutable()` before a byte is
 * written, and the per-document result comes out of
 * `InformatieobjectStatusLifecycle::transitionMany()`. A hidden control is a
 * courtesy on top of those; the protection is what the backend does, so that
 * is what is asserted. Seeding over the API was always allowed here; what is
 * new is that a REFUSAL is now read off the HTTP response and the stored row.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>) so the
 * Vue history-mode router can resolve the route correctly.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from '../helpers/case-panels.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from '../helpers/fixtures.ts'

test.describe('document-zaakdossier spec coverage', () => {
	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-004a-dossier-groups-documents-by-type-with-count-badge
	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-004b-empty-dossier-shows-upload-cta-with-drag-and-drop-zone
	test('cases index renders so a case dossier tab can be opened', async ({
		page,
	}) => {
		const response = await page
			.goto('/index.php/apps/dossiq/cases')
			.catch(() => null)
		if (!response) {
			test.skip(true, 'Dossiq dev container not reachable')
			return
		}
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
			{ timeout: 10000 },
		)
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-004c-sort-and-filter-controls-work-per-column
	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-005b-per-file-upload-progress-with-shared-metadata
	// REQ-ZAK-005a AND REQ-ZAK-005c HAVE LEFT THIS TEST. Both are refusals the
	// spec marks safety-relevant, and a fixme'd body cannot observe either.
	// They are real tests in the block below, against a seeded case. What is
	// left here is genuinely unobservable in this suite: REQ-ZAK-004c needs the
	// per-column sort and filter controls with their URL state, and REQ-ZAK-005b
	// needs per-file progress read mid-upload.
	test('dossier sort and per-file progress are not observable here, blocked by #764', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#764: REQ-ZAK-004c (per-column sort and filter with URL state) and REQ-ZAK-005b (per-file progress read mid-upload) have no assertion in this suite. The seeded-case fixture they were said to be blocked on now exists, and is used in the block below; what is still missing is the sort/filter surface and any way to observe progress before the upload resolves.',
		)
		const response = await page
			.goto('/index.php/apps/dossiq/cases')
			.catch(() => null)
		if (!response) {
			test.skip(true, 'Dossiq dev container not reachable')
			return
		}
		const bodyText = await page
			.locator('body')
			.innerText()
			.catch(() => '')
		expect(bodyText).not.toContain('Internal Server Error')
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-006a-concept-document-version-history-shows-restore
	// REQ-ZAK-006b IS NO LONGER CITED HERE. It is a refusal (restore must be
	// disabled on a `definitief` document) and this body never runs, so the
	// citation was a claim nothing backed. Its live home is
	// `tests/e2e/case-documents.spec.ts`, which seeds a final document and opens
	// its version panel. Re-declaring the anchor on a fixme'd body would only
	// put the scenario back where this audit found it.
	//
	// REQ-ZAK-006a stays quarantined, and the reason is narrower than it was:
	// not "no seeded case" — that fixture exists — but no seeded document with
	// MORE THAN ONE Nextcloud file version. Versions come from successive writes
	// through the Files backend, and nothing here writes a document twice.
	test('version history needs a multi-version document, blocked by #764', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#764: REQ-ZAK-006a needs an informatieobject carrying more than one Nextcloud file version. The seeded-case fixture exists; a second file version does not, because nothing in this suite writes the same document twice through the Files backend.',
		)
		const response = await page
			.goto('/index.php/apps/dossiq/cases')
			.catch(() => null)
		if (!response) {
			test.skip(true, 'Dossiq dev container not reachable')
			return
		}
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
			{ timeout: 10000 },
		)
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-008a-zip-export-includes-manifestcsv-and-type-sub-folders
	// REQ-ZAK-008c HAS LEFT THIS TEST for the block below, where the bulk
	// endpoint is called with a mixed batch and the per-document result list is
	// read back. The multi-document fixture the quarantine named is seeded
	// there. REQ-ZAK-008a stays: a ZIP is a binary artefact whose manifest.csv
	// and per-type sub-folders have to be unpacked to be asserted, which is the
	// Newman collection's job rather than the browser's.
	test('ZIP export contents are not asserted in the browser, blocked by #764', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#764: REQ-ZAK-008a needs the downloaded ZIP unpacked to assert manifest.csv and the per-type sub-folders. That is asserted at the API tier (tests/newman/document-zaakdossier.postman_collection.json) and in ZipManifestBuilderTest; this suite has no assertion for it.',
		)
		const response = await page
			.goto('/index.php/apps/dossiq/cases')
			.catch(() => null)
		if (!response) {
			test.skip(true, 'Dossiq dev container not reachable')
			return
		}
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
			{ timeout: 10000 },
		)
	})

	// The 18 backend-enforced scenarios (REQ-ZAK-001a…c, 002a…d, 003a…d, 007a…b,
	// 008b, 009a…b, 010a…b) used to be listed here as `@e2e` tags on a test whose
	// body ended in an unconditional `test.skip(true, 'Backend-enforced …')`.
	// That test reported *skipped* on every run while still supplying gate-19
	// with a traceability link for all 18 — coverage recorded by a test that
	// never executed. The scenarios are genuinely backend-only, so their
	// traceability now lives where the gate provides for it: a reason-bearing
	// `@e2e exclude` on each scenario in
	// openspec/specs/document-zaakdossier/spec.md. Their behaviour is asserted in
	// PHPUnit + Newman (see the file header for the exact suites).
})

/** A minimal, valid PDF — enough for a real multipart upload. */
const PDF_BYTES = Buffer.from(
	'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
		+ '2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n'
		+ 'trailer<</Root 1 0 R>>\n%%EOF\n',
	'utf8',
)

/**
 * A Windows PE header on a file named like a document.
 *
 * This is the magic-byte half of REQ-ZAK-005c. Renaming an executable to
 * `.pdf` defeats an extension check on its own, so the requirement asks for
 * both, and asserting only the `.exe` case would leave the second half
 * unproven while reading as full coverage.
 */
const DISGUISED_EXE_BYTES = Buffer.concat([
	Buffer.from('MZ', 'ascii'),
	Buffer.from([0x90, 0x00, 0x03, 0x00, 0x00, 0x00, 0x04, 0x00]),
])

let api: APIRequestContext
let token: string
let caseTypeId = ''
/** The case the upload endpoint is probed against. */
let uploadCaseId = ''
/** The case the metadata dialog is driven on. */
let dialogCaseId = ''
/** Two documents on one case, for the mixed bulk transition. */
let bulkCaseId = ''
let draftDocumentId = ''
let archivedDocumentId = ''
let documentTypeId = ''
let documentTypeTitle = ''

/** The dossier upload endpoint for one case. */
function uploadUrl(caseId: string): string {
	return `/index.php/apps/dossiq/api/cases/${caseId}/dossier`
}

/** Headers for a CSRF-protected write against the app's own API. */
function writeHeaders(): Record<string, string> {
	return { requesttoken: token, 'OCS-APIRequest': 'true' }
}

/**
 * Seed one informatieobject and link it to a case.
 *
 * @param onCase The case the document hangs on.
 * @param fields The informatieobject body.
 * @return The created informatieobject id.
 */
async function seedDocument(
	onCase: string,
	fields: Record<string, unknown>,
): Promise<string> {
	const created = await createObject(api, token, 'informatieobject', {
		vertrouwelijkheidaanduiding: 'openbaar',
		status: 'draft',
		taal: 'nld',
		creatiedatum: '2026-05-04',
		informatieobjecttype: documentTypeId,
		...fields,
	})
	const id = objectId(created)
	await createObject(api, token, 'zaakinformatieobject', {
		case: onCase,
		informatieobject: id,
		registrationDate: '2026-05-04T10:02:00+00:00',
		natureRelationshipDisplay: `${RUN_PREFIX} hoort bij`,
	})
	return id
}

/**
 * Every informatieobject currently joined to a case.
 *
 * Read through the join rather than through the dossier UI, because the
 * question these tests ask is whether anything was STORED — which a screen
 * that failed to refresh answers wrongly in both directions.
 *
 * @param onCase The case to count the dossier of.
 * @return The linked informatieobject ids.
 */
async function documentsOnCase(onCase: string): Promise<string[]> {
	const joins = await listObjects(api, 'zaakinformatieobject', {
		_limit: '200',
	})
	return joins
		.filter((row: any) => String(row.case ?? '') === onCase)
		.map((row: any) => String(row.informatieobject ?? ''))
		.filter((id: string) => id !== '')
}

test.describe('document-zaakdossier — the guards that refuse', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		caseTypeId = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Dossier type`,
				identifier: `${RUN_PREFIX.toLowerCase()}-dossier-type`,
				description: 'Seeded by document-zaakdossier.spec.ts.',
				isDraft: false,
			}),
		)

		const documentType = await createObject(api, token, 'informatieobjecttype', {
			title: `${RUN_PREFIX} Aanvraag`,
			informatieobjectcategorie: 'incoming',
		})
		documentTypeId = objectId(documentType)
		documentTypeTitle = String(documentType.title)

		uploadCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} upload guard case`,
				caseType: caseTypeId,
			}),
		)
		dialogCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} metadata dialog case`,
				caseType: caseTypeId,
			}),
		)
		bulkCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} bulk transition case`,
				caseType: caseTypeId,
			}),
		)

		draftDocumentId = await seedDocument(bulkCaseId, {
			title: `${RUN_PREFIX} draft document`,
			fileName: 'draft.pdf',
			status: 'draft',
		})
		archivedDocumentId = await seedDocument(bulkCaseId, {
			title: `${RUN_PREFIX} archived document`,
			fileName: 'archived.pdf',
			status: 'archived',
		})
	})

	test.afterAll(async () => {
		if (api !== undefined) {
			await cleanupRunObjects(api, token, [
				'zaakinformatieobject',
				'informatieobject',
				'informatieobjecttype',
				'case',
				'caseType',
			])
			await api.dispose()
		}
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-005c-file-validation-blocks-executable-uploads
	test('an executable is refused before storage, by extension and by magic bytes alike', async ({
		page,
	}) => {
		// THE CONTROL FIRST. Without a successful upload through the same
		// endpoint with the same metadata, every rejection below would also be
		// explained by a malformed request, and the test would "prove" the
		// guard while proving only that the call was wrong.
		const accepted = await page.request.post(uploadUrl(uploadCaseId), {
			headers: writeHeaders(),
			multipart: {
				metadata: JSON.stringify({
					informatieobjecttype: documentTypeId,
					title: `${RUN_PREFIX} legitimate upload`,
				}),

				files: {
					name: 'aanvraag.pdf',
					mimeType: 'application/pdf',
					buffer: PDF_BYTES,
				},
			},
		})
		expect(
			accepted.status(),
			'a plain PDF must upload, so a rejection below is about the content',
		).toBe(201)

		// 1. The extension half.
		const byExtension = await page.request.post(uploadUrl(uploadCaseId), {
			headers: writeHeaders(),
			multipart: {
				metadata: JSON.stringify({
					informatieobjecttype: documentTypeId,
					title: `${RUN_PREFIX} blocked by extension`,
				}),

				files: {
					name: 'malware.exe',
					mimeType: 'application/octet-stream',
					buffer: Buffer.from('harmless text, blocked on its name'),
				},
			},
		})
		// 422, not 201: the controller answers UNPROCESSABLE when no file of
		// the request was created. A 201 here would mean the executable landed.
		expect(byExtension.status()).toBe(422)
		const extensionBody = await byExtension.json()
		expect(extensionBody.results[0].success).toBe(false)
		// The requirement asks the error to state the filename AND the reason.
		expect(String(extensionBody.results[0].error)).toContain('malware.exe')
		expect(String(extensionBody.results[0].error)).toMatch(/[Ee]xecutable/)

		// 2. The magic-byte half, on a file whose extension is allowed. This is
		// the clause an extension-only check passes while failing the
		// requirement, so it is asserted separately rather than assumed.
		const byMagic = await page.request.post(uploadUrl(uploadCaseId), {
			headers: writeHeaders(),
			multipart: {
				metadata: JSON.stringify({
					informatieobjecttype: documentTypeId,
					title: `${RUN_PREFIX} blocked by magic bytes`,
				}),

				files: {
					name: 'besluit.pdf',
					mimeType: 'application/pdf',
					buffer: DISGUISED_EXE_BYTES,
				},
			},
		})
		expect(
			byMagic.status(),
			'an MZ header under a .pdf name must be refused too, or the '
				+ "requirement's magic-byte clause is unenforced",
		).toBe(422)
		const magicBody = await byMagic.json()
		expect(magicBody.results[0].success).toBe(false)
		expect(String(magicBody.results[0].error)).toContain('besluit.pdf')

		// "MUST be rejected before the file is written to disk": the register is
		// the only witness to that, and it must hold exactly the control upload.
		const stored = await documentsOnCase(uploadCaseId)
		expect(stored).toHaveLength(1)
		const survivor = await showObject(api, 'informatieobject', stored[0])
		expect(String(survivor.fileName)).toBe('aanvraag.pdf')
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-005a-drag-drop-triggers-metadata-dialog-before-upload
	test('the metadata dialog lists every selected file and refuses to upload while required fields are empty', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${dialogCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		const panel = await openCasePanel(page, 'documents')

		// The drop zone and the upload button share `openMetadataDialog()`, so
		// driving the hidden file input exercises the same handler a real drop
		// does — and unlike a synthetic DataTransfer it works everywhere.
		await panel.locator('input[type="file"]').setInputFiles([
			{ name: 'aanvraag.pdf', mimeType: 'application/pdf', buffer: PDF_BYTES },
			{ name: 'bijlage.pdf', mimeType: 'application/pdf', buffer: PDF_BYTES },
		])

		const dialog = page.locator('.dossier-metadata-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		// "showing both filenames" — asserted, not assumed.
		await expect(dialog).toContainText('aanvraag.pdf')
		await expect(dialog).toContainText('bijlage.pdf')

		const upload = dialog.getByRole('button', { name: /^(Upload|Uploaden)$/ })
		// PRESENT, THEN DISABLED. Asserting only `toBeDisabled()` on a locator
		// that matches nothing is the shape this audit kept finding: a dialog
		// with no Upload button at all would satisfy it.
		await expect(upload).toHaveCount(1)
		await expect(
			upload,
			'the dialog must refuse to upload while informatieobjecttype and '
				+ 'confidentiality are unset',
		).toBeDisabled()

		// And it must not act on being pressed anyway. `force` skips the
		// actionability wait, so this genuinely delivers the click rather than
		// timing out on a disabled control — which means a build that enabled
		// the button early would upload here and redden the two assertions after.
		await upload.click({ force: true })
		await expect(
			dialog,
			'the dialog MUST NOT close until the required fields are filled',
		).toBeVisible()
		expect(
			await documentsOnCase(dialogCaseId),
			'nothing may be stored while the dialog is still incomplete',
		).toHaveLength(0)

		// Filling the required fields releases it — which is what tells a
		// working refusal apart from a dialog that is simply broken.
		await dialog.getByRole('combobox').first().click()
		await page.getByRole('option').filter({ hasText: documentTypeTitle }).click()
		await expect(upload).toBeEnabled({ timeout: 15_000 })
		await upload.click()
		await expect(dialog).toBeHidden({ timeout: 60_000 })

		// "a single informatieobjecttype selection MUST apply to both files".
		await expect(async () => {
			const ids = await documentsOnCase(dialogCaseId)
			expect(ids).toHaveLength(2)
			for (const id of ids) {
				const stored = await showObject(api, 'informatieobject', id)
				expect(String(stored.informatieobjecttype)).toBe(documentTypeId)
			}
		}).toPass({ timeout: 60_000 })
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-008c-bulk-status-transition-returns-per-document-result
	test('a bulk status transition reports success and failure per document, and applies only the legal half', async ({
		page,
	}) => {
		// A MIXED batch is the whole point of the requirement. `draft -> final`
		// is legal and `archived -> final` is not, so one call must come back
		// carrying both verdicts; a batch of five identical documents would
		// report five identical results and prove nothing about per-ID
		// reporting.
		const res = await page.request.post(
			'/index.php/apps/dossiq/api/informatieobjecten/bulk/status',
			{
				headers: { ...writeHeaders(), 'Content-Type': 'application/json' },
				data: {
					ids: [draftDocumentId, archivedDocumentId],
					status: 'final',
				},
			},
		)
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(body.results).toHaveLength(2)

		const byId = new Map<string, any>(
			body.results.map((row: any) => [String(row.id), row]),
		)
		expect(byId.get(draftDocumentId)?.success).toBe(true)
		expect(
			byId.get(archivedDocumentId)?.success,
			'a document that cannot make the transition must be reported as a '
				+ 'failure, not swallowed by the batch',
		).toBe(false)
		// "with reasons" — the failing entry must say why.
		expect(String(byId.get(archivedDocumentId)?.error)).toMatch(
			/archived.*final|transition/i,
		)

		// The stored rows, because a per-ID result list that does not match what
		// was written is the more dangerous of the two failures.
		await expect(async () => {
			const promoted = await showObject(
				api,
				'informatieobject',
				draftDocumentId,
			)
			expect(String(promoted.status)).toBe('final')
		}).toPass({ timeout: 30_000 })
		const untouched = await showObject(
			api,
			'informatieobject',
			archivedDocumentId,
		)
		expect(
			String(untouched.status),
			'the refused document must be left exactly as it was',
		).toBe('archived')
	})
})
