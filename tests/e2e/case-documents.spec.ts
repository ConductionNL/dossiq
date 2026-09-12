/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Documents tab: the case file, on the case page.
 *
 * documents-on-the-case task 2.2 (Ruben, 2026-09-11, option b): the tab is
 * now `type: "object-list"` (a CnObjectListWidget), grown in nextcloud-vue
 * #1115/#1117 specifically so this swap would not drop grouping, multi-select
 * with bulk actions, interactive sort, the keyword facet, or upload. Every
 * one of those five is asserted here, on the real rendered DOM, because that
 * is the only thing Ruben's decision was actually protecting.
 *
 * DOM CONVENTIONS THIS SPEC DEPENDS ON (CnDataTable / CnObjectListWidget,
 * @conduction/nextcloud-vue):
 *   - a row is `[data-testid="cn-object-row"]`; its cells are `<td>` in
 *     column order, offset by ONE because `selectable: true` adds a leading
 *     checkbox `<td>` — see `rowCell()` below.
 *   - a column header is a real `<th>` (role `columnheader`), found by its
 *     visible label text.
 *   - a group (from `content.groupBy`) is `[data-testid="object-list-group"]`
 *     and contains its OWN CnDataTable, so headers/rows must be scoped to one
 *     group's locator rather than the whole panel — grouping is always on
 *     for this widget instance.
 *   - the facet is `[data-testid="object-list-facet"]`, one
 *     `[data-testid="object-list-facet-chip"]` button per keyword.
 *   - the bulk bar is `[data-testid="object-list-bulk-bar"]`, appearing once
 *     a row's checkbox is checked; its buttons are
 *     `[data-testid="object-list-bulk-action"]`, one per declared
 *     `bulkActions` entry, by their label text.
 *   - the click-to-upload button/input are `[data-testid="object-list-upload"]`
 *     / `[data-testid="object-list-upload-input"]`.
 *   - the row's only action (Versions) is `[data-testid="cn-row-actions"]`
 *     and is a plain button, not a menu: NcActions renders a lone action
 *     inline, and its testid replaces the entry's. See `clickRowAction`.
 *
 * Locale: nothing forces the language of the E2E instance, so tab and button
 * names are matched in either locale the app ships, as the sibling
 * case-detail specs do. Rows are matched on their run-prefixed title, which
 * is data this spec wrote and no translation touches.
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
import { clickHeaderAction, tickCheckbox } from './helpers/nav.ts'

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
 * A second, DIFFERENT valid PDF for the version-history fixture. Nextcloud's
 * version list holds PREVIOUS contents, not the current one, so a document
 * needs a second write with changed bytes before it has anything to list.
 */
const PDF_BYTES_V2 = Buffer.from(
	'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
		+ '2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n'
		+ 'trailer<</Root 1 0 R>>\n%%EOF\n\n% v2\n',
	'utf8',
)

let api: APIRequestContext
let token: string
let caseTypeId = ''
/** The case the tab is read on: two documents of different types (also the grouping fixture). */
let caseId = ''
/** A case with no documents at all. */
let emptyCaseId = ''
/** A case the drop zone / upload button writes into, kept apart so the new row is unambiguous. */
let dropCaseId = ''
/** A case the keyword facet is exercised on. */
let filterCaseId = ''
/** A case with several same-type documents, for the interactive-sort assertion. */
let sortCaseId = ''
/** A case with several documents, for the multi-select + bulk-action assertion. */
let bulkCaseId = ''
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
		natureRelationshipDisplay: 'Hoort at omgekeerd',
	})
	return id
}

/**
 * Seed a document that actually has a previous Nextcloud file version, for
 * the version-history / restore-guard assertion.
 *
 * A plain `seedDocument()` POSTs an informatieobject row and nothing else:
 * no file is written, so no `fileId` is stamped, and
 * `VersionHistoryPanel.fetchVersions()` returns before it asks the server
 * anything — the panel then renders "No previous versions", which looks
 * identical to a panel whose PROPFIND failed and to one that lost its
 * buttons entirely. This goes through the product's own APIs instead of
 * naming the storage path: the upload endpoint writes version one and stamps
 * the fileId, a second write through OpenRegister's file endpoint makes the
 * version (Nextcloud's version list holds PREVIOUS contents, not the
 * current one), and the draft -> final transition finalises it last,
 * because a final document is meant to refuse further writes.
 *
 * @param onCase The case the document hangs on.
 * @param options Title, filename, type and author for the seeded document.
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
	// THE SIZE, NOT THE STATUS. A 200 that changed no bytes is a write that
	// did nothing, and a file whose content never changed has no previous
	// content to list — which lands back on the empty panel this helper
	// exists to prevent, wearing a green fixture.
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
 * fetch, until its tab is opened.
 *
 * @param page The Playwright page.
 * @param id The case id to open.
 * @return The open tab panel.
 */
async function openDocumentsTab(page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })
	return await openCasePanel(page, 'documents')
}

/**
 * One group's container, by its heading text (the resolved type name).
 *
 * @param panel The open Documents panel.
 * @param typeName The type's run-prefixed name.
 * @return The group locator.
 */
function group(panel, typeName: string) {
	return panel
		.locator('[data-testid="object-list-group"]')
		.filter({ hasText: typeName })
}

/**
 * The nth data cell of a row, accounting for the leading checkbox `<td>`
 * `selectable: true` adds. Column 0 is Title.
 *
 * @param row The row locator.
 * @param column The 0-based data-column index (0 = Title … 5 = Author).
 * @return The cell locator.
 */
function rowCell(row, column: number) {
	return row.locator('td').nth(column + 1)
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
 * Upload one file through the tab's click-to-upload button, the metadata
 * dialog and Upload.
 *
 * `content.dropZone` and the click-to-upload button dispatch the identical
 * action (nextcloud-vue's CnObjectListWidget rides one onto the other), so
 * driving the upload button's hidden file input exercises the same handler a
 * real drop does — and unlike a synthetic DataTransfer it works in every
 * browser the project runs.
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
	},
) {
	await panel.locator('[data-testid="object-list-upload-input"]').setInputFiles({
		name: options.fileName,
		mimeType: 'application/pdf',
		buffer: PDF_BYTES,
	})

	const dialog = page.locator('.dossier-metadata-dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })

	await dialog.getByRole('combobox').first().click()
	await page.getByRole('option').filter({ hasText: options.typeName }).click()

	if (options.direction) {
		await directionPicker(dialog).click()
		await page.getByRole('option').filter({ hasText: options.direction }).click()
	}

	await dialog.getByRole('textbox', { name: /Title|Titel/ }).fill(options.title)

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

/**
 * Fire a document row's action.
 *
 * 🔴 ONE ROW ACTION IS NOT A MENU, AND THE ENTRY TESTID DOES NOT EXIST.
 * `case-documents` declares exactly one `rowActions` entry (Versions).
 * `CnRowActions` puts `data-testid="cn-row-actions"` on `<NcActions>` and
 * `data-testid="cn-action-item-<slug>"` on each `NcActionButton`, and with a
 * single action NcActions takes its `actions.length === 1 && !forceMenu`
 * branch and renders that action INLINE as the component's own root node.
 * Both testids then target one element, Vue's fallthrough attribute wins, and
 * the button ends up carrying `cn-row-actions` while
 * `cn-action-item-versions` is nowhere in the document.
 *
 * Measured rather than reasoned about: mounting CnRowActions' markup with one
 * action renders
 * `<button class="… action-item action-item--single" data-testid="cn-row-actions">`
 * and `cn-action-item-versions` resolves to nothing; the same mount with two
 * actions renders the popover instead. The only variable between the two is
 * how many actions were passed.
 *
 * So the single click below IS the Versions click, and the old two-step
 * version waited out its whole budget on a menu entry that is never created.
 * The class assertion is the guard: add a second row action and the widget
 * goes back to a popover, `action-item--single` stops being true, and this
 * helper has to grow the open step again rather than quietly clicking a menu
 * toggle and asserting nothing happened.
 *
 * @param row The row locator.
 * @param itemTestId The `cn-action-item-*` suffix, named for the reader.
 */
async function clickRowAction(row, itemTestId: string) {
	const trigger = row.locator('[data-testid="cn-row-actions"]')
	await expect(
		trigger,
		`${itemTestId} is the row's only action, so NcActions renders it inline `
			+ 'as a single button. A popover here means a second action was added '
			+ 'and this helper must open the menu again.',
	).toHaveClass(/action-item--single/, { timeout: 20_000 })
	await trigger.click()
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

		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type',
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
				title: `${RUN_PREFIX} Documents sort`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Documents bulk`,
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
			sortCaseId,
			bulkCaseId,
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

		// caseId: one of EACH type, so the widget renders two groups.
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
			fileName: 'tagged-drawing.pdf',
			informatieobjecttype: objectionTypeId,
			direction: 'incoming',
			keywords: ['bezwaar'],
			auteur: 'Els Jansen',
		})
		await seedDocument(filterCaseId, {
			title: `${RUN_PREFIX} Untagged letter`,
			fileName: 'untagged-letter.pdf',
			informatieobjecttype: acknowledgementTypeId,
			direction: 'outgoing',
			auteur: 'Piet de Boer',
		})

		// The sort case: three documents of the SAME type (one group, one
		// table), so a header-click reorder is directly observable in row order.
		await seedDocument(sortCaseId, {
			title: `${RUN_PREFIX} Sort B`,
			fileName: 'sort-b.pdf',
			informatieobjecttype: objectionTypeId,
			creatiedatum: '2026-05-02',
		})
		await seedDocument(sortCaseId, {
			title: `${RUN_PREFIX} Sort A`,
			fileName: 'sort-a.pdf',
			informatieobjecttype: objectionTypeId,
			creatiedatum: '2026-05-01',
		})
		await seedDocument(sortCaseId, {
			title: `${RUN_PREFIX} Sort C`,
			fileName: 'sort-c.pdf',
			informatieobjecttype: objectionTypeId,
			creatiedatum: '2026-05-03',
		})

		// The bulk case: two documents, both draft, so "Mark final" has
		// something to do to both.
		await seedDocument(bulkCaseId, {
			title: `${RUN_PREFIX} Bulk one`,
			fileName: 'bulk-one.pdf',
			informatieobjecttype: objectionTypeId,
		})
		await seedDocument(bulkCaseId, {
			title: `${RUN_PREFIX} Bulk two`,
			fileName: 'bulk-two.pdf',
			informatieobjecttype: objectionTypeId,
		})

		versionedDocumentId = await seedVersionedDocument(versionCaseId, {
			title: `${RUN_PREFIX} Final report`,
			fileName: 'final-report.pdf',
			typeId: acknowledgementTypeId,
			auteur: 'Piet de Boer',
		})
	})

	test.afterAll(async () => {
		if (!api) return

		// 🔴 THE JOINS FIRST, BY THEIR CASE, BECAUSE THE PREFIX SWEEP CANNOT SEE
		// THEM (dossiq#2477). `cleanupRunObjects`'s prefix sweep matches a row
		// by finding `RUN_PREFIX` anywhere in its JSON, and a
		// `zaakinformatieobject` is two uuids, a date and
		// `natureRelationshipDisplay` — which the schema declares as an ENUM of
		// exactly "Hoort at omgekeerd" and "Legt vast, omgekeerd", so no prefix
		// can ever land there. Calling `cleanupRunObjects` on
		// 'zaakinformatieobject' below never fails; it also never deletes
		// anything, so every join this spec creates would otherwise survive.
		//
		// The sweep's other arm, `matchesCase`, is the one that fits, and it
		// arms only when `case` is in the schema's own field list — which it
		// must not be here, since a case is archival and the sweep would then
		// report every one of them as a survivor and fail this hook. So the
		// joins are collected by their `case` field and removed directly.
		const ourCases = new Set(
			[
				caseId,
				emptyCaseId,
				dropCaseId,
				filterCaseId,
				sortCaseId,
				bulkCaseId,
				generateCaseId,
				versionCaseId,
			].filter((id) => id !== ''),
		)
		// The documents the joins point at go with them: the letter the
		// Generate document test files is titled after its TEMPLATE
		// ("Ontvangstbevestiging"), carries no run prefix, and the prefix
		// sweep cannot see it either. Its join names it, which is the only
		// handle on it there is, so it is collected here while readable.
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

		// The type catalog rows do carry the run prefix in their description,
		// so the ordinary sweep finds those. The cases are archival and
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
	// grouped dossier view, which is a different surface.
	test('the tab lists this case documents with all six columns', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, caseId)
		const objectionGroup = group(panel, OBJECTION_TYPE_NAME)
		await expect(objectionGroup).toBeVisible({ timeout: 20_000 })

		for (const column of [
			'Title',
			'Type',
			'Status',
			'Direction',
			'Date',
			'Author',
		]) {
			await expect(
				objectionGroup.getByRole('columnheader', {
					name: new RegExp(column, 'i'),
				}),
			).toBeVisible()
		}

		const row = objectionGroup
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: OBJECTION_TITLE })
		await expect(row).toHaveCount(1, { timeout: 20_000 })
		await expect(rowCell(row, 1)).toHaveText(OBJECTION_TYPE_NAME)
		// Cells 2 and 3 are Status and Direction. What they should read is
		// asserted in its own parked test below, not weakened here.
		await expect(rowCell(row, 4)).toContainText('2026')
		await expect(rowCell(row, 5)).toHaveText('Els Jansen')

		// The other case's documents exist and are filtered out.
		await expect(panel.getByText(TAGGED_TITLE)).toHaveCount(0)
	})

	// @e2e exclude Same surface and same governing requirement as the six-column
	// test above; this is the enum-label half of it, split out so the other five
	// columns keep running while the label defect is open.
	//
	// 🔴 PARKED BECAUSE THE PRODUCT IS WRONG AND THIS REPO CANNOT FIX IT. A
	// Dutch reader sees `incoming` in the Direction column and `draft` in
	// Status. Both are stored codes. The bespoke DossierTab that #2553 deleted
	// mapped them itself, in DocumentRow.vue, and the object-list widget that
	// replaced it maps nothing.
	//
	// HALF of that is fixed in this PR. `informatieobject.status` and
	// `informatieobject.direction` now carry `x-enum-labels` in
	// lib/Settings/register.d/70-document-zaakdossier.json, which is where the
	// labels belong and which the form and detail surfaces already read.
	//
	// The other half is the library, and it is why this test stays parked.
	// CnObjectListWidget never passes a schema to CnDataTable, and
	// CnDataTable.getSchemaProperty matches a column key exactly, so a dotted
	// `_extend` key such as `informatieobject.direction` resolves to `{}`.
	// CnCellRenderer then sees no `enum` and prints the raw value. Measured by
	// mounting CnCellRenderer twice on the same value: handed `{}` it renders
	// "incoming", handed the annotated property it renders "Incoming". The
	// renderer is willing; nothing reaches it.
	//
	// UNFIX IT when ConductionNL/nextcloud-vue#1132 lands, and fold these two
	// assertions back into the six-column test above.
	test.fixme('the Status and Direction cells read as labels, not the stored codes', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, caseId)
		const objectionGroup = group(panel, OBJECTION_TYPE_NAME)
		const row = objectionGroup
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: OBJECTION_TITLE })
		await expect(row).toHaveCount(1, { timeout: 20_000 })

		await expect(rowCell(row, 2)).toHaveText(/Draft|Concept/)
		await expect(rowCell(row, 3)).toHaveText(/Incoming|Inkomend/)
	})

	// @e2e exclude No canonical scenario covers row grouping on the Documents tab.
	// documents-on-the-case task 2.2 (option b) and
	// openspec/changes/object-list-widget-grouping-select-facet govern it.
	test('documents group by type, one heading per type in use', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, caseId)

		const objectionGroup = group(panel, OBJECTION_TYPE_NAME)
		const acknowledgementGroup = group(panel, ACKNOWLEDGEMENT_TYPE_NAME)
		await expect(objectionGroup).toBeVisible({ timeout: 20_000 })
		await expect(acknowledgementGroup).toBeVisible({ timeout: 20_000 })

		await expect(
			objectionGroup
				.locator('[data-testid="cn-object-row"]')
				.filter({ hasText: OBJECTION_TITLE }),
		).toHaveCount(1)
		await expect(
			acknowledgementGroup
				.locator('[data-testid="cn-object-row"]')
				.filter({ hasText: ACKNOWLEDGEMENT_TITLE }),
		).toHaveCount(1)
		// A document is not ALSO in the other group.
		await expect(
			objectionGroup
				.locator('[data-testid="cn-object-row"]')
				.filter({ hasText: ACKNOWLEDGEMENT_TITLE }),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#scenario-req-zak-004b-empty-dossier-shows-upload-cta-with-drag-and-drop-zone
	test('a case without documents says so and still offers upload', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, emptyCaseId)

		await expect(panel).toContainText(/No documents yet|Nog geen documenten/, {
			timeout: 20_000,
		})
		await expect(
			panel.locator('[data-testid="object-list-upload"]'),
		).toBeVisible()
		await expect(panel.locator('[data-testid="object-list-group"]')).toHaveCount(
			0,
		)
	})

	// @e2e exclude No canonical scenario covers filing an uploaded file on the case
	// with its type and direction. REQ-ZAK-011 and REQ-ZAK-013 govern it and live in
	// the open change documents-on-the-case.
	test('an uploaded file is filed on the case with its type and direction', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, dropCaseId)

		await uploadThroughDialog(page, panel, {
			fileName: 'inspection-report.pdf',
			title: DROPPED_TITLE,
			typeName: OBJECTION_TYPE_NAME,
			direction: /Incoming|Inkomend/,
		})

		const row = group(panel, OBJECTION_TYPE_NAME)
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: DROPPED_TITLE })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		// The Direction CELL reads `incoming` rather than Incoming, and that
		// defect is asserted once, in its own parked test above. What this test
		// is for is that the upload filed the direction at all, so it checks the
		// stored value below instead of the cell.
		const stored = await storedDocument(DROPPED_TITLE)
		expect(String(stored.informatieobjecttype)).toBe(objectionTypeId)
		expect(String(stored.direction)).toBe('incoming')

		const joins = await listObjects(api, 'zaakinformatieobject', {
			_limit: '200',
		})
		const join = joins.find(
			(r) => String(r.informatieobject) === objectId(stored),
		)
		expect(join, 'the upload must write the case link').toBeTruthy()
		expect(String(join.case)).toBe(dropCaseId)
	})

	// @e2e exclude The keyword facet is not in the canonical spec. REQ-ZAK-012
	// "Filter the list on a keyword" governs it and lives in the open change
	// documents-on-the-case; canonical REQ-ZAK-004c is status and date filtering.
	test('the keyword facet narrows the list, and clearing it restores both', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, filterCaseId)

		await expect(panel.locator('[data-testid="cn-object-row"]')).toHaveCount(2, {
			timeout: 20_000,
		})

		const chip = panel
			.locator('[data-testid="object-list-facet-chip"]')
			.filter({ hasText: 'bezwaar' })
		await expect(chip).toBeVisible({ timeout: 20_000 })
		await chip.click()

		await expect(panel.locator('[data-testid="cn-object-row"]')).toHaveCount(1, {
			timeout: 20_000,
		})
		await expect(panel.getByText(TAGGED_TITLE)).toBeVisible()

		await chip.click()
		await expect(panel.locator('[data-testid="cn-object-row"]')).toHaveCount(2, {
			timeout: 20_000,
		})
	})

	// @e2e exclude No canonical scenario covers interactive column sort on the
	// Documents tab. documents-on-the-case task 2.2 (option b) governs it.
	//
	// 🔴 PARKED BECAUSE THE PRODUCT CANNOT DO THIS YET, NOT BECAUSE THE TEST IS
	// WRONG. This test was right and it caught a real defect: clicking Title
	// reordered nothing. CnObjectListWidget sorts server-side only, by
	// refetching with `_order[<column key>]`, and every column on this widget
	// is a dotted `_extend` path that OpenRegister will not order on. Measured
	// against a live register: `_order[informatieobject]` asc and desc come
	// back reversed, `_order[informatieobject.title]` asc and desc come back
	// identical. The two CI runs bear that out, since they ended on two
	// different wrong orders, ["Sort B","Sort A","Sort C"] and
	// ["Sort C","Sort B","Sort A"].
	//
	// dossiq has dropped `sortable` from the case-documents widget rather than
	// keep six headers that look clickable and reorder nothing, so there is now
	// no control for this test to click either. UNFIX IT, and put `sortable`
	// back in src/manifest.json, when ConductionNL/nextcloud-vue#1131 lands a
	// client-side sort for dotted keys or ConductionNL/openregister#3676 lets
	// `_order` reach into an extended object.
	test.fixme('clicking the Title header sorts the group, ascending then descending', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, sortCaseId)
		const sortGroup = group(panel, OBJECTION_TYPE_NAME)
		await expect(sortGroup.locator('[data-testid="cn-object-row"]')).toHaveCount(
			3,
			{ timeout: 20_000 },
		)

		const titleHeader = sortGroup.getByRole('columnheader', { name: /^Title$/i })
		await titleHeader.click()
		await expect
			.poll(async () =>
				(
					await sortGroup
						.locator('[data-testid="cn-object-row"]')
						.allTextContents()
				).map((t) => t.match(/Sort [ABC]/)?.[0]),
			)
			.toEqual(['Sort A', 'Sort B', 'Sort C'])

		await titleHeader.click()
		await expect
			.poll(async () =>
				(
					await sortGroup
						.locator('[data-testid="cn-object-row"]')
						.allTextContents()
				).map((t) => t.match(/Sort [ABC]/)?.[0]),
			)
			.toEqual(['Sort C', 'Sort B', 'Sort A'])
	})

	// @e2e exclude No canonical scenario covers multi-select bulk actions on the
	// Documents tab. documents-on-the-case task 2.2 (option b) governs it.
	test('selecting rows and marking them final applies to every selected document', async ({
		page,
	}) => {
		const panel = await openDocumentsTab(page, bulkCaseId)
		const bulkGroup = group(panel, OBJECTION_TYPE_NAME)
		const rows = bulkGroup.locator('[data-testid="cn-object-row"]')
		await expect(rows).toHaveCount(2, { timeout: 20_000 })

		await expect(
			panel.locator('[data-testid="object-list-bulk-bar"]'),
		).toHaveCount(0)

		for (let i = 0; i < 2; i++) {
			// CnDataTable's select column is an NcCheckboxRadioSwitch, whose
			// styled `<span class="checkbox-content …">` sits over the real
			// input and swallows the click: Playwright finds the input,
			// calls it visible, enabled and stable, and retries against
			// "intercepts pointer events" for the whole budget. `tickCheckbox`
			// clicks the label a person clicks, and its doc comment says why
			// `force: true` is refused rather than used here.
			await tickCheckbox(
				rows.nth(i).locator('.cn-table-col--checkbox').getByRole('checkbox'),
			)
		}

		const bulkBar = panel.locator('[data-testid="object-list-bulk-bar"]')
		await expect(bulkBar).toBeVisible({ timeout: 10_000 })
		await bulkBar.getByText(/2/).first().waitFor()

		await bulkBar
			.locator('[data-testid="object-list-bulk-action"]')
			.filter({ hasText: /Mark final/i })
			.click()

		const dialog = page.locator('[data-testid="bulk-document-dialog"]')
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await dialog.locator('[data-testid="bulk-document-confirm"]').click()
		await expect(
			dialog.locator('[data-testid="bulk-document-results"]'),
		).toBeVisible({ timeout: 20_000 })

		await expect(async () => {
			const stored = await listObjects(api, 'informatieobject', {
				_limit: '200',
			})
			const bulkOne = stored.find(
				(r) => String(r.title) === `${RUN_PREFIX} Bulk one`,
			)
			const bulkTwo = stored.find(
				(r) => String(r.title) === `${RUN_PREFIX} Bulk two`,
			)
			expect(bulkOne?.status, 'Bulk one must be final').toBe('final')
			expect(bulkTwo?.status, 'Bulk two must be final').toBe('final')
		}).toPass({ timeout: 20_000 })
	})

	// @e2e openspec/specs/document-zaakdossier/spec.md#scenario-req-zak-006b-restore-is-disabled-for-definitief-documents
	test('Versions on a row opens the panel, and restore is refused on a final document', async ({
		page,
	}) => {
		// THE VERSIONS ARE REAL. `seedVersionedDocument()` uploads through the
		// product's own endpoint and writes the file a second time, so the
		// panel below has something to list — a plain `seedDocument()` stamps
		// no `fileId` at all, which renders the identical "No previous
		// versions" text a failed PROPFIND and a panel with no buttons also
		// produce (dossiq#2477).
		const panel = await openDocumentsTab(page, versionCaseId)
		const finalGroup = group(panel, ACKNOWLEDGEMENT_TYPE_NAME)
		const row = finalGroup
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: `${RUN_PREFIX} Final report` })
		await expect(row).toHaveCount(1, { timeout: 20_000 })

		await clickRowAction(row, 'cn-action-item-versions')

		const versionPanel = page.locator('.dossier-version-panel')
		await expect(versionPanel).toBeVisible({ timeout: 20_000 })

		const entries = versionPanel.locator('.dossier-version-panel__item')

		// THE LIST HAS TO BE THERE BEFORE IT CAN BE COUNTED. The panel mounts
		// with a spinner and fills in when its PROPFIND lands, so
		// `toBeVisible()` above is satisfied by the EMPTY moment; a plain
		// `.count()` read there returns 0 and never looks again. This is also
		// the precondition the rest depends on: looping over
		// `restore.count()` alone lets a panel rendering zero Restore buttons
		// run the body zero times and report green — "the button MUST be
		// disabled on every version" satisfied by there being no button to
		// disable at all.
		await expect(
			entries,
			'the version panel must list at least one version, or there is nothing to refuse restoring',
		).not.toHaveCount(0, { timeout: 20_000 })
		const versionCount = await entries.count()

		// EVERY listed version offers a download — asserted as an invariant,
		// not a fixed number, since how many previous versions exist depends
		// on Nextcloud's own file-versions retention.
		await expect(
			versionPanel.locator(
				'.dossier-version-panel__item button:has-text("Download")',
			),
			'every listed version must offer a Download control',
		).toHaveCount(versionCount)

		// The half that is fully deterministic: the document is final, the
		// server refuses to change it, so the UI must not offer to. Every
		// version offers a Restore control (hiding it instead of disabling it
		// would redden here, same as a panel that lists versions but paints
		// no actions), and every one of them is refused.
		const restore = versionPanel.getByRole('button', {
			name: /Restore|Herstellen/,
		})
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

		const stored = await showObject(api, 'informatieobject', versionedDocumentId)
		expect(String(stored.status)).toBe('final')
	})

	// @e2e exclude No canonical scenario covers the Generate document picker listing
	// the library. REQ-005 "The picker lists the library" governs it and lives in the
	// open change documents-on-the-case.
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

		await expect(
			page.getByRole('option').filter({ hasText: 'Ontvangstbevestiging' }),
		).toHaveCount(1, { timeout: 20_000 })
		await expect(
			page.getByRole('option').filter({ hasText: 'Verdagingsbrief' }),
		).toHaveCount(1)
	})

	// @e2e exclude No canonical scenario covers generating a letter onto the
	// Documents tab. REQ-BES-012 governs it and lives in the open change
	// documents-on-the-case; canonical REQ-BES-001 is a different flow.
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
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: 'Ontvangstbevestiging' })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		// The letter is outgoing, and `stored.direction` above is where this
		// test checks that. The Direction CELL still reads `outgoing` rather
		// than Outgoing; that defect is asserted once, in the parked
		// Status-and-Direction test near the top of this file.
	})
})
