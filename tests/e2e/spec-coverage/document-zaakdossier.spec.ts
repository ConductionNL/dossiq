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
import { inflateRawSync } from 'node:zlib'
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

	// 🔴 THE TWO CITATIONS THAT WERE HERE ARE GONE, and taking them down is the
	// repair rather than a retreat from it. Both sat on the `test.fixme(true,
	// …)` below, so gate-19 credited neither and a reader met two anchors on a
	// body that has never executed. The scenarios now carry reason-bearing
	// `@e2e exclude` entries in the spec, where the next person to implement
	// the surface will actually meet them.
	//
	// Each reason was RE-MEASURED rather than inherited, and one was wrong.
	// This comment used to say REQ-ZAK-004c "needs the per-column sort and
	// filter controls", implying the surface is absent. It is not:
	// `DossierTab.vue` ships a `Sort by` NcSelect bound to `sortKey` and
	// `sortDirection`. What is genuinely absent is the scenario's LAST clause,
	// the filter and sort state being reflected in the URL: the component has
	// no `$route`, `query` or `router.replace` anywhere in it. A citation that
	// blames a missing control sends the next reader to build one that exists.
	//
	// REQ-ZAK-005b holds up: `uploadProgress` is bound, so the indicator is
	// real, but the scenario asks for it to be READ mid-upload, and nothing in
	// this suite can observe a value between the request starting and its
	// promise resolving.
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

	// 🔴 THE REQ-ZAK-006a CITATION IS GONE TOO, for the same reason: it sat on
	// a body that never runs, so it credited nothing and read as cover. The
	// scenario now carries an `@e2e exclude` naming what is left to do, which
	// is smaller and more specific than "#764".
	//
	// REQ-ZAK-006b IS NO LONGER CITED HERE. It is a refusal (restore must be
	// disabled on a `definitief` document) and this body never runs, so the
	// citation was a claim nothing backed. Its live home is
	// `tests/e2e/case-documents.spec.ts`, which seeds a final document and opens
	// its version panel. Re-declaring the anchor on a fixme'd body would only
	// put the scenario back where this audit found it.
	//
	// REQ-ZAK-006a stays quarantined, but the reason has MOVED and saying so is
	// the point: the missing piece is no longer missing. It used to be that
	// nothing in this suite wrote a document twice, so no informatieobject
	// carried more than one Nextcloud file version.
	// `case-documents.spec.ts#seedVersionedDocument` now does exactly that, and
	// its docblock carries the storage path and the three calls it takes.
	//
	// What is still absent is a fixture HERE. This file seeds no case and no
	// document at all; it navigates and asserts the page does not 500. Lifting
	// the fixme means giving this body a draft document with two versions and
	// then mutation-checking that the ENABLED restore it asserts can actually
	// go red. Nobody has done that, so the fixme stays rather than becoming a
	// second citation nothing backs.
	test('version history needs a multi-version document, blocked by #764', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#764: REQ-ZAK-006a needs a draft informatieobject with two Nextcloud file versions, seeded in THIS file. The recipe exists (case-documents.spec.ts#seedVersionedDocument); the fixture here does not, and the enabled-restore assertion has never been mutation-checked.',
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

	// 🔴 REQ-ZAK-008a HAS LEFT THIS TEST, and the premise it left behind was
	// wrong rather than merely stale. It said a ZIP "has to be unpacked to be
	// asserted, which is the Newman collection's job rather than the
	// browser's". Unpacking it is the job of whoever asserts the requirement,
	// and this suite can: Node ships `zlib`, the scenario names its own
	// endpoint, and `readZipEntries` above reads a central directory in forty
	// lines. The requirement is proven in the block below, mutation checked
	// both ways.
	//
	// What stays here is nothing. This body asserts that `/cases` does not say
	// "Internal Server Error", which is the smoke test at the top of the file,
	// so the test is kept only because removing a fixme'd body and its issue
	// reference is a separate decision from the citation work.
	test('ZIP export contents are not asserted in the browser, blocked by #764', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#764 NO LONGER BLOCKS REQ-ZAK-008a: it is asserted in "the dossier ZIP carries a manifest row per document and one folder per type" below, against the real endpoint. This body is a duplicate of the smoke test at the top of the file and is a candidate for deletion.',
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

/**
 * Read a ZIP's entries without a dependency.
 *
 * The CENTRAL DIRECTORY is parsed, not the local file headers, and that is the
 * difference between a reader that works and one that works until it does not:
 * a local header may carry zeroed sizes with the real ones in a trailing data
 * descriptor, while the central directory always has them. `ZipArchive`
 * deflates `addFromString` content, so stored (method 0) and deflated
 * (method 8) are both handled and anything else is reported rather than
 * silently skipped.
 *
 * @param  zip The archive bytes.
 * @return Each entry's name and its decompressed content.
 */
function readZipEntries(zip: Buffer): Array<{ name: string; content: Buffer }> {
	// End of central directory: the last `PK\x05\x06`, scanned backwards
	// because the comment field that follows it is variable length.
	let eocd = -1
	for (let at = zip.length - 22; at >= 0; at--) {
		if (zip.readUInt32LE(at) === 0x06054b50) {
			eocd = at
			break
		}
	}
	if (eocd < 0) throw new Error('not a ZIP: no end-of-central-directory record')

	const count = zip.readUInt16LE(eocd + 10)
	let at = zip.readUInt32LE(eocd + 16)
	const entries: Array<{ name: string; content: Buffer }> = []

	for (let index = 0; index < count; index++) {
		if (zip.readUInt32LE(at) !== 0x02014b50) {
			throw new Error(`central directory entry ${index} has a bad signature`)
		}
		const method = zip.readUInt16LE(at + 10)
		const compressedSize = zip.readUInt32LE(at + 20)
		const nameLength = zip.readUInt16LE(at + 28)
		const extraLength = zip.readUInt16LE(at + 30)
		const commentLength = zip.readUInt16LE(at + 32)
		const localAt = zip.readUInt32LE(at + 42)
		const name = zip.subarray(at + 46, at + 46 + nameLength).toString('utf8')

		// The local header's own name/extra lengths decide where the bytes
		// start; the central directory's extra field is a different length.
		const localNameLength = zip.readUInt16LE(localAt + 26)
		const localExtraLength = zip.readUInt16LE(localAt + 28)
		const from = localAt + 30 + localNameLength + localExtraLength
		const raw = zip.subarray(from, from + compressedSize)

		let content: Buffer
		if (method === 0) {
			content = Buffer.from(raw)
		} else if (method === 8) {
			content = inflateRawSync(raw)
		} else {
			throw new Error(`entry ${name} uses unsupported compression ${method}`)
		}
		entries.push({ name, content })

		at += 46 + nameLength + extraLength + commentLength
	}
	return entries
}

let api: APIRequestContext
let token: string
let caseTypeId = ''
/** The case the upload endpoint is probed against. */
let uploadCaseId = ''
/** The case the metadata dialog is driven on. */
let dialogCaseId = ''
/** Two documents for the mixed bulk transition. */
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
 * Seed one informatieobject.
 *
 * NOT JOINED TO A CASE, deliberately. The bulk endpoint takes document ids and
 * guards each one by the document's own clearance, so the join adds nothing
 * the per-document result depends on. It does add a failure that is not ours:
 * on an instance that also runs zaakafhandelapp, that app listens for
 * `zaakinformatieobject` creation in this register and throws a TypeError from
 * `ZGWLogicService::createOio()`, turning every seed into an HTTP 500.
 * Measured on the shared dev instance 2026-09-11.
 *
 * @param fields The informatieobject body.
 * @return The created informatieobject id.
 */
async function seedDocument(fields: Record<string, unknown>): Promise<string> {
	const created = await createObject(api, token, 'informatieobject', {
		vertrouwelijkheidaanduiding: 'openbaar',
		status: 'draft',
		taal: 'nld',
		creatiedatum: '2026-05-04',
		informatieobjecttype: documentTypeId,
		...fields,
	})
	return objectId(created)
}

test.describe('document-zaakdossier — the guards that refuse', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// A describe-level `setTimeout` governs TESTS, not HOOKS: a hook keeps
		// the config's 30s until it is widened from inside itself. Seeding a
		// dozen objects on a loaded instance does not fit in 30s, and the
		// failure then reads `"beforeAll" hook timeout` against whichever test
		// ran first, which points at the wrong thing entirely.
		test.setTimeout(300_000)
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

		// `description` carries the display name on this schema, and the
		// catalogue picker labels its options with it. There is no `title`
		// field here, and a missing `description` is a 400 at seed time.
		documentTypeTitle = `${RUN_PREFIX} Aanvraag`
		const documentType = await createObject(api, token, 'informatieobjecttype', {
			description: documentTypeTitle,
			informatieobjectcategorie: 'incoming',
			vertrouwelijkheidaanduiding: 'openbaar',
		})
		documentTypeId = objectId(documentType)

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
		draftDocumentId = await seedDocument({
			title: `${RUN_PREFIX} draft document`,
			fileName: 'draft.pdf',
			status: 'draft',
		})
		archivedDocumentId = await seedDocument({
			title: `${RUN_PREFIX} archived document`,
			fileName: 'archived.pdf',
			status: 'archived',
		})
	})

	test.afterAll(async () => {
		if (api === undefined) return
		// The document rows only. The cases and their case type are archival
		// or referenced by archival rows; they carry the family prefix, and
		// global-setup's residue sweep takes them before the next run, the same
		// split `case-documents.spec.ts` makes. Purging them here overran the
		// helper's 120s teardown budget on a loaded instance.
		await cleanupRunObjects(api, token, [
			'informatieobject',
			'informatieobjecttype',
		])
		await api.dispose()
	})

	// 🔴 THIS CITATION MOVED OFF A TEST THAT NEVER RAN. It used to sit on
	// `ZIP export contents are not asserted in the browser, blocked by #764`,
	// an unconditional `test.fixme(true, …)`. A citation on a body that cannot
	// execute is the purest form of the thing this programme removes: gate-19
	// reports it as uncredited, and a reader sees an anchor and assumes cover.
	//
	// The premise was also wrong, which is why it is a test and not an
	// exclusion. "Not observable in the browser" is true and beside the point:
	// the scenario names its own endpoint, `POST /api/cases/{caseId}/dossier/
	// zip`, and both of its content clauses are about the ARCHIVE. An API call
	// and a ZIP reader settle them exactly, with no UI involved.
	//
	// ✅ BOTH CLAUSES MUTATION CHECKED 2026-09-12, against a live instance, by
	// editing the exporter and flushing opcache with `apachectl -k graceful`
	// rather than waiting out `opcache.revalidate_freq=60`. A mutation that is
	// on disk but not yet served reports the unbroken code as green, which is
	// the same lie as a test that never ran.
	//
	//   buildEntryName() prefixing every entry with one fixed folder
	//     -> "three documents across two types must land in two folders, and
	//        the archive held ["MUTATION-one-folder-for-everything"]",
	//        expected 2 received 1
	//   buildManifest() writing the header and no rows
	//     -> "the manifest must carry a header and one row per document",
	//        expected 4 received 1
	//
	// Restored, `php -l` clean, green again.
	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-008a-zip-export-includes-manifestcsv-and-type-sub-folders
	test('the dossier ZIP carries a manifest row per document and one folder per type', async () => {
		test.setTimeout(180_000)

		// A SECOND type, because "sub-folders per informatieobjecttype" is not
		// observable with one: a single folder is equally consistent with a
		// layout that ignores the type entirely and names one folder for every
		// archive.
		const secondTypeId = objectId(
			await createObject(api, token, 'informatieobjecttype', {
				description: `${RUN_PREFIX} Besluit`,
				informatieobjectcategorie: 'outgoing',
				vertrouwelijkheidaanduiding: 'openbaar',
			}),
		)

		const zipCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} zip export case`,
				caseType: caseTypeId,
			}),
		)

		// UPLOADED, not `seedDocument`'d. The exporter reads bytes out of each
		// informatieobject's file; a row with no file behind it contributes an
		// entry with nothing in it, so a fixture that only writes objects would
		// "pass" on an archive of empty files. The upload endpoint writes the
		// file and makes the case join, and `collectDocuments` finds documents
		// BY CASE, so the join is required here rather than avoided as it is
		// for the bulk-transition fixtures above.
		const uploaded: Array<{ id: string; title: string; type: string }> = []
		for (const [index, typeId] of [
			documentTypeId,
			documentTypeId,
			secondTypeId,
		].entries()) {
			const title = `${RUN_PREFIX} zip document ${index + 1}`
			const res = await api.post(uploadUrl(zipCaseId), {
				headers: writeHeaders(),
				multipart: {
					files: {
						name: `zip-${index + 1}.pdf`,
						mimeType: 'application/pdf',
						buffer: PDF_BYTES,
					},
					metadata: JSON.stringify({
						title,
						informatieobjecttype: typeId,
						direction: 'incoming',
					}),
				},
			})
			expect(
				res.status(),
				`upload ${index + 1} -> ${res.status()} ${await res.text()}`,
			).toBe(201)
			const result = (await res.json()).results[0]
			expect(result?.success, JSON.stringify(result)).toBe(true)
			uploaded.push({
				id: String(result.informatieobject.id),
				title,
				type: typeId,
			})
		}

		// The endpoint answers a binary body, so it is fetched through a
		// context that does not try to read it as text.
		const zipResponse = await api.post(
			`/index.php/apps/dossiq/api/cases/${zipCaseId}/dossier/zip`,
			{
				headers: { ...writeHeaders(), 'Content-Type': 'application/json' },
				data: { ids: uploaded.map((row) => row.id) },
			},
		)
		expect(
			zipResponse.status(),
			`dossier zip -> ${zipResponse.status()} ${await zipResponse.text()}`,
		).toBe(200)

		const entries = readZipEntries(Buffer.from(await zipResponse.body()))
		const names = entries.map((entry) => entry.name)

		// 1. THE MANIFEST, at the root and populated. "All rows" is asserted as
		// one row per document PLUS the header, not as "a manifest exists": an
		// exporter that writes the header and no rows is exactly the failure
		// the scenario's "with all 8 rows populated" guards against.
		const manifest = entries.find((entry) => entry.name === 'manifest.csv')
		expect(
			manifest,
			`the archive must carry manifest.csv at its root, and it held ${JSON.stringify(names)}`,
		).toBeTruthy()
		const manifestText = String(manifest?.content.toString('utf8'))
		const manifestRows = manifestText
			.split('\n')
			.map((line) => line.trim())
			.filter((line) => line !== '')
		expect(
			manifestRows.length,
			`the manifest must carry a header and one row per document:\n${manifestText}`,
		).toBe(uploaded.length + 1)
		expect(
			manifestRows[0],
			'the manifest header must name the columns the exporter declares',
		).toContain('fileName')
		for (const row of uploaded) {
			expect(
				manifestText,
				`the manifest must carry a row for ${row.title}`,
			).toContain(row.title)
		}

		// 2. ONE FOLDER PER TYPE. The document entries are prefixed with the
		// sanitised informatieobjecttype, so two types must produce two
		// distinct prefixes across three documents. Asserted as the SET of
		// prefixes rather than a count of entries, because three documents in
		// three folders and three documents in one folder both have three
		// entries and only one of them is the requirement.
		const folders = new Set(
			names
				.filter((name) => name !== 'manifest.csv')
				.map((name) => name.split('/')[0]),
		)
		expect(
			names.filter((name) => name !== 'manifest.csv').length,
			`every uploaded document must appear in the archive: ${JSON.stringify(names)}`,
		).toBe(uploaded.length)
		expect(
			folders.size,
			`three documents across two types must land in two folders, and the archive held ${JSON.stringify([...folders])}`,
		).toBe(2)
		for (const name of names) {
			if (name === 'manifest.csv') continue
			expect(
				name,
				'a document entry must sit inside its type folder, not at the root',
			).toContain('/')
		}

		// 3. AND THE BYTES ARE REALLY THERE. An archive of correctly-named
		// empty entries satisfies every assertion above.
		for (const entry of entries) {
			if (entry.name === 'manifest.csv') continue
			expect(
				entry.content.length,
				`${entry.name} must carry the uploaded file's bytes`,
			).toBe(PDF_BYTES.length)
		}
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-005c-file-validation-blocks-executable-uploads
	test('an executable is refused before storage, by extension and by magic bytes alike', async ({
		page,
	}) => {
		/**
		 * Post one file to the dossier endpoint and return its per-file result.
		 *
		 * @param name   The filename as the browser would send it.
		 * @param buffer The bytes.
		 * @return The HTTP status and the single per-file result entry.
		 */
		const upload = async (name: string, buffer: Buffer) => {
			const res = await page.request.post(uploadUrl(uploadCaseId), {
				headers: writeHeaders(),
				multipart: {
					metadata: JSON.stringify({
						informatieobjecttype: documentTypeId,
						title: `${RUN_PREFIX} ${name}`,
					}),

					files: { name, mimeType: 'application/octet-stream', buffer },
				},
			})
			const body = await res.json()
			return { status: res.status(), result: body.results[0] }
		}

		// THE CONTROL FIRST, and it is about the MESSAGE rather than the status.
		// Without it every rejection below would also be explained by a malformed
		// request, and the test would "prove" the guard while proving only that
		// the call was wrong. It deliberately does not require a 201: whether the
		// downstream ZGW listener accepts the document varies by which apps an
		// instance has installed, and that is not what this scenario is about.
		// What must be true everywhere is that a plain PDF is never refused AS AN
		// EXECUTABLE.
		const control = await upload('aanvraag.pdf', PDF_BYTES)
		expect(
			String(control.result.error ?? ''),
			'a plain PDF must not be screened out as an executable, or the two '
				+ 'refusals below say nothing about the content',
		).not.toMatch(/[Ee]xecutable/)

		// 1. The extension half.
		const byExtension = await upload(
			'malware.exe',
			Buffer.from('harmless text, blocked on its name'),
		)
		// 422, not 201: the controller answers UNPROCESSABLE when no file of the
		// request was created. A 201 here would mean the executable landed.
		expect(byExtension.status).toBe(422)
		expect(byExtension.result.success).toBe(false)
		// The requirement asks the error to state the filename AND the reason.
		expect(String(byExtension.result.error)).toContain('malware.exe')
		expect(String(byExtension.result.error)).toMatch(/[Ee]xecutable/)

		// 2. The magic-byte half, on a file whose extension is allowed. This is
		// the clause an extension-only check passes while failing the
		// requirement, so it is asserted separately rather than assumed.
		const byMagic = await upload('besluit.pdf', DISGUISED_EXE_BYTES)
		expect(
			byMagic.status,
			'an MZ header under a .pdf name must be refused too, or the '
				+ "requirement's magic-byte clause is unenforced",
		).toBe(422)
		expect(byMagic.result.success).toBe(false)
		expect(String(byMagic.result.error)).toContain('besluit.pdf')
		expect(String(byMagic.result.error)).toMatch(/[Ee]xecutable/)

		// "MUST be rejected before the file is written to disk": the register is
		// the only witness to that. Read the informatieobject schema itself, not
		// the dossier join, because a document written without its join would
		// slip past a join-based count while still being on disk.
		for (const name of ['malware.exe', 'besluit.pdf']) {
			const rows = await listObjects(api, 'informatieobject', {
				fileName: name,
				_limit: '500',
			})
			const ours = rows.filter((row: any) =>
				String(row.title ?? '').startsWith(RUN_PREFIX),
			)
			expect(ours, `${name} must never reach the register`).toHaveLength(0)
		}
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-005a-drag-drop-triggers-metadata-dialog-before-upload
	test('the metadata dialog lists every selected file and issues no upload while required fields are empty', async ({
		page,
	}) => {
		// WATCH THE WIRE, not the register. "MUST NOT close or upload" is a claim
		// about whether a request is made, and `DossierTab.performUpload()` keeps
		// the dialog open and shows "Upload failed" when a POST is refused
		// downstream. Counting stored rows would therefore confuse "the dialog
		// refused to send" with "it sent and storage said no", which are opposite
		// outcomes for this requirement.
		let uploads = 0
		page.on('request', (req) => {
			if (
				req.method() === 'POST'
				&& req.url().includes(`/api/cases/${dialogCaseId}/dossier`)
			) {
				uploads++
			}
		})

		// What each request CARRIED is read from the form as it is built, not
		// off the wire. Chromium does not expose the body of a multipart request
		// that contains a File, so `request.postData()` answers an empty string
		// for exactly the requests this test is about. Recording each
		// `FormData.append` observes the app's own payload without changing it.
		await page.addInitScript(() => {
			const record: Array<{ name: string; value: string }> = []
			;(window as any).__dossierForm = record
			const append = FormData.prototype.append
			FormData.prototype.append = function (
				this: FormData,
				name: string,
				value: any,
				...rest: any[]
			) {
				record.push({
					name,
					value:
						typeof value === 'string'
							? value
							: String(value?.name ?? ''),
				})
				return (append as any).call(this, name, value, ...rest)
			}
		})
		const appended = () =>
			page.evaluate(
				() =>
					(window as any).__dossierForm as Array<{
						name: string
						value: string
					}>,
			)

		await page.goto(`/apps/${REGISTER}/cases/${dialogCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		const panel = await openCasePanel(page, 'documents')

		// The drop zone and the upload button share `openMetadataDialog()`, so
		// driving the hidden file input exercises the same handler a real drop
		// does, and unlike a synthetic DataTransfer it works everywhere.
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
		// that matches nothing is the shape this audit kept finding: a dialog with
		// no Upload button at all would satisfy it.
		await expect(upload).toHaveCount(1)
		await expect(
			upload,
			'the dialog must refuse to upload while the document type and the '
				+ 'confidentiality are unset',
		).toBeDisabled()

		// And it must not act on being pressed anyway. `force` skips the
		// actionability wait, so this genuinely delivers the click rather than
		// timing out on a disabled control, which means a build that enabled the
		// button early would send here and redden the two assertions after it.
		await upload.click({ force: true })
		await expect(
			dialog,
			'the dialog MUST NOT close until the required fields are filled',
		).toBeVisible()
		expect(
			uploads,
			'no upload may be sent while the dialog is still incomplete',
		).toBe(0)

		// Filling the required fields releases it, which is what tells a working
		// refusal apart from a dialog that is simply broken. Choosing the type
		// also defaults the confidentiality from it, so this one pick satisfies
		// both required fields.
		await dialog.getByRole('combobox').first().click()
		await page.getByRole('option').filter({ hasText: documentTypeTitle }).click()
		await expect(upload).toBeEnabled({ timeout: 15_000 })
		await upload.click()

		// "a single informatieobjecttype selection MUST apply to both files".
		// `performUpload` posts once per file with the shared metadata, so both
		// requests must carry the one type that was picked.
		await expect.poll(() => uploads, { timeout: 60_000 }).toBe(2)
		const form = await appended()
		const metadata = form.filter((entry) => entry.name === 'metadata')
		expect(metadata).toHaveLength(2)
		for (const entry of metadata) {
			expect(JSON.parse(entry.value).informatieobjecttype).toBe(documentTypeId)
		}
		expect(
			form
				.filter((entry) => entry.name === 'files')
				.map((entry) => entry.value),
		).toEqual(['aanvraag.pdf', 'bijlage.pdf'])
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-008c-bulk-status-transition-returns-per-document-result
	test('a bulk status transition answers per document, and the register agrees with every verdict', async ({
		page,
	}) => {
		// A MIXED batch is the whole point of the requirement. `draft -> final` is
		// a legal move and `archived -> final` is not, so one call must come back
		// carrying two different verdicts. A batch of five identical documents
		// would report five identical results and prove nothing about per-ID
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
		expect(byId.has(draftDocumentId)).toBe(true)
		expect(byId.has(archivedDocumentId)).toBe(true)

		// The refusal, and it must be the ARCHIVED document's alone. The
		// forward-only guard is what this half of the scenario is about, and a
		// verdict broadcast over the whole batch would satisfy a weaker assertion
		// while proving nothing per document.
		expect(
			byId.get(archivedDocumentId).success,
			'a document that cannot make the transition must be reported as a '
				+ 'failure, not swallowed by the batch',
		).toBe(false)
		expect(String(byId.get(archivedDocumentId).error)).toMatch(
			/Invalid status transition from archived to final/i,
		)
		expect(
			String(byId.get(draftDocumentId).error ?? ''),
			"the legal move must not be refused with the illegal one's reason",
		).not.toMatch(/Invalid status transition/i)

		// THE REGISTER MUST AGREE WITH THE LIST, entry by entry. This is the
		// assertion that catches a result list which reports one thing and writes
		// another, in either direction, and it holds whatever the per-document
		// outcome turns out to be.
		//
		// 🔴 MEASURED 2026-09-11, and left standing rather than asserted around:
		// the draft entry comes back a FAILURE reading "The required properties
		// (title, fileName, vertrouwelijkheidaanduiding, informatieobjecttype)
		// are missing". `InformatieobjectStatusLifecycle::transition()` saves
		// `['status' => …]` alone against an existing uuid, and OpenRegister's
		// `saveObject()` replaces rather than merges, so the write is validated as
		// a whole object and no status transition can currently complete. The
		// per-document reporting this scenario is about works; the lifecycle under
		// it does not. That defect belongs in its own change, and this assertion
		// is written so it needs no edit when the fix lands.
		for (const [id, row] of byId) {
			const stored = await showObject(api, 'informatieobject', id)
			const expected =
				row.success === true
					? 'final'
					: id === draftDocumentId
						? 'draft'
						: 'archived'
			expect(
				String(stored.status),
				`the stored status of ${id} must match the verdict the bulk call reported for it`,
			).toBe(expected)
		}
	})
})
