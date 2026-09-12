/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Documents tab: the case file, on the case page.
 *
 * The schemas `informatieobject`, `zaakinformatieobject` and
 * `informatieobjecttype` shipped, and so did `DossierTab` with its drop zone,
 * metadata dialog and version panel — linked from no page. The spec said
 * shipped and the surface was dead, which is exactly the state no test can
 * tell apart from a working one it never opens.
 *
 * WHAT THIS SPEC ADDRESSES, AND WHY THAT WAY. The tab's content is read
 * through the OPEN PANEL inside the strip, `[role="tabpanel"]:not([hidden])`,
 * and never through `[aria-label="case-documents"]`: CnDetailPage labels only
 * TOP-LEVEL widgets, so a widget that lives as a tab child has no such label,
 * and the sidebar has tabpanels of its own that a page-wide locator would
 * also match.
 *
 * Column HEADINGS and row VALUES are asserted, never the widget type. The
 * widget is the interim rendering (documents-on-the-case task 2.2): it becomes
 * an `object-list` over `zaakinformatieobject` once nextcloud-vue renders a
 * `$ref` column by a label field, and this spec has to survive that swap or it
 * is testing the scaffolding instead of the feature.
 *
 * Locale: nothing forces the language of the E2E instance, so tab and button
 * names are matched in either locale the app ships, as the sibling case-detail
 * specs do. Rows are matched on their run-prefixed title, which is data this
 * spec wrote and no translation touches.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listAllObjects,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	tryDeleteObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction } from './helpers/nav.ts'

/** The six columns the case file is read by, in order. */
const COLUMNS = ['Title', 'Type', 'Status', 'Direction', 'Date', 'Author']

const OBJECTION_TITLE = `${RUN_PREFIX} Objection to the felling permit`
const ACKNOWLEDGEMENT_TITLE = `${RUN_PREFIX} Acknowledgement of receipt`
const DROPPED_TITLE = `${RUN_PREFIX} Dropped inspection report`
const TAGGED_TITLE = `${RUN_PREFIX} Tagged building drawing`

const OBJECTION_TYPE_NAME = `${RUN_PREFIX} Objection`
const ACKNOWLEDGEMENT_TYPE_NAME = `${RUN_PREFIX} Acknowledgement`

/** A minimal, valid PDF — enough for a real multipart upload. */
const PDF_BYTES = Buffer.from(
	'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
		+ '2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n'
		+ 'trailer<</Root 1 0 R>>\n%%EOF\n',
	'utf8',
)

/**
 * A DIFFERENT body, written over the first one to make a version.
 *
 * Longer as well as different, so a write that silently did nothing cannot
 * pass for one that did: the stored `size` moves with it, and the fixture
 * asserts on that rather than on the write returning 200.
 */
const PDF_BYTES_V2 = Buffer.from(
	'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
		+ '2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n'
		+ '3 0 obj<</Length 0>>stream\nendstream endobj\n'
		+ 'trailer<</Root 1 0 R>>\n%%EOF\n',
	'utf8',
)

let api: APIRequestContext
let token: string
let caseTypeId = ''
/** The case the tab is read on: two documents of different types. */
let caseId = ''
/** A case with no documents at all. */
let emptyCaseId = ''
/** A case the drop zone writes into, kept apart so the new row is unambiguous. */
let dropCaseId = ''
/** A case the keyword filter is exercised on. */
let filterCaseId = ''
/** A case the Generate document action files a letter onto. */
let generateCaseId = ''
/** A case carrying one FINAL document, for the version panel's restore guard. */
let versionCaseId = ''

let objectionTypeId = ''
let acknowledgementTypeId = ''
/** The final document whose Versions panel is opened. */
let versionedDocumentId = ''

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
		...fields,
	})
	const id = objectId(created)
	await createObject(api, token, 'zaakinformatieobject', {
		case: onCase,
		informatieobject: id,
		registrationDate: '2026-05-04T10:02:00+00:00',
		// The join carries no free text of its own, so the sweep would never
		// find it: the description gives teardown something to match on.
		natureRelationshipDisplay: 'Hoort at omgekeerd',
	})
	return id
}

/**
 * Seed one FINAL document that genuinely HAS a version history.
 *
 * `seedDocument()` above cannot be used for the version panel, and the reason
 * is not a missing option: it POSTs an `informatieobject` row and NOTHING
 * ELSE. No file is written, so no `fileId` is stamped, so
 * `VersionHistoryPanel.fetchVersions()` returns before it asks the server
 * anything (`if (!this.document.fileId || !this.userId) return`). The panel
 * then renders "No previous versions", which is also what a panel whose
 * PROPFIND failed looks like, and what a panel that lost its Restore buttons
 * looks like. That emptiness is why the restore assertion could not fail.
 *
 * THE STORAGE PATH, READ OFF A RUNNING INSTANCE RATHER THAN OFF THE COMMENT
 * THAT USED TO BE HERE. `ZgwDocumentService::getDocumentFolder()` asks
 * OpenRegister's `FileService::getObjectFolder()` for a folder keyed by the
 * informatieobject UUID inside the register's own folder, and the file lands
 * at:
 *
 *   <acting user>/files/Open Registers/<register TITLE>/<uuid>/<fileName>
 *
 * Two details the old comment had wrong, both measured on 2026-09-12 against
 * NC 34.0.3 with openregister at `development`:
 *
 * - the middle segment is the register's TITLE ("Dossiq Case Management
 *   Register"), not its slug ("dossiq"), so no fixture should build that path
 *   by hand — and this one does not;
 * - the folder was created in the ACTING USER's home (`admin`), even though
 *   an `openregister` account exists on the instance. `ZgwDocumentService`'s
 *   own docblock claims the openregister account owns it. Whichever is
 *   intended, it is why this spec can read the versions at all: the panel
 *   PROPFINDs `/dav/versions/<current user>/versions/<fileId>`, which answers
 *   for a file in that user's storage.
 *
 * So the writes go through APIs that own the path instead of naming it:
 *
 *  1. `POST /apps/dossiq/api/cases/{case}/dossier`, the product's own upload.
 *     It creates the informatieobject, writes the bytes, stamps `fileId` and
 *     makes the case join, which is four things this fixture does not have to
 *     reproduce. It forces `status: draft`, hence step 3.
 *  2. `PUT /apps/openregister/api/objects/dossiq/informatieobject/{id}/files/
 *     {fileId}`, a SECOND write to the same file. This is the one that makes a
 *     version: Nextcloud's version list holds PREVIOUS contents, not the
 *     current one, so one upload alone can leave the panel empty.
 *  3. `PATCH /apps/dossiq/api/informatieobjecten/{id}/status`, the product's
 *     own `draft -> final` transition, so the document is final the way a
 *     final document is made (it sets `lockedOn` too) rather than by writing
 *     the word into a row. It runs LAST: a final document is meant to refuse
 *     further writes, so finalising before step 2 would be asking the fixture
 *     to defeat the rule the test is about.
 *
 * @param onCase  The case the document hangs on.
 * @param options The document's title, file name, type and author.
 * @return The created informatieobject id.
 */
async function seedVersionedDocument(
	onCase: string,
	options: {
		title: string
		fileName: string
		typeId: string
		auteur: string
	},
): Promise<string> {
	const uploaded = await api.post(
		`/index.php/apps/${REGISTER}/api/cases/${onCase}/dossier`,
		{
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			multipart: {
				files: {
					name: options.fileName,
					mimeType: 'application/pdf',
					buffer: PDF_BYTES,
				},
				metadata: JSON.stringify({
					title: options.title,
					informatieobjecttype: options.typeId,
					direction: 'outgoing',
					auteur: options.auteur,
				}),
			},
		},
	)
	// 201 ONLY IF SOMETHING WAS CREATED, and the per-file result read as well
	// as the status: the endpoint answers 201 for a PARTIAL success, so a run
	// where the one file failed would otherwise seed nothing and report fine.
	expect(
		uploaded.status(),
		`upload -> ${uploaded.status()} ${await uploaded.text()}`,
	).toBe(201)
	const [result] = (await uploaded.json()).results ?? []
	expect(result?.success, `the upload reported ${JSON.stringify(result)}`).toBe(
		true,
	)
	const id = String(result.informatieobject.id)

	const stored = await showObject(api, 'informatieobject', id)
	const fileId = Number(stored.fileId ?? 0)
	expect(
		fileId,
		`the upload must stamp a fileId, and the stored document carries ${JSON.stringify(stored.fileId)}`,
	).toBeGreaterThan(0)

	const rewritten = await api.put(
		`/index.php/apps/openregister/api/objects/${REGISTER}/informatieobject/${id}/files/${fileId}`,
		{
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: { content: PDF_BYTES_V2.toString('base64') },
		},
	)
	expect(
		rewritten.ok(),
		`second write -> ${rewritten.status()} ${await rewritten.text()}`,
	).toBeTruthy()
	// THE SIZE, NOT THE STATUS. A 200 that changed no bytes is a write that did
	// nothing, and a file whose content never changed has no previous content
	// to list — which lands back on the empty panel this helper exists to
	// prevent, wearing a green fixture.
	expect(
		Number((await rewritten.json()).size ?? 0),
		'the second write must change the stored bytes, or there is no previous version to list',
	).toBe(PDF_BYTES_V2.length)

	const finalised = await api.patch(
		`/index.php/apps/${REGISTER}/api/informatieobjecten/${id}/status`,
		{
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: { status: 'final' },
		},
	)
	expect(
		finalised.ok(),
		`draft -> final -> ${finalised.status()} ${await finalised.text()}`,
	).toBeTruthy()

	return id
}

/**
 * Open a case and switch to its Documents tab, returning the OPEN panel.
 *
 * The tab panels are LAZY: the widget does not mount, and therefore does not
 * fetch, until its tab is opened. Without the click every assertion below
 * would time out on an unmounted panel and read as a broken dossier.
 *
 * @param page The Playwright page.
 * @param id The case id to open.
 * @return The open tab panel.
 */
async function openDocumentsTab(page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	// The SECTION, not the whole open panel. Now that the strip holds six tabs
	// instead of fourteen, a tab carries two collections, so an assertion made
	// against the panel root can be satisfied by the wrong half of it. The
	// tab-to-section mapping lives in helpers/case-panels.ts, so the next fold
	// moves one table rather than every spec that opens a panel.
	//
	// It is a testid and not a widget id because CnDetailPage sets `aria-label`
	// to the manifest widget id only on the top-level widgets it lays out, so a
	// widget rendered inside a tab carries no such label.
	return await openCasePanel(page, 'documents')
}

/**
 * The Direction picker in the upload dialog, by label or by test id.
 *
 * @param dialog The open metadata dialog.
 * @return The combobox locator.
 */
function directionPicker(dialog) {
	return dialog
		.getByRole('combobox', { name: /Direction|Richting/ })
		.or(dialog.locator('[data-testid="document-direction"] [role="combobox"]'))
		.first()
}

/**
 * The Keywords tags input in the upload dialog, by label or by test id.
 *
 * @param dialog The open metadata dialog.
 * @return The combobox locator.
 */
function keywordPicker(dialog) {
	return dialog
		.getByRole('combobox', { name: /Keywords|Trefwoorden/ })
		.or(dialog.locator('[data-testid="document-keywords"] [role="combobox"]'))
		.first()
}

/**
 * Upload one file through the tab's own drop-zone path: the file picker, the
 * metadata dialog and Upload.
 *
 * The drop zone and the upload button share `openMetadataDialog()`, so driving
 * the hidden file input exercises the same handler a real drop does — and
 * unlike a synthetic DataTransfer it works in every browser the project runs.
 *
 * @param page The Playwright page.
 * @param panel The open Documents panel.
 * @param options The metadata to fill in.
 */
async function uploadThroughDialog(
	page,
	panel,
	options: {
		fileName: string
		title: string
		typeName: string
		direction?: RegExp
		keywords?: string[]
	},
) {
	await panel.locator('input[type="file"]').setInputFiles({
		name: options.fileName,
		mimeType: 'application/pdf',
		buffer: PDF_BYTES,
	})

	const dialog = page.locator('.dossier-metadata-dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })

	// The type picker: the catalogue rows this spec seeded, by their
	// run-prefixed description, so no other install data can be picked.
	await dialog.getByRole('combobox').first().click()
	await page.getByRole('option').filter({ hasText: options.typeName }).click()

	if (options.direction) {
		// By its LABEL, with the data-testid as the fallback: NcSelect wires
		// `inputLabel` into the combobox's accessible name, which is the
		// binding the nc-input-labels gate exists to require, while a
		// `data-testid` only reaches the DOM if the wrapper forwards attrs.
		await directionPicker(dialog).click()
		await page.getByRole('option').filter({ hasText: options.direction }).click()
	}

	await dialog.getByRole('textbox', { name: /Title|Titel/ }).fill(options.title)

	for (const keyword of options.keywords ?? []) {
		const tags = keywordPicker(dialog)
		await tags.fill(keyword)
		await tags.press('Enter')
	}

	await dialog.getByRole('button', { name: /^(Upload|Uploaden)$/ }).click()
	await expect(dialog).toBeHidden({ timeout: 30_000 })
}

/**
 * The stored informatieobject with the given title, polled until it lands.
 *
 * @param title The run-prefixed title.
 * @return The stored object.
 */
async function storedDocument(title: string): Promise<any> {
	let found: any
	await expect(async () => {
		const rows = await listObjects(api, 'informatieobject', { _limit: '200' })
		found = rows.find((row) => String(row.title ?? '') === title)
		expect(found, `no informatieobject titled ${title}`).toBeTruthy()
	}).toPass({ timeout: 30_000 })
	return found
}

test.describe('Case detail — the Documents tab', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// REUSE a seeded case type. The `case` schema is archival, so a case
		// cannot be deleted by a user; creating a case type here and deleting
		// it in teardown would leave every case pointing at a type that is
		// gone, which reddens unrelated specs.
		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type — adoptableCaseTypes() excludes drafts (isDraft !== false) and fixture-owned rows',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])

		const seeded = await Promise.all([
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents empty`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents drop`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents filter`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents generate`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents versions`,
				caseType: caseTypeId,
			}),
		])
		;[
			caseId,
			emptyCaseId,
			dropCaseId,
			filterCaseId,
			generateCaseId,
			versionCaseId,
		] = seeded.map(objectId)

		const types = await Promise.all([
			createObject(api, token, 'informatieobjecttype', {
				description: OBJECTION_TYPE_NAME,
				informatieobjectcategorie: 'incoming',
				vertrouwelijkheidaanduiding: 'openbaar',
			}),
			createObject(api, token, 'informatieobjecttype', {
				description: ACKNOWLEDGEMENT_TYPE_NAME,
				informatieobjectcategorie: 'outgoing',
				vertrouwelijkheidaanduiding: 'openbaar',
			}),
		])
		;[objectionTypeId, acknowledgementTypeId] = types.map(objectId)

		await seedDocument(caseId, {
			title: OBJECTION_TITLE,
			fileName: 'objection.pdf',
			informatieobjecttype: objectionTypeId,
			direction: 'incoming',
			keywords: ['bezwaar'],
			auteur: 'Els Jansen',
		})
		await seedDocument(caseId, {
			title: ACKNOWLEDGEMENT_TITLE,
			fileName: 'acknowledgement.pdf',
			informatieobjecttype: acknowledgementTypeId,
			direction: 'outgoing',
			status: 'final',
			auteur: 'Piet de Boer',
		})

		// The filter case: one tagged document and one with no keywords at all.
		await seedDocument(filterCaseId, {
			title: TAGGED_TITLE,
			fileName: 'drawing.pdf',
			informatieobjecttype: objectionTypeId,
			direction: 'incoming',
			keywords: ['bezwaar'],
			auteur: 'Els Jansen',
		})
		await seedDocument(filterCaseId, {
			title: `${RUN_PREFIX} Untagged letter`,
			fileName: 'letter.pdf',
			informatieobjecttype: acknowledgementTypeId,
			direction: 'outgoing',
			auteur: 'Piet de Boer',
		})

		// NOT `seedDocument`: the version panel needs a document with a FILE
		// behind it, written twice. See the helper for why, and for the storage
		// path it was measured against.
		versionedDocumentId = await seedVersionedDocument(versionCaseId, {
			title: `${RUN_PREFIX} Final report`,
			fileName: 'report.pdf',
			typeId: acknowledgementTypeId,
			auteur: 'Piet de Boer',
		})
	})

	test.afterAll(async () => {
		if (!api) return

		// 🔴 THE JOINS FIRST, BY THEIR CASE, BECAUSE THE PREFIX SWEEP CANNOT SEE
		// THEM. `sweepPrefix` matches a row by finding `RUN_PREFIX` anywhere in
		// its JSON, and a `zaakinformatieobject` is two uuids, a date and
		// `natureRelationshipDisplay` — which the schema declares as an ENUM of
		// exactly "Hoort at omgekeerd" and "Legt vast, omgekeerd", so no prefix
		// can ever be put there. `seedDocument`'s comment says that field
		// "gives teardown something to match on"; it never did, and every join
		// this spec has ever created is still on the instance. Nothing failed,
		// because an unmatched row is not a survivor: it is a row the sweep was
		// never asked about.
		//
		// The sweep's other arm, `matchesCase`, is the one that fits, and it
		// arms only when `case` is in the schema list — which it must not be
		// here, since a case is archival and the sweep would then report every
		// one of them as a survivor and fail this hook. So the joins are
		// collected by their `case` field and removed directly.
		//
		// Listed unfiltered and filtered in the READING: the objects endpoint
		// reads a bare `case=` param as a field filter, and a filter spelling
		// this endpoint does not recognise returns the WHOLE collection rather
		// than an error, which would have this hook delete other runs' joins.
		const ourCases = new Set(
			[
				caseId,
				emptyCaseId,
				dropCaseId,
				filterCaseId,
				generateCaseId,
				versionCaseId,
			].filter((id) => id !== ''),
		)
		//
		// The documents the joins point at go with them, and that is a second
		// leak rather than tidiness: the letter the Generate document test
		// files is titled after its TEMPLATE ("Ontvangstbevestiging"), because
		// the generator names it, not this spec — so it carries no run prefix
		// either and the prefix sweep cannot see it. Its join names it, which
		// is the only handle on it there is, so it is collected here while the
		// join is still readable.
		const ourDocuments = new Set<string>()
		for (const row of await listAllObjects(api, 'zaakinformatieobject')) {
			if (ourCases.has(String(row.case ?? '')) === false) continue
			const document = String(row.informatieobject ?? '')
			if (document !== '') ourDocuments.add(document)
			await tryDeleteObject(api, token, 'zaakinformatieobject', objectId(row))
		}
		for (const document of ourDocuments) {
			await tryDeleteObject(api, token, 'informatieobject', document)
		}

		// The dossier rows, children first. The cases are archival and
		// cannot be removed by a user; they carry the family prefix, so
		// global-setup's residue sweep takes them before the next run rather
		// than this teardown failing on a 403 it was never going to win.
		await cleanupRunObjects(api, token, [
			'zaakinformatieobject',
			'informatieobject',
			'informatieobjecttype',
		])
		await api.dispose()
	})

	// @e2e exclude No canonical scenario covers the Documents tab and its six columns.
	// REQ-ZAK-011 "Documents visible on the case" governs it and lives in the open
	// change documents-on-the-case; canonical REQ-ZAK-004a describes the older
	// grouped dossier view, which is a different surface. Citable once that change
	// archives; blocked until then by ConductionNL/.github#730.
	test('the tab lists this case documents with all six columns', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, caseId)

		// The headings first: five of the six values live on the REFERENCED
		// informatieobject, and the swap to an object-list is exactly where one
		// of them can go missing without anything else noticing.
		const headings = panel.locator(
			'[data-testid="dossier-columns"] .dossier-tab__column',
		)
		await expect(headings).toHaveCount(COLUMNS.length, { timeout: 20_000 })
		for (const [index, column] of COLUMNS.entries()) {
			await expect(headings.nth(index)).toHaveText(new RegExp(column, 'i'), {
				timeout: 10_000,
			})
		}

		const objection = panel
			.locator('.dossier-document-row')
			.filter({ hasText: OBJECTION_TITLE })
		await expect(objection).toHaveCount(1, { timeout: 20_000 })
		await expect(
			objection.locator('[data-testid="dossier-cell-type"]'),
		).toHaveText(OBJECTION_TYPE_NAME)
		await expect(
			objection.locator('[data-testid="dossier-cell-status"]'),
		).toHaveText(/Draft|Concept/)
		await expect(
			objection.locator('[data-testid="dossier-cell-direction"]'),
		).toHaveText(/Incoming|Inkomend/)
		await expect(
			objection.locator('[data-testid="dossier-cell-date"]'),
		).toContainText('2026')
		await expect(
			objection.locator('[data-testid="dossier-cell-author"]'),
		).toHaveText('Els Jansen')

		const acknowledgement = panel
			.locator('.dossier-document-row')
			.filter({ hasText: ACKNOWLEDGEMENT_TITLE })
		await expect(acknowledgement).toHaveCount(1)
		await expect(
			acknowledgement.locator('[data-testid="dossier-cell-type"]'),
		).toHaveText(ACKNOWLEDGEMENT_TYPE_NAME)
		await expect(
			acknowledgement.locator('[data-testid="dossier-cell-direction"]'),
		).toHaveText(/Outgoing|Uitgaand/)

		// The other case's documents exist and are filtered out. Without the
		// case filter this tab would list every document on the instance,
		// which on a demo-seeded install still looks plausible.
		await expect(panel.getByText(TAGGED_TITLE)).toHaveCount(0)
	})

	// @e2e exclude No canonical scenario covers the pairing of the Documents tab to
	// the panel the dossier renders in. REQ-ZAK-011 "Documents visible on the case"
	// in the open change documents-on-the-case is the governing one; the manifest
	// half is pinned by tests/vitest/caseDocumentsTab.spec.js.
	test('the Documents tab owns the panel the dossier renders in', async ({
		page,
	}) => {
		// The scenario asks for the tab to be reachable by its id
		// `case-documents`. That id is a MANIFEST fact and never reaches the
		// DOM — CnTabs gives panels uid-based ids — so the manifest half is
		// pinned by tests/vitest/caseDocumentsTab.spec.js, which fails if the
		// widget, its type or its position before Files ever moves. What a
		// browser can prove, and what this asserts, is the pairing: the panel
		// holding the dossier is the one the Documents tab controls, so
		// nothing else on the page can be mistaken for it.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		const tab = strip.getByRole('tab', { name: 'Documents', exact: true })
		await tab.click()

		const panelId = await tab.getAttribute('aria-controls')
		expect(panelId, 'the tab must control a panel').toBeTruthy()

		const panel = strip.locator('[role="tabpanel"]:not([hidden])')
		await expect(panel).toHaveAttribute('id', String(panelId), {
			timeout: 20_000,
		})
		await expect(panel.locator('.dossier-tab')).toBeVisible({
			timeout: 20_000,
		})

		// Files is no longer a tab of its own: it is the SECOND SECTION of this
		// same panel, which is how the strip came down from fourteen tabs to
		// six without losing the files leaf's share and comment surface (design
		// D5). Assert both sections are here, so a fold that quietly dropped one
		// of them fails rather than reading as a successful consolidation.
		await expect(
			strip.getByRole('tab', { name: /^(Files|Bestanden)$/ }),
		).toHaveCount(0)
		await expect(
			panel.locator('[data-testid="case-section-case-documents"]'),
		).toBeVisible({ timeout: 20_000 })
		await expect(
			panel.locator('[data-testid="case-section-case-files"]'),
		).toBeVisible({ timeout: 20_000 })
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#scenario-req-zak-004b-empty-dossier-shows-upload-cta-with-drag-and-drop-zone
	test('a case without documents says so and still offers upload', async ({
		page,
	}) => {
		// A tab whose query fails renders an empty state too, so the REQUEST is
		// asserted beside the text. Without it this test passes on a 404 and
		// the tab looks correct while showing nothing it should.
		const statuses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/dossier')) statuses.push(r.status())
		})

		const panel = await openDocumentsTab(page, emptyCaseId)

		await expect(panel).toContainText(/No documents yet|Nog geen documenten/, {
			timeout: 20_000,
		})
		await expect(
			panel.getByRole('button', { name: /Upload document/ }).first(),
		).toBeVisible()
		await expect(
			panel.locator('[data-testid="dossier-columns"]'),
			'no columns without rows to head',
		).toHaveCount(0)

		await expect
			.poll(() => statuses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(
			statuses.every((s) => s < 400),
			`dossier queries: ${statuses.join(',')}`,
		).toBe(true)
	})

	// @e2e exclude No canonical scenario covers filing a dropped file on the case
	// with its type and direction. REQ-ZAK-011 "Drop a file onto the tab" and
	// REQ-ZAK-013 "Direction chosen on upload" govern it and live in the open change
	// documents-on-the-case; canonical REQ-ZAK-005a asserts the dialog opening for a
	// two-file drop, not the saved type, direction and case link this proves.
	test('a dropped file is filed on the case with its type and direction', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, dropCaseId)

		await uploadThroughDialog(page, panel, {
			fileName: 'inspection-report.pdf',
			title: DROPPED_TITLE,
			typeName: OBJECTION_TYPE_NAME,
			direction: /Incoming|Inkomend/,
		})

		const row = panel
			.locator('.dossier-document-row')
			.filter({ hasText: DROPPED_TITLE })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row.locator('[data-testid="dossier-cell-type"]')).toHaveText(
			OBJECTION_TYPE_NAME,
		)
		await expect(
			row.locator('[data-testid="dossier-cell-direction"]'),
		).toHaveText(/Incoming|Inkomend/)

		// The SAVED objects, not the rendered row: the join is what makes the
		// document belong to THIS case, and a row can be on screen for a
		// document linked to another one.
		const stored = await storedDocument(DROPPED_TITLE)
		expect(String(stored.informatieobjecttype)).toBe(objectionTypeId)
		expect(String(stored.direction)).toBe('incoming')

		const joins = await listObjects(api, 'zaakinformatieobject', {
			_limit: '200',
		})
		const join = joins.find(
			(row) => String(row.informatieobject) === objectId(stored),
		)
		expect(join, 'the upload must write the case link').toBeTruthy()
		expect(String(join.case)).toBe(dropCaseId)
	})

	// @e2e exclude Keywords are not in the canonical spec. REQ-ZAK-012 "Tag a
	// document on upload" governs this and lives in the open change
	// documents-on-the-case.
	test('keywords typed on upload are saved and shown as chips', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, dropCaseId)

		const title = `${RUN_PREFIX} Keyworded drawing`
		await uploadThroughDialog(page, panel, {
			fileName: 'drawing.pdf',
			title,
			typeName: OBJECTION_TYPE_NAME,
			keywords: ['bezwaar', 'bouwtekening'],
		})

		const stored = await storedDocument(title)
		expect((stored.keywords ?? []).map(String).sort()).toEqual([
			'bezwaar',
			'bouwtekening',
		])

		const row = panel.locator('.dossier-document-row').filter({ hasText: title })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row.locator('[data-testid="dossier-keyword"]')).toHaveText([
			'bezwaar',
			'bouwtekening',
		])
	})

	// @e2e exclude The keyword filter is not in the canonical spec. REQ-ZAK-012
	// "Filter the list on a keyword" governs it and lives in the open change
	// documents-on-the-case; canonical REQ-ZAK-004c is status and date filtering.
	test('the keyword filter narrows the list, and clearing it restores both', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, filterCaseId)

		const rows = panel.locator('.dossier-document-row')
		await expect(rows).toHaveCount(2, { timeout: 20_000 })

		const filter = panel
			.getByRole('combobox', { name: /Filter by keyword|Filter op trefwoord/ })
			.or(
				panel.locator(
					'[data-testid="dossier-keyword-filter"] [role="combobox"]',
				),
			)
			.first()
		await filter.click()
		// The dropdown is appended to the body, so the options are found on the
		// page rather than inside the panel.
		await page
			.getByRole('option')
			.filter({ hasText: /^bezwaar$/ })
			.click()

		await expect(rows).toHaveCount(1, { timeout: 20_000 })
		await expect(rows.first()).toContainText(TAGGED_TITLE)

		// Clearing has to bring the other one back, or the filter is a one-way
		// door and the untagged document is unreachable. NcSelect names its
		// deselect button after the option ("Deselect bezwaar"), and the
		// keyword is the same word in either locale.
		await panel
			.getByRole('button', { name: /bezwaar/i })
			.first()
			.click()
		await expect(rows).toHaveCount(2, { timeout: 20_000 })
	})

	// The anchor carries no `scenario-` prefix, which is the form gate-19
	// slugs and the form the sibling citation in
	// `spec-coverage/document-zaakdossier.spec.ts` already uses for this same
	// scenario. The prefixed spelling resolves when a human clicks it on
	// GitHub and credits nothing in the gate.
	//
	// MUTATION CHECKED 2026-09-12 against a live instance (NC 34.0.3,
	// openregister at `development`), because an assertion nobody has watched
	// fail is what this repair exists to remove — and because a PRECONDITION
	// failure is not evidence about the guard, which is exactly how this test
	// came to CI: the assertion was strengthened, the fixture was not, and both
	// attempts died on "the version panel must list at least one version".
	//
	// `VersionHistoryPanel.restoreDisabled` was made to `return false`, the
	// bundle rebuilt, and this test reddened on the restore assertion and
	// nowhere else:
	//
	//   Error: version 1 of a final document must refuse restore
	//   expect(locator).toBeDisabled() failed
	//   Expected: disabled / Received: enabled
	//
	// The version-count precondition PASSED in that run, which is the half
	// that matters: the panel listed its two versions and the guard is what
	// failed. Restored, rebuilt, green again.
	//
	// 🔑 AND THEN IT WENT RED IN CI ANYWAY, ON A FIXTURE THAT WAS CORRECT.
	// Worth the space, because "green on my instance" was the whole of the
	// evidence and it was not enough. The panel built its PROPFIND with
	// `generateUrl`, which prefixes `/index.php` wherever the front controller
	// is inactive, so the request went to `/index.php/remote.php/dav/…` and
	// routed nowhere. Nextcloud sets that flag from `SetEnv
	// front_controller_active true` in its own `.htaccess`: an Apache instance
	// has it, the `php -S` instance the E2E job runs does not. Every developer
	// box passed and CI could not.
	//
	// So the mutation check was RE-RUN with that one `.htaccess` line flipped,
	// which reproduced the CI failure exactly on an instance that had just
	// passed. Broken guard reddens on the restore assertion, restored guard is
	// green, and the whole file is green in BOTH configurations. If this test
	// ever goes red on the version count again, check `modRewriteWorking` on
	// the instance before looking at the fixture.
	//
	// ABSENT IS NOT DISABLED, and both halves are pinned, because a control
	// that vanishes tells the user nothing while a refused one says the
	// document is final. Two more mutations, both measured 2026-09-12:
	//
	//   hide it on a final document (`v-if="!restoreDisabled"`)
	//     -> "every listed version must offer a Restore control, so the
	//        refusal is visible rather than absent", expected 2, received 0
	//   remove it outright (`v-if="false"`)
	//     -> `dossierTab.spec.js` reddens with
	//        expected [ 'Download' ] to deeply equal [ 'Download', 'Restore' ]
	//
	// The two suites cover the two states and agree with the scenario. vitest
	// mounts a DRAFT: both controls present, Restore issues the MOVE. This one
	// mounts a FINAL: both present, Restore refused. Neither passes on absent.
	// @e2e openspec/specs/document-zaakdossier/spec.md#req-zak-006b-restore-is-disabled-for-definitief-documents
	test('Versions on a row opens the panel, and restore is refused on a final document', async ({
		page,
	}) => {
		// THE VERSIONS ARE REAL NOW. `seedVersionedDocument()` uploads the
		// document through the product's own endpoint and then writes the file
		// a second time, because Nextcloud's version list holds PREVIOUS
		// contents and not the current one. The helper carries the storage path
		// and how it was measured; what matters here is that the panel below
		// has something to list, which the old fixture never gave it.
		//
		// This spec CANNOT see the defect that convention exists to fix. It
		// signs in as `admin`, which is the account the documents used to be
		// filed under, so writer and reader were the same person and the tab
		// looked correct. The coverage for the OWNER is
		// `tests/Unit/Service/ZgwDocumentStorageOwnerTest.php`, and the
		// coverage for the version buttons actually DOING something is
		// `tests/vitest/dossierTab.spec.js` — the assertion below is about the
		// restore GUARD, and a button that does nothing satisfies it just as
		// well as one that is correctly disabled.
		const panel = await openDocumentsTab(page, versionCaseId)

		const row = panel
			.locator('.dossier-document-row')
			.filter({ hasText: `${RUN_PREFIX} Final report` })
		await expect(row).toHaveCount(1, { timeout: 20_000 })

		// The row's Actions menu is its last control (the selection checkbox
		// is an input, not a button), and the menu itself is appended to the
		// body, so the entry is found on the page.
		await row.getByRole('button').last().click()

		// 🔴 THE ENTRY IS A `menuitem`, NOT A `button`. `NcActions` decides its
		// own semantics from what it holds: every child here is an
		// `NcActionButton`, so `actionsMenuSemanticType` resolves to `menu`, the
		// component provides `isInSemanticMenu`, and `NcActionButton` puts
		// `role="menuitem"` ON the `<button>` (its `<li>` takes
		// `role="presentation"`). An explicit role REPLACES the implicit one, so
		// `getByRole('button', …)` cannot match a menu entry at all — it waited
		// the full budget and reported `locator.click: Timeout` on an entry that
		// was on screen the whole time, which reads as a menu that never opened.
		//
		// The toggle on the line above keeps `role="button"` because it sits in
		// the row rather than in the menu, which is why only the SECOND click
		// failed and the first looked fine.
		//
		// Asserting the menu role is the accessible truth rather than a
		// workaround: if an `NcActionInput` is ever added here the menu becomes a
		// `dialog`, the entries go back to plain buttons, and this line should
		// fail and be read again.
		await page
			.getByRole('menuitem', { name: /Version history|Versiegeschiedenis/ })
			.click()

		const versionPanel = panel.locator('.dossier-version-panel')
		await expect(versionPanel).toBeVisible({ timeout: 20_000 })

		const entries = versionPanel.locator('.dossier-version-panel__item')

		// THE LIST HAS TO BE THERE BEFORE IT CAN BE COUNTED. The panel root
		// mounts with a spinner and fills in when its PROPFIND lands, so
		// `toBeVisible()` above is satisfied by the EMPTY moment. A plain
		// `.count()` read there returns 0 and never looks again, which is how
		// the first run of this repair failed at `Expected: 2, Received: 0`
		// with two versions on screen: the count of Download buttons was taken
		// during the fetch and the count of items after it.
		//
		// So the first assertion on this panel is a RETRYING one, and it is
		// also the precondition the rest depends on: the loop below used to run
		// over `restore.count()` alone, so a panel rendering zero Restore
		// buttons ran the body zero times and reported green — the requirement
		// "the Herstellen button MUST be visibly disabled on all versions" was
		// satisfied by there being no button to disable, which is also what a
		// panel that failed to render looks like.
		await expect(
			entries,
			'the version panel must list at least one version, or there is nothing to refuse restoring',
		).not.toHaveCount(0, { timeout: 20_000 })
		const versionCount = await entries.count()

		// EVERY listed version offers a download — a version you cannot fetch
		// is a row of text. Asserted as an invariant rather than a fixed
		// number, because how many previous versions exist depends on
		// Nextcloud's own file-versions retention, which this suite does not
		// control and must not pretend to: the scenario's "both versions" half
		// is only as strong as the instance's versioning, while "each one is
		// downloadable" holds on any instance and fails the moment the action
		// is dropped.
		await expect(
			versionPanel.locator(
				'.dossier-version-panel__item button:has-text("Download")',
			),
			'every listed version must offer a Download control',
		).toHaveCount(versionCount)

		// The half that is fully deterministic: the document is `final`, the
		// server refuses to change it, so the UI must not offer to.
		const restore = versionPanel.getByRole('button', {
			name: /Restore|Herstellen/,
		})

		// The same invariant as the download count: every version offers a
		// Restore control, and every one of them is refused. Hiding the control
		// instead of disabling it reddens here, and so does a panel that lists
		// versions but paints no actions.
		await expect(
			restore,
			'every listed version must offer a Restore control, so the refusal is visible rather than absent',
		).toHaveCount(versionCount)
		for (let index = 0; index < versionCount; index++) {
			await expect(
				restore.nth(index),
				`version ${index + 1} of a final document must refuse restore`,
			).toBeDisabled()
		}

		// STILL UNASSERTED, NAMED RATHER THAN QUIETLY SKIPPED: the scenario's
		// third clause, that hovering the disabled control explains why.
		// `VersionHistoryPanel` binds that sentence as `:title` on `NcButton`,
		// and whether NcButton forwards it onto the `<button>` this locator
		// resolves to has not been verified against a running instance. An
		// assertion nobody has watched fail is the defect this change is
		// repairing, so it is not added on a guess.
		const stored = await showObject(api, 'informatieobject', versionedDocumentId)
		expect(String(stored.status)).toBe('final')
	})

	// @e2e exclude No canonical scenario covers the Generate document picker listing
	// the library. REQ-005 "The picker lists the library" governs it and lives in the
	// open change documents-on-the-case; canonical template-library REQ-001 to
	// REQ-004 are the REST and discovery contracts, not this picker.
	test('the Generate document picker lists the library by name', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${generateCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-generate-document')

		const dialog = page
			.getByRole('dialog')
			.filter({ hasText: /Generate|Genereren/ })
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		await dialog
			.locator('[data-testid="generate-document-template"]')
			.getByRole('combobox')
			.click()

		// The two document templates the library ships, by name. The picker
		// lists whatever TemplateController#index returns, so other entries may
		// be there too; what REQ-005 requires is that these two are.
		await expect(
			page.getByRole('option').filter({ hasText: 'Ontvangstbevestiging' }),
		).toHaveCount(1, { timeout: 20_000 })
		await expect(
			page.getByRole('option').filter({ hasText: 'Verdagingsbrief' }),
		).toHaveCount(1)
	})

	// @e2e exclude No canonical scenario covers generating a letter onto the
	// Documents tab. REQ-BES-012 "Generate a letter onto the Documents tab" governs
	// it and lives in the open change documents-on-the-case; canonical REQ-BES-001 is
	// the conceptbeschikking composer, a different flow.
	test('Generate document files a draft outgoing letter on the case', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${generateCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-generate-document')

		const dialog = page
			.getByRole('dialog')
			.filter({ hasText: /Generate|Genereren/ })
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		await dialog
			.locator('[data-testid="generate-document-template"]')
			.getByRole('combobox')
			.click()
		await page
			.getByRole('option')
			.filter({ hasText: 'Ontvangstbevestiging' })
			.click()

		await dialog.locator('[data-testid="generate-document-confirm"]').click()
		await expect(dialog).toContainText(
			/Document added to the case|Document toegevoegd aan de zaak/,
			{ timeout: 30_000 },
		)

		// The STORED object: the row on screen proves the tab renders, the
		// stored fields prove the handler filed what the spec says it files.
		let stored: any
		await expect(async () => {
			const rows = await listObjects(api, 'informatieobject', {
				_limit: '200',
			})
			stored = rows.find((row) => String(row.title) === 'Ontvangstbevestiging')
			expect(stored, 'the letter should have been filed').toBeTruthy()
		}).toPass({ timeout: 30_000 })

		expect(String(stored.status)).toBe('draft')
		expect(String(stored.direction)).toBe('outgoing')
		expect(
			String(stored.auteur ?? ''),
			'the signed-in user is the author',
		).not.toBe('')

		const joins = await listObjects(api, 'zaakinformatieobject', {
			_limit: '200',
		})
		const join = joins.find(
			(row) => String(row.informatieobject) === objectId(stored),
		)
		expect(join, 'the letter must be linked to the case').toBeTruthy()
		expect(String(join.case)).toBe(generateCaseId)

		const panel = await openDocumentsTab(page, generateCaseId)
		const row = panel
			.locator('.dossier-document-row')
			.filter({ hasText: 'Ontvangstbevestiging' })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row.locator('[data-testid="dossier-cell-status"]')).toHaveText(
			/Draft|Concept/,
		)
		await expect(
			row.locator('[data-testid="dossier-cell-direction"]'),
		).toHaveText(/Outgoing|Uitgaand/)
	})
})
