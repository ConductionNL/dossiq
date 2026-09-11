/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What identifies a case: its number, its tags and its statutory fields
 * (case-identity, REQ-CM-25 / 26 / 27).
 *
 * Three things about this file change what an assertion here can honestly
 * claim, and all three are worth reading before the tests.
 *
 * ONE. The number is not written by dossiq on the happy path. `identifier`
 * carries an `x-openregister-calculations` entry using OpenRegister's
 * `sequence` operator, and `CaseNumberListener` only fills the field when the
 * register left it empty. So the API scenario asserts the SHAPE and the YEAR,
 * never a literal number: an instance that already holds cases has a sequence
 * part nobody here chose. The form scenario asserts the number is higher than
 * every number of that year the list held before the click, which is the
 * "next" the requirement is really about and does not break when a sibling
 * spec files a case at the same moment.
 *
 * TWO. The tags editor is the `data` built-in in a sidebar tab. It edits in
 * place: a cell is clicked (`role="button"`, `aria-label="Click to edit …"` —
 * an English literal from the library, not a translated string), the value is
 * confirmed on the field, and the widget is then saved. The whole chain is
 * driven here rather than shortcut through the API, because the point of
 * REQ-CM-26 is that a person can add a tag on the case page.
 *
 * THREE. `statutoryTerm` is asserted over the API BEFORE it is asserted on
 * screen. It is materialised from `@ref.caseType.processingDeadline` at save
 * time, so if the calculation did not run the register holds nothing and the
 * page is right to show nothing — an on-screen assertion alone would blame
 * the widget for the register's silence.
 *
 * Locale: nothing forces the language of the instance under test, so every
 * label is matched in either language the app ships. Everything else is
 * matched on an id, a `data-testid` or on data this spec seeded.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

/** The processing deadline this run's case type declares. */
const PROCESSING_DEADLINE = 'P56D'

/** The case type every case in this spec is filed under. */
const CASE_TYPE_TITLE = `${RUN_PREFIX} Identity type`

/**
 * The tag the filter scenario narrows on.
 *
 * Run-scoped rather than the demo seed's literal `wijk-noord`: the instance
 * under test may or may not carry the English demo dataset, and a filter
 * assertion that counts rows must own every row it counts.
 */
const FILTER_TAG = `${RUN_PREFIX}-wijk-noord`

/** The tag the sidebar scenario types. */
const TYPED_TAG = 'spoed'

/** The legal basis and archive data the terms block reads. */
const LEGAL_BASIS = 'Awb 4:13'
const ARCHIVE_NOMINATION = 'blijvend_bewaren'

/** The identifier the "an existing number stays" scenario protects. */
const LEGACY_IDENTIFIER = 'BZW-2025-17'

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The case the Tags tab is driven on. */
let tagCaseId = ''
/** The case the terms and archive block is read on. */
let termsCaseId = ''
/** The case whose number must survive an edit. */
let legacyCaseId = ''

/**
 * The sequence half of a `YYYY-NNNN` identifier, or -1 when it is not one.
 *
 * @param identifier The identifier to read.
 * @param year       The year the number must belong to.
 */
function sequenceOf(identifier: unknown, year: string): number {
	const match = /^(\d{4})-(\d+)$/.exec(String(identifier ?? ''))
	if (match === null || match[1] !== year) {
		return -1
	}
	return Number(match[2])
}

/**
 * The highest number handed out this year, across every case on the instance.
 *
 * @param year The four-digit year.
 */
async function highestNumberOfYear(year: string): Promise<number> {
	const cases = await listObjects(api, 'case', { _limit: '1000' })
	return cases.reduce(
		(highest: number, row: any) =>
			Math.max(highest, sequenceOf(row.identifier, year)),
		-1,
	)
}

/**
 * Open the case page and wait for the detail shell.
 *
 * @param page   The Playwright page.
 * @param caseId The case to open.
 */
async function openCase(page: Page, caseId: string): Promise<void> {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })
}

/**
 * Open the case sidebar and return it.
 *
 * @param page The Playwright page.
 */
async function openSidebar(page: Page): Promise<Locator> {
	const toggle = page.locator('.app-sidebar__toggle')
	if (await toggle.isVisible()) await toggle.click()
	const sidebar = page.locator('.app-sidebar')
	await expect(sidebar).toBeVisible({ timeout: 15_000 })
	return sidebar
}

test.describe('Case identity', () => {
	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		// This run's own case type, so the statutory lead time on screen is a
		// number this spec chose rather than whatever the instance happened to
		// be seeded with.
		const caseType = await createObject(api, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			identifier: `${RUN_PREFIX.toLowerCase()}-identity-type`,
			description: 'Throwaway case type for the case-identity spec.',
			processingDeadline: PROCESSING_DEADLINE,
			// PUBLISHED, NOT DRAFT. `case.caseType` carries
			// `x-relation-filter: {isDraft: false}` and the caseType schema
			// defaults `isDraft` to TRUE, so a type seeded without this is a
			// draft and never appears in the New case picker. The failure reads
			// as a missing option, not as a draft.
			isDraft: false,
		})
		caseTypeId = objectId(caseType)

		tagCaseId = objectId(
			await createObject(api, token, 'case', {
				title: `${RUN_PREFIX} untagged case`,
				caseType: caseTypeId,
				startDate: '2026-03-01',
			}),
		)

		termsCaseId = objectId(
			await createObject(api, token, 'case', {
				title: `${RUN_PREFIX} statutory case`,
				caseType: caseTypeId,
				startDate: '2026-03-02',
				legalBasis: LEGAL_BASIS,
				archiveNomination: ARCHIVE_NOMINATION,
				// archiveActionDate is deliberately absent: REQ-CM-27's second
				// scenario is that the row is there and empty, not hidden. That
				// scenario is currently RED and its test is parked on
				// nextcloud-vue#1062, so keep this absent — it is the fixture
				// the test needs the day the row comes back.
			}),
		)

		legacyCaseId = objectId(
			await createObject(api, token, 'case', {
				title: `${RUN_PREFIX} legacy numbered case`,
				caseType: caseTypeId,
				startDate: '2025-11-01',
				identifier: LEGACY_IDENTIFIER,
			}),
		)

		for (const suffix of ['one', 'two']) {
			await createObject(api, token, 'case', {
				title: `${RUN_PREFIX} tagged case ${suffix}`,
				caseType: caseTypeId,
				startDate: '2026-03-03',
				tags: [FILTER_TAG],
			})
		}
		// Three cases of the same type WITHOUT the tag, so a filter that does
		// nothing at all cannot pass this spec.
		for (const suffix of ['one', 'two', 'three']) {
			await createObject(api, token, 'case', {
				title: `${RUN_PREFIX} plain case ${suffix}`,
				caseType: caseTypeId,
				startDate: '2026-03-04',
			})
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/case-management/spec.md
	test('a case posted to the API is numbered in the year of its start date', async () => {
		const filed = await createObject(api, token, 'case', {
			title: `${RUN_PREFIX} api filed case`,
			caseType: caseTypeId,
			startDate: '2026-07-15',
		})

		const stored = await showObject(api, 'case', objectId(filed))
		expect(
			String(stored.identifier ?? ''),
			'a case posted without an identifier must come back with one',
		).toMatch(/^\d{4}-\d{4}$/)
		expect(
			String(stored.identifier).slice(0, 4),
			'the year half is the year of the start date',
		).toBe('2026')
	})

	// @e2e openspec/specs/case-management/spec.md
	test('an existing number survives an edit to the case', async () => {
		await updateObject(api, token, 'case', legacyCaseId, {
			title: `${RUN_PREFIX} legacy numbered case, retitled`,
		})

		const stored = await showObject(api, 'case', legacyCaseId)
		expect(stored.identifier).toBe(LEGACY_IDENTIFIER)
	})

	// @e2e openspec/specs/case-management/spec.md
	test('the New case form asks for no number, and the case gets the next one', async ({
		page,
	}) => {
		const before = await highestNumberOfYear(String(new Date().getFullYear()))

		await page.goto(`/apps/${REGISTER}/`)
		await expect(page).not.toHaveURL(/login/, { timeout: 15_000 })
		await page.getByRole('button', { name: 'New case', exact: true }).click()

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// The requirement's second clause: the form never offers a number.
		await expect(
			dialog.locator('[data-cn-field="identifier"]'),
			'a generated number is not something a handler types',
		).toHaveCount(0)

		const title = `${RUN_PREFIX} filed from the form`
		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(title)

		const combo = dialog.getByRole('combobox', { name: /Case type|Zaaktype/ })
		await combo.click()
		// By TEXT, not by accessible name: vue-select splits an option label
		// across spans and the name computation rejoins them with a space, so
		// an exact name match never hits a label carrying RUN_PREFIX.
		const option = page.getByRole('option').filter({ hasText: CASE_TYPE_TITLE })
		if (!(await option.isVisible().catch(() => false))) {
			await combo.pressSequentially(RUN_PREFIX, { delay: 30 })
		}
		await expect(option).toBeVisible({ timeout: 20_000 })
		await option.click()

		await dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ }).click()
		await expect(page).toHaveURL(/\/cases\/[^/?#]+$/, { timeout: 30_000 })

		const filed = (await listObjects(api, 'case', { title })).find(
			(row: any) => row.title === title,
		)
		expect(filed, 'the form should have filed a case').toBeTruthy()

		const year = String(filed.startDate ?? '').slice(0, 4)
		const sequence = sequenceOf(filed.identifier, year)
		expect(sequence, `identifier was ${filed.identifier}`).toBeGreaterThan(-1)
		expect(
			sequence,
			'the new case must take a number no case of this year already holds',
		).toBeGreaterThan(before)

		// And it is on the page, in the core widget, which is the half that was
		// broken: `identifier` is schema-readOnly, and a data widget drops a
		// readOnly property unless an override re-admits it. The widget is the
		// `Data` tab of the case strip now, not a laid-out widget, so it
		// carries no `aria-label` of its own.
		await expect(
			await openCasePanel(page, 'data'),
			'the case page shows the number it was given',
		).toContainText(String(filed.identifier), { timeout: 20_000 })
	})

	// @e2e openspec/specs/case-management/spec.md
	test('a tag added in the sidebar is on the case after a reload', async ({
		page,
	}) => {
		await openCase(page, tagCaseId)
		const sidebar = await openSidebar(page)

		// By id, not by label: NcAppSidebarTab renders `#tab-button-<id>` for
		// the manifest tab id, which is the same in every language.
		await sidebar.locator('#tab-button-tags').click()
		const panel = sidebar.locator('[data-testid="cn-object-sidebar-tab-tags"]')
		await expect(panel).toBeVisible({ timeout: 15_000 })

		// A `type: "custom"` widget in a sidebar tab resolves to nothing and
		// renders an empty panel, so the panel having CONTENT is the assertion
		// that tells a working tab from a declared one.
		const cell = panel.locator('.cn-object-data-widget__cell')
		await expect(cell).toHaveCount(1)

		await cell.getByRole('button', { name: /Click to edit/ }).click()
		const editor = panel.locator('.cn-object-data-widget__editor')
		await expect(editor).toBeVisible({ timeout: 15_000 })

		// NcSelect in taggable mode: type the word, then Enter mints it.
		await editor.getByRole('combobox').first().fill(TYPED_TAG)
		await page.keyboard.press('Enter')

		// Then CLOSE the dropdown, which minting the tag does not do. vue-select
		// keeps its menu open after Enter and, the search term having matched
		// nothing, renders `<li class="vs__no-options">No results</li>` in a
		// floating list positioned over the editor's own buttons. Playwright
		// reported the Confirm button as visible, enabled and stable and then
		// spent the whole 15s budget being told `vs__no-options … intercepts
		// pointer events` — a covered control, which reads exactly like a
		// control that is not there.
		await page.keyboard.press('Escape')
		await expect(
			page.locator('.vs__dropdown-menu'),
			'the tag dropdown still covers the editor actions',
		).toHaveCount(0, { timeout: 15_000 })

		// Confirming the field IS the save. CnObjectDataWidget's `commitEdit`
		// stages the value and then awaits its own `save()`, so a click-to-edit
		// confirm writes in one step; the header Save exists only for edits that
		// queue without a per-field confirm.
		//
		// This test used to click that header Save afterwards and could not
		// pass. `save()` sets `saving` before the PUT and clears `dirtyFields`
		// after it, and the header button is `v-if="isDirty" :disabled="saving"`
		// — so it exists ONLY while the write is in flight, and is disabled for
		// every millisecond of that. Playwright resolved it, spent the whole
		// budget on "element is not enabled", and then reported it detached.
		// Measured on the CI trace of the failing run: one
		// `PUT /apps/openregister/api/objects/dossiq/case/<id>` with
		// `{"tags":["spoed"]}`, 200, response body carrying the tag — sent 15s
		// BEFORE the click that failed. The write worked; the click was for a
		// second step the widget does not have.
		await panel
			.locator('.cn-object-data-widget__editor-actions button')
			.first()
			.click()

		// Assert the single step out loud rather than deleting the line: the
		// widget must be left with nothing staged. A revert to a two-step save
		// would leave a Save button standing here and redden this, which is the
		// signal a bare deletion would throw away.
		await expect(
			panel.getByRole('button', { name: /^(Save|Opslaan)$/ }),
			'confirming the field left unsaved state behind: the widget no longer saves in one step',
		).toHaveCount(0, { timeout: 20_000 })

		await expect
			.poll(
				async () => (await showObject(api, 'case', tagCaseId)).tags ?? [],
				{ timeout: 20_000 },
			)
			.toContain(TYPED_TAG)

		await page.reload()
		const reopened = await openSidebar(page)
		await reopened.locator('#tab-button-tags').click()
		await expect(
			reopened.locator('[data-testid="cn-object-sidebar-tab-tags"]'),
		).toContainText(TYPED_TAG, { timeout: 20_000 })
	})

	// @e2e openspec/specs/case-management/spec.md
	test('the Cases index filters on a tag, and offers the filter', async ({
		page,
	}) => {
		// The deep link is the same filter the sidebar applies — a non-underscore
		// query param is a field filter — so this asserts the narrowing rather
		// than the widget that requests it.
		await page.goto(
			`/apps/${REGISTER}/cases?tags=${encodeURIComponent(FILTER_TAG)}`,
		)
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })

		for (const suffix of ['one', 'two']) {
			await expect(
				page.getByText(`${RUN_PREFIX} tagged case ${suffix}`),
				'a case carrying the tag belongs in the filtered list',
			).toBeVisible({ timeout: 20_000 })
		}
		for (const suffix of ['one', 'two', 'three']) {
			await expect(
				page.getByText(`${RUN_PREFIX} plain case ${suffix}`),
				'an untagged case must not survive the filter',
			).toHaveCount(0)
		}

		// And the filter is reachable without hand-writing a URL: `tags` is
		// `facetable`, so the index sidebar builds a Tags control from the
		// schema.
		// 🔴 BY THE FILTER'S OWN CLASS, NOT BY TEXT. Three separate elements in
		// this sidebar say exactly "Tags", and a bare getByText is a strict
		// mode violation that reports "resolved to 3 elements" rather than
		// anything about the filter:
		//
		//   .cn-index-sidebar__filter-label   the filter label, what is meant
		//   label.select__label               that filter's own NcSelect label
		//   .checkbox-content__text           the Columns picker checkbox
		//
		// All three are correct, and they arrived together the moment `tags`
		// became facetable and gained a column. The assertion has to say which
		// one it means. `CnIndexSidebar.vue` renders the first as
		// `<span class="cn-index-sidebar__filter-label">{{ filter.label }}</span>`.
		const sidebar = await openSidebar(page)
		await expect(
			sidebar
				.locator('.cn-index-sidebar__filter-label')
				.filter({ hasText: /^(Tags|Labels)$/ }),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e openspec/specs/case-management/spec.md
	test('the case shows its lead time, legal basis and archive nomination', async ({
		page,
	}) => {
		// Asserted over the API first: `statutoryTerm` is materialised from the
		// case type at save time, so a blank on screen could be the widget or
		// could be an empty register, and only this tells them apart.
		const stored = await showObject(api, 'case', termsCaseId)
		expect(
			stored.statutoryTerm,
			'the lead time is copied from the case type at save time',
		).toBe(PROCESSING_DEADLINE)

		await openCase(page, termsCaseId)

		// The statutory half stayed where it was. A `data` widget builds its
		// fields from the schema's own properties, which is exactly what a
		// lead time and a legal basis are.
		const terms = page.locator('[aria-label="case-terms"]')
		await expect(terms).toBeVisible({ timeout: 30_000 })
		await expect(terms).toContainText(PROCESSING_DEADLINE)
		await expect(terms).toContainText(LEGAL_BASIS)

		// The archival half moved out into its own card (dossiq#2322). The
		// page no longer reads dossiq's `archiveNomination` off the record: it
		// reads the abstract decision OpenRegister resolves into
		// `@self._retention`, so the same card answers for any object. Two
		// consequences this assertion has to respect:
		//
		//  - the widget is a `metadata` one, so the block is `case-archival`
		//    and not `case-terms`;
		//  - the stored ZGW code is translated on the way out, so
		//    `blijvend_bewaren` arrives as the phrase an archivist would say.
		//    The raw code stays in the pattern because an unrecognised
		//    nomination falls back to it rather than being hidden.
		const archival = page.locator('[aria-label="case-archival"]')
		await expect(archival).toBeVisible({ timeout: 30_000 })
		await expect(archival).toContainText(/keep permanently|blijvend[ _]bewaren/i)
	})

	// @e2e openspec/specs/case-management/spec.md
	test.fixme('an empty archive action date is shown empty, not hidden', async ({
		page,
	}) => {
		// PARKED ON ConductionNL/nextcloud-vue#1062, and the requirement is
		// still right — it is the surface underneath it that cannot express it
		// any more.
		//
		// An absent destruction date is itself what a records officer looks
		// for, so the row has to be present and blank. While the archival
		// fields lived in the `case-terms` data widget, `hideEmpty: false`
		// said exactly that, and this test was the assertion that would catch
		// it being turned on.
		//
		// dossiq#2322 moved them onto `@self._retention` and
		// CnObjectMetadataWidget, which is the right move — it ends the
		// per-app duplication of ZGW field names — but the guarantee had
		// nowhere to land. Measured on the installed 2.44.0, both layers drop
		// the row and neither offers a way to ask for it:
		//
		//  - OpenRegister's ArchivalDecisionResolver returns only the keys it
		//    could establish, so this case's `_retention` is
		//    `{ nomination: 'blijvend_bewaren' }` with no `actionDate` key;
		//  - CnObjectMetadataWidget then does `if (raw === undefined || raw
		//    === null) continue` over its archival defs, and its props are
		//    title / icon / objectData / layout / columns / labelWidth /
		//    extraItems / include / exclude / collapsible / collapsed /
		//    emptyLabel. There is no showEmpty.
		//
		// So the card renders one row of the seven `case-archival` names, and
		// Archive action date is absent rather than blank. The body below is
		// kept and pointed at the new card so unfixming is one word once
		// #1062 lands a `showEmpty` (or honours an explicit `include`).
		await openCase(page, termsCaseId)

		const archival = page.locator('[aria-label="case-archival"]')
		await expect(archival).toBeVisible({ timeout: 30_000 })

		const row = archival
			.locator('.cn-detail-grid__item')
			.filter({ hasText: /Archive action date|Datum archiefactie/ })
		await expect(row).toHaveCount(1)
	})
})
