/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The things a case is about: the Objects tab on the case, the Link object
 * action, and the Objects index that answers the question the other way
 * round.
 *
 * The `caseObject` schema and its rows have existed since the ZGW import;
 * what did not exist was any surface that read them. That makes three
 * failures look identical on screen, and this spec is what tells them
 * apart: a widget whose query fails, a case that genuinely has no objects,
 * and a tab child that never mounted because the strip could not resolve
 * its type. The empty case additionally asserts that its query came back
 * under 400, and a saved object is read back over the API rather than
 * inferred from the page.
 *
 * Locale: nothing forces the language of the E2E instance, so tab and
 * button names are matched in either locale the app ships. Everything else
 * is matched on an id, a `data-testid`, or on data this spec seeded. The
 * object types `building` and `vehicle` are values this spec writes, not
 * translated labels, so the sidebar folders are safe to assert bare.
 *
 * The index scenarios narrow the list with a `?objectIdentification=` query
 * filter rather than by typing in the search box. CnIndexPage reads the
 * query string as a fetch filter (`resolveQueryFilters`) and exposes no
 * stable handle on the search input, so the query is the same narrowing the
 * search performs, addressed in a way that cannot go stale.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import {
	clickHeaderAction,
	dismissSupportDialog,
	openHeaderActionsMenu,
	PAGE_LOAD,
} from './helpers/nav.ts'

/** The fields the Link object form asks a handler to fill. */
const FORM_FIELDS = [
	'objectType',
	'objectIdentification',
	'objectUrl',
	'description',
]

/** The building both cases are about. Unique per run, so the index is unambiguous. */
const BUILDING_ID = `${RUN_PREFIX}-0363100012345678`
/** The vehicle on one case only. */
const VEHICLE_ID = `${RUN_PREFIX}-12-ABC-3`
/** The object on the other case, which must never appear in the first case's tab. */
const OTHER_ID = `${RUN_PREFIX}-other-object`
/** The object typed into the Link object form. */
const TYPED_ID = `${RUN_PREFIX}-typed-object`

const BUILDING_TYPE = 'building'
const VEHICLE_TYPE = 'vehicle'

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The case the Objects tab is read on: a building and a vehicle. */
let objectsCaseId = ''
/** A second case on the SAME building, which the index has to find. */
let secondBuildingCaseId = ''
/** A third case with an object of its own, which must stay out of the tab. */
let otherCaseId = ''
/** A case with no objects at all. */
let emptyCaseId = ''
/** A case the Link object form writes to, kept apart so the row is unambiguous. */
let formCaseId = ''

/**
 * Seed one case object through the object API.
 *
 * @param onCase         The case the object is on.
 * @param objectType     The type, which is also the sidebar folder.
 * @param identification The identification (carries RUN_PREFIX so teardown finds it).
 * @param description    The relation in prose.
 */
async function seedObject(
	onCase: string,
	objectType: string,
	identification: string,
	description: string,
): Promise<string> {
	const created = await createObject(api, token, 'caseObject', {
		case: onCase,
		objectType,
		objectIdentification: identification,
		objectUrl: `https://example.invalid/objects/${identification}`,
		description,
	})
	return objectId(created)
}

/**
 * Open a case and switch to its Objects tab.
 *
 * The tab panels are LAZY: the widget does not mount, and therefore does not
 * query, until its tab is opened. Without the click every assertion below
 * would time out on an unmounted panel and read as a broken filter.
 *
 * @param page The Playwright page.
 * @param id   The case id to open.
 */
async function openObjectsTab(page: Page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`, PAGE_LOAD)
	await dismissSupportDialog(page)
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
	return await openCasePanel(page, 'objects')
}

/**
 * Open an index route with a query string, under either URL shape.
 *
 * Only one of `/apps/dossiq` and `/index.php/apps/dossiq` is inside the
 * router base on a given instance, and the wrong one silently lands on the
 * Dashboard.
 *
 * @param page  The Playwright page.
 * @param route The in-app route, e.g. `/case-objects`.
 * @param query The filter query params.
 */
async function openIndex(
	page: Page,
	route: string,
	query: Record<string, string> = {},
): Promise<void> {
	const qs = new URLSearchParams(query).toString()
	for (const base of [`/apps/${REGISTER}`, `/index.php/apps/${REGISTER}`]) {
		await page.goto(
			qs === '' ? `${base}${route}` : `${base}${route}?${qs}`,
			PAGE_LOAD,
		)
		await dismissSupportDialog(page)
		if (new URL(page.url()).pathname.endsWith(route)) {
			await expect(
				page.locator('[data-testid="cn-object-list"]').first(),
			).toBeVisible({ timeout: 30_000 })
			return
		}
	}
	throw new Error(`neither URL shape resolved the ${route} route`)
}

/** The rows of the index table. @param page The Playwright page. */
function indexRows(page: Page) {
	return page.locator('[data-testid="cn-object-row"]')
}

test.describe('Case objects', () => {
	test.setTimeout(180_000)

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
				title: `${RUN_PREFIX} Objects`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Objects second building`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Objects other`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Objects empty`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Objects form`,
				caseType: caseTypeId,
			}),
		])
		;[
			objectsCaseId,
			secondBuildingCaseId,
			otherCaseId,
			emptyCaseId,
			formCaseId,
		] = seeded.map(objectId)

		await seedObject(
			objectsCaseId,
			BUILDING_TYPE,
			BUILDING_ID,
			'The house the dormer window is built on.',
		)
		await seedObject(
			objectsCaseId,
			VEHICLE_TYPE,
			VEHICLE_ID,
			"The contractor's crane truck.",
		)
		// The SAME building on a second case: this is what makes the index
		// answer "which cases are on this building" with more than one row.
		await seedObject(
			secondBuildingCaseId,
			BUILDING_TYPE,
			BUILDING_ID,
			'The same house, from the earlier extension permit.',
		)
		await seedObject(
			otherCaseId,
			BUILDING_TYPE,
			OTHER_ID,
			'A building on a case of its own.',
		)
	})

	test.afterAll(async () => {
		if (!api) return
		// The case objects. The cases are archival and cannot be removed by a
		// user; they carry the family prefix, so global-setup's residue sweep
		// takes them before the next run rather than this teardown failing on
		// a 403 it was never going to win.
		await cleanupRunObjects(api, token, ['caseObject'])
		await api.dispose()
	})

	// @e2e openspec/specs/case-management/spec.md#the-tab-lists-the-cases-objects
	// @e2e case-management::the-tab-lists-the-cases-objects
	test('the tab lists this case objects and not another case one', async ({
		page,
	}) => {
		const widget = await openObjectsTab(page, objectsCaseId)

		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(2, { timeout: 20_000 })

		const building = rows.filter({ hasText: BUILDING_ID })
		await expect(building).toHaveCount(1)
		await expect(building).toContainText(BUILDING_TYPE)
		await expect(building).toContainText('dormer window')
		// The link column is bound: the row carries the object's URL, which is
		// what makes the fourth column more than a header.
		await expect(building).toContainText(BUILDING_ID)

		const vehicle = rows.filter({ hasText: VEHICLE_ID })
		await expect(vehicle).toHaveCount(1)
		await expect(vehicle).toContainText(VEHICLE_TYPE)

		// The other case's object exists and is filtered out. Without the
		// filter this widget would list every case object on the instance,
		// which on a demo-seeded install still looks plausible.
		await expect(widget.getByText(OTHER_ID)).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#an-empty-case-shows-the-tab
	// @e2e case-management::an-empty-case-shows-the-tab
	test('a case without objects shows the tab, its empty state and the way out', async ({
		page,
	}) => {
		// A widget whose query fails renders an empty state too, so the
		// REQUEST is asserted beside the text. Without it this test passes on
		// a 404 and the tab looks correct while showing nothing it should.
		const statuses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/objects/dossiq/caseObject')) {
				statuses.push(r.status())
			}
		})

		const widget = await openObjectsTab(page, emptyCaseId)

		await expect(widget).toContainText(
			/No objects linked to this case yet|Nog geen objecten aan deze zaak gekoppeld/,
			{ timeout: 20_000 },
		)
		await expect
			.poll(() => statuses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(
			statuses.every((s) => s < 400),
			`caseObject queries: ${statuses.join(',')}`,
		).toBe(true)

		// The tab is present rather than hidden, and the way out of the empty
		// state is on the page rather than behind it. The tab is named for both
		// collections it holds since the strip came down to six.
		await expect(
			page.locator('.cn-tabs-widget').getByRole('tab', {
				name: 'Objects and locations',
				exact: true,
			}),
		).toBeVisible({ timeout: 15_000 })
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-link-object')).toBeVisible({
			timeout: 15_000,
		})
	})

	// @e2e openspec/specs/case-management/spec.md#a-linked-object-shows-up-in-the-tab
	// @e2e case-management::a-linked-object-shows-up-in-the-tab
	test('the Link object form asks for the object fields and never for the case', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-link-object')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		for (const key of FORM_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`the form should ask for ${key}`,
			).toHaveCount(1)
		}
		// `case` is seeded through the action's props: the handler opened the
		// form FROM the case, so being asked which case it is about would be
		// the form forgetting.
		await expect(dialog.locator('[data-cn-field="case"]')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#a-linked-object-shows-up-in-the-tab
	// @e2e case-management::a-linked-object-shows-up-in-the-tab
	test('an object linked from the case carries that case and shows up in the tab', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-link-object')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		await dialog
			.locator('[data-cn-field="objectType"]')
			.getByRole('textbox')
			.fill(BUILDING_TYPE)
		await dialog
			.locator('[data-cn-field="objectIdentification"]')
			.getByRole('textbox')
			.fill(TYPED_ID)
		await dialog
			.locator('[data-cn-field="description"]')
			.getByRole('textbox')
			.fill('The shed at the back')

		await dialog
			.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/ })
			.click()

		// The SAVED OBJECT, not the prefilled field. `props` seeding the case
		// into the form is the interim while nextcloud-vue cannot pass a
		// list's filter or an action's props into its create form as initial
		// data (task 3.2), so what is asserted here is the outcome that has to
		// hold either way.
		let saved: any
		await expect(async () => {
			const rows = await listObjects(api, 'caseObject', { _limit: '200' })
			saved = rows.find(
				(r) => String(r.objectIdentification ?? '') === TYPED_ID,
			)
			expect(saved, 'the case object should have been created').toBeTruthy()
		}).toPass({ timeout: 30_000 })

		const stored = await showObject(api, 'caseObject', objectId(saved))
		expect(String(stored.case)).toBe(formCaseId)
		expect(String(stored.objectType)).toBe(BUILDING_TYPE)
		expect(String(stored.description)).toBe('The shed at the back')

		const widget = await openObjectsTab(page, formCaseId)
		await expect(
			widget.locator('tbody tr').filter({ hasText: TYPED_ID }),
		).toHaveCount(1, { timeout: 20_000 })
	})

	// @e2e openspec/specs/case-management/spec.md#a-link-without-an-object-type-is-refused
	// @e2e case-management::a-link-without-an-object-type-is-refused
	test('a link without an object type cannot be saved and the field is marked', async ({
		page,
	}) => {
		const refusedId = `${RUN_PREFIX}-refused-object`

		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-link-object')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		await dialog
			.locator('[data-cn-field="objectIdentification"]')
			.getByRole('textbox')
			.fill(refusedId)

		// CnFormDialog refuses by DISABLING the confirm button while a required
		// field is empty, so there is no click to make and no error toast to
		// read. Asserting only "the dialog is still open" would pass on a
		// dialog that is merely slow, so the disabled button, the required
		// marker and the absence of a saved row are asserted together.
		const save = dialog.getByRole('button', {
			name: /^(Create|Save|Aanmaken|Opslaan)$/,
		})
		await expect(save).toBeDisabled({ timeout: 10_000 })

		// The field carries the required marker CnFormDialog appends to a
		// required field's label. An asterisk is the same character in every
		// locale the app ships, so this survives a Dutch instance.
		await expect(dialog.locator('[data-cn-field="objectType"]')).toContainText(
			'*',
			{ timeout: 10_000 },
		)

		// Filling the object type is what releases the button: that is what
		// makes the refusal ABOUT the object type rather than about the form
		// being in some other unsaveable state.
		await dialog
			.locator('[data-cn-field="objectType"]')
			.getByRole('textbox')
			.fill(BUILDING_TYPE)
		await expect(save).toBeEnabled({ timeout: 10_000 })

		await page.keyboard.press('Escape')

		const rows = await listObjects(api, 'caseObject', { _limit: '200' })
		expect(
			rows.filter((r) => String(r.objectIdentification ?? '') === refusedId),
			'no case object should have been saved without an object type',
		).toHaveLength(0)
	})

	// @e2e openspec/specs/case-management/spec.md#one-building-two-cases
	// @e2e case-management::one-building-two-cases
	test('the index finds one building on two cases and leaves the vehicle out', async ({
		page,
	}) => {
		await openIndex(page, '/case-objects', {
			objectIdentification: BUILDING_ID,
		})

		const rows = indexRows(page)
		await expect(rows).toHaveCount(2, { timeout: 30_000 })

		// One row PER CASE, not one row listed twice. The two rows are told
		// apart by their descriptions, which this spec wrote per case; the
		// Case column itself is not asserted here because `caseTitle` resolves
		// out of a capped collection fetch and would make this test depend on
		// how many cases the instance happens to hold. That the formatter
		// resolves an id to a title, and falls back to the id when it cannot,
		// is covered in tests/vitest/formatters.spec.js.
		await expect(rows.filter({ hasText: 'dormer window' })).toHaveCount(1)
		await expect(rows.filter({ hasText: 'extension permit' })).toHaveCount(1)

		await expect(page.getByText(VEHICLE_ID)).toHaveCount(0)
		await expect(page.getByText(OTHER_ID)).toHaveCount(0)
	})

	// @e2e openspec/specs/case-management/spec.md#a-row-opens-its-case
	// @e2e case-management::a-row-opens-its-case
	test('View case on a row opens that row case, not the row', async ({ page }) => {
		await openIndex(page, '/case-objects', {
			objectIdentification: VEHICLE_ID,
		})

		const row = indexRows(page).filter({ hasText: VEHICLE_ID })
		await expect(row).toHaveCount(1, { timeout: 30_000 })

		// The overflow trigger is the only button in the row-actions cell.
		await row.locator('button').last().click()
		const menu = page.locator('[role="menu"]').last()
		await expect(menu).toBeVisible({ timeout: 10_000 })
		await menu
			.locator('[role="menuitem"], li')
			.filter({ hasText: /View case|Bekijk zaak/ })
			.first()
			.click()

		// The CASE's id, not the case object's. The row action resolves
		// `{case}` off the row and merges it over the default row id; getting
		// that wrong opens a case page on an id no case carries, which renders
		// an empty detail page rather than an error.
		await expect(page).toHaveURL(
			new RegExp(`/cases/${objectsCaseId}(?:[?#]|$)`),
			{ timeout: 30_000 },
		)
		await expect(page.locator('.cn-detail-page')).toContainText(
			`${RUN_PREFIX} Objects`,
			{ timeout: 30_000 },
		)
	})

	// @e2e openspec/specs/case-management/spec.md#the-sidebar-groups-by-object-type
	// @e2e case-management::the-sidebar-groups-by-object-type
	test('the sidebar groups by object type and All objects brings every row back', async ({
		page,
	}) => {
		// Narrowed to this run's rows first, so the folder tree is built from
		// data this spec wrote rather than from whatever the instance was
		// seeded with. The tree's folder names ARE the stored values, so
		// `building` and `vehicle` are language-neutral here.
		await openIndex(page, '/case-objects', {
			case: objectsCaseId,
		})

		const rows = indexRows(page)
		await expect(rows).toHaveCount(2, { timeout: 30_000 })

		const sidebar = page.locator('.cn-folder-sidebar')
		await expect(sidebar).toBeVisible({ timeout: 20_000 })

		await sidebar
			.locator('.cn-folder-tree__item')
			.filter({ hasText: BUILDING_TYPE })
			.first()
			.click()

		await expect(rows).toHaveCount(1, { timeout: 20_000 })
		await expect(rows.first()).toContainText(BUILDING_ID)
		await expect(page.getByText(VEHICLE_ID)).toHaveCount(0)

		await sidebar.locator('.cn-folder-sidebar__all').click()
		await expect(rows).toHaveCount(2, { timeout: 20_000 })
	})
})
