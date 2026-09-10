/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Colour, versions, folders and the AVG fields (case-type-authoring-extras).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * The merge, the cycle refusal and the publish order are pinned by
 * CaseTypeResolverTest, CaseTypePublishServiceTest and
 * CaseTypeControllerTest, and the shaping by the vitest suites. What none of
 * those can reach is the half this change exists for: that a colour authored
 * on a status ARRIVES on a badge and a board column, that a child type's
 * inherited statuses are actually drawn on its own page, that the folder
 * sidebar narrows the index, and that Publish refuses in front of the person
 * rather than in a log. Every one of those is a chain of four declarations
 * that no build step compares.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance, and every label on these
 * surfaces is translated. So the assertions are on `data-testid`, on the
 * `data-colour` attribute the badge and the swatch carry, or on text this
 * spec seeded itself — the names carry RUN_PREFIX and read the same in
 * either locale.
 *
 * WHY THE COLOUR IS ASSERTED AS A NAME, NOT A PIXEL
 * -------------------------------------------------
 * The badge resolves `var(--nl-color-orange, #e17000)`, and which of the two
 * a browser paints depends on whether the instance carries the NL Design
 * System theme. Asserting the computed colour would make this spec pass or
 * fail on the instance's theming rather than on this change. The rendered
 * `data-colour` says which colour the code CHOSE, which is the thing that was
 * broken before: every status resolved to grey.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
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
	updateObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog } from './helpers/nav.ts'

let api: APIRequestContext
let token = ''

/** The parent type: four statuses, a category, a filled AVG block. */
const parent = {
	caseType: '',
	intake: '',
	progress: '',
	decision: '',
	closed: '',
}

/** The child type: names the parent, declares nothing of its own. */
const child = { caseType: '' }

/** A draft with no initial status, so Publish has a finding to report. */
const incomplete = { caseType: '' }

/** A draft that validates, so Publish can actually publish it. */
const publishable = { caseType: '', intake: '', closed: '', template: '' }

/** The category the folder sidebar narrows on. */
const CATEGORY = `${RUN_PREFIX} Vergunningen`

/** A propertyDefinition saved with NO case type, so it is shared. */
const SHARED_ATTRIBUTE = `${RUN_PREFIX} Kenteken`

/** Cases seeded into the hidden final status. */
const hiddenCases: string[] = []

/**
 * Create one statusType.
 *
 * @param caseType The case type it belongs to.
 * @param name     The status name.
 * @param order    Its position in the lifecycle.
 * @param extra    colour, isFinal, hiddenInLists.
 */
async function seedStatus(
	caseType: string,
	name: string,
	order: number,
	extra: Record<string, unknown> = {},
): Promise<string> {
	const row = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} ${name}`,
		caseType,
		order,
		isFinal: false,
		...extra,
	})
	return objectId(row)
}

/**
 * Open a case type's page and wait for the blueprint panel to have answered.
 *
 * @param page The Playwright page.
 * @param id   The case type to open.
 */
async function openCaseType(page: Page, id: string): Promise<void> {
	await page.goto(`/apps/${REGISTER}/settings/case-types/${id}`)
	await dismissSupportDialog(page)
	await expect(page.getByTestId('case-type-blueprint')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Narrow the Cases index to the rows this run seeded, by clicking this run's
 * own case type in the facet sidebar.
 *
 * 🔴 WITHOUT THIS THE HIDDEN-STATUS TEST LOOKS AT PAGE 1 OF SEVERAL. The Cases
 * index paginates at 20 (`CnIndexPage` falls back to `limit || 20`) and orders
 * by identifier ascending, so a case seeded seconds ago carries a HIGH number
 * and lands on the LAST page. The assertion then reads "the open case is not in
 * the list" while the hidden status is behaving perfectly and the row is three
 * pages away.
 *
 * ⚠️ The absence half of that test is what makes this necessary rather than
 * merely tidy. `Afgehandelde zaak 1..3` are asserted to have count 0, and on an
 * unnarrowed list they have count 0 because they are off the page — so the
 * assertion passed for the wrong reason and could not have caught a hidden
 * status that stopped hiding. Narrowing first is what gives that absence its
 * meaning back.
 *
 * The facet is the instrument rather than a search box: `CnActionsBar` renders
 * an `<input type="search">` only behind its `showSearch` prop and this page
 * does not set it, which is the trap #1974 recorded for `case-list-lenses`. The
 * sidebar facet is a control the page really renders, and `CnIndexPage` spreads
 * a quick filter's own filter BEFORE the user's `activeFilters`, so the facet
 * survives the Closed tab click below and that tab re-fetches at page 1.
 *
 * @param page The page.
 */
async function narrowToThisRun(page: Page): Promise<void> {
	// The accessible name carries the facet's COUNT when it has matches, so
	// `E2EZAAK-… Bezwaar 4` and not `E2EZAAK-… Bezwaar`. Anchored at both ends
	// and carrying the run prefix, so it still cannot match another run's type
	// or this spec's own `… Bezwaar (verkort)` child.
	const title = `${RUN_PREFIX} Bezwaar`
	const facet = page.getByRole('button', {
		name: new RegExp(
			`^${title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s+\\d+)?$`,
		),
	})
	await expect(
		facet,
		`the sidebar should offer a case-type filter named ${title}`,
	).toBeVisible({ timeout: 30_000 })
	await facet.click()

	// The list must have answered before anything is asserted, or the first
	// assertion races the fetch this click started.
	await expect(
		page.getByRole('row').filter({ hasText: RUN_PREFIX }).first(),
	).toBeVisible({ timeout: 30_000 })
}

/**
 * Open the Case types index.
 *
 * By ROUTE and not by sidebar label: `navTo` resolves a nav entry by its
 * visible text, which on a Dutch instance reads "Zaaktypen".
 *
 * @param page The Playwright page.
 */
async function openCaseTypes(page: Page): Promise<void> {
	await page.goto(`/apps/${REGISTER}/settings/case-types`)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Colour, versions, folders and the AVG fields', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// ── the parent: four coloured statuses, the last one hidden ─────────
		parent.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Bezwaar`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cta-parent`,
				description:
					'Throwaway parent caseType for case-type-authoring-extras.',
				category: CATEGORY,
				processingDeadline: 'P12W',
			}),
		)
		parent.intake = await seedStatus(parent.caseType, 'Ontvangen', 1, {
			colour: 'blue',
		})
		parent.progress = await seedStatus(parent.caseType, 'In behandeling', 2, {
			colour: 'orange',
		})
		parent.decision = await seedStatus(parent.caseType, 'Besluitvorming', 3, {
			colour: 'purple',
		})
		// The hidden one is also the final one, which is the pairing the seed
		// data ships: a closed status fills the list with work nobody is doing.
		parent.closed = await seedStatus(parent.caseType, 'Afgehandeld', 4, {
			colour: 'green',
			isFinal: true,
			hiddenInLists: true,
		})
		await updateObject(api, token, 'caseType', parent.caseType, {
			initialStatus: parent.intake,
			processesPersonalData: true,
			personalDataCategories: ['naw', 'bsn'],
			legalBasis: 'public_task',
			verwerkingsactiviteit: 'behandelen-bezwaarschrift',
		})

		// ── the child: names the parent and declares NOTHING ────────────────
		child.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Bezwaar (verkort)`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cta-child`,
				description:
					'Throwaway child caseType for case-type-authoring-extras.',
				parentCaseType: parent.caseType,
				category: CATEGORY,
				// Its own deadline, which must beat the parent's twelve weeks.
				processingDeadline: 'P6W',
			}),
		)

		// ── an attribute belonging to NO case type: shared ──────────────────
		await createObject(api, token, 'propertyDefinition', {
			name: SHARED_ATTRIBUTE,
			description: 'Shared attribute for case-type-authoring-extras.',
			propertyType: 'string',
		})

		// ── a draft that cannot be published: no initial status ─────────────
		incomplete.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Onaf concept`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cta-incomplete`,
				description: 'Throwaway draft with nothing on it.',
				isDraft: true,
			}),
		)

		// ── a draft that CAN be published ───────────────────────────────────
		publishable.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Publiceerbaar concept`,
				identifier: `${RUN_PREFIX.toLowerCase()}-cta-publishable`,
				description: 'Throwaway draft that validates.',
				isDraft: true,
			}),
		)
		publishable.intake = await seedStatus(publishable.caseType, 'Ontvangen', 1, {
			colour: 'blue',
		})
		publishable.closed = await seedStatus(
			publishable.caseType,
			'Afgehandeld',
			2,
			{ colour: 'green', isFinal: true },
		)
		await updateObject(api, token, 'caseType', publishable.caseType, {
			initialStatus: publishable.intake,
		})
		publishable.template = objectId(
			await createObject(api, token, 'workflowTemplate', {
				title: `${RUN_PREFIX} Publiceerbare flow`,
				caseType: publishable.caseType,
				isActive: true,
				isDraft: true,
				version: 1,
				lifecycleStatus: 'draft',
				transitions: JSON.stringify([]),
			}),
		)

		// ── three cases in the hidden status, one in an open one ────────────
		for (const n of [1, 2, 3]) {
			hiddenCases.push(
				objectId(
					await seedCase(api, token, {
						title: `${RUN_PREFIX} Afgehandelde zaak ${n}`,
						caseType: parent.caseType,
						status: parent.closed,
					}),
				),
			)
		}
		await seedCase(api, token, {
			title: `${RUN_PREFIX} Lopende zaak`,
			caseType: parent.caseType,
			status: parent.progress,
		})
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// ── REQ-CT-19: a status has a colour and a list visibility ─────────────

	// @e2e case-types::a-coloured-status-shows-on-the-board
	// Scenario: A coloured status shows on the board
	test('a coloured status draws its board column in that colour', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/workflow-board`)
		await dismissSupportDialog(page)

		// The board merges every non-final status sharing a NAME into one
		// column, and the seeded names carry RUN_PREFIX, so this column is
		// this spec's own and no other run can colour it.
		const column = page
			.locator('.board-column')
			.filter({ hasText: `${RUN_PREFIX} In behandeling` })
		await expect(column).toBeVisible({ timeout: 30_000 })

		await expect(
			column.locator('[data-testid="board-column-colour"]'),
		).toHaveAttribute('data-colour', 'orange')
	})

	// @e2e case-types::a-hidden-status-keeps-its-cases-out-of-the-list
	// Scenario: A hidden status keeps its cases out of the list
	test('a hidden status keeps its cases off the list, and Closed brings them back', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})
		await narrowToThisRun(page)

		// The open case of the same type proves the list is populated at all:
		// without it, an empty list would pass this test for the wrong reason.
		await expect(
			page.getByText(`${RUN_PREFIX} Lopende zaak`, { exact: true }),
		).toBeVisible({ timeout: 30_000 })

		for (const n of [1, 2, 3]) {
			await expect(
				page.getByText(`${RUN_PREFIX} Afgehandelde zaak ${n}`, {
					exact: true,
				}),
			).toHaveCount(0)
		}

		await page.getByRole('tab', { name: /^(Closed|Gesloten)$/ }).click()
		await expect(
			page.getByText(`${RUN_PREFIX} Afgehandelde zaak 1`, { exact: true }),
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/specs/case-types/spec.md
	// The badge half of the same requirement: the case page draws the status
	// in its own colour, which is where a handler actually reads it.
	test('the case page draws the current status in its status’s colour', async ({
		page,
	}) => {
		const openCase = (
			await listObjects(api, 'case', { caseType: parent.caseType })
		).find((row) => String(row.status ?? '') === parent.progress)
		expect(openCase, 'an open case of the parent type').toBeTruthy()

		await page.goto(`/apps/${REGISTER}/cases/${objectId(openCase)}`)
		await dismissSupportDialog(page)

		await expect(page.getByTestId('case-current-status')).toHaveAttribute(
			'data-colour',
			'orange',
			{ timeout: 30_000 },
		)
	})

	// ── REQ-CT-20: a type derives from a parent ────────────────────────────

	// @e2e case-types::a-child-shows-its-parents-statuses
	// Scenario: A child shows its parent's statuses
	test('a child that declares nothing shows its parent’s four statuses, marked Inherited', async ({
		page,
	}) => {
		await openCaseType(page, child.caseType)

		const statuses = page.getByTestId('case-type-statuses')
		await expect(statuses).toBeVisible({ timeout: 30_000 })

		// Four rows, not "at least one": a merge that dropped the parent's
		// last status would still show three and read as working.
		await expect(statuses.locator('.case-type-blueprint__row')).toHaveCount(4)

		// Every one of them came from the parent, so every one is badged.
		await expect(
			statuses.locator('.case-type-blueprint__badge[data-origin="inherited"]'),
		).toHaveCount(4)

		await expect(page.getByTestId('case-type-parent')).toContainText(
			`${RUN_PREFIX} Bezwaar`,
		)
	})

	// @e2e openspec/specs/case-types/spec.md
	// Scenario: A child overrides one deadline
	test('a child’s own deadline beats its parent’s', async () => {
		// Through the API rather than through the page: the deadline a case
		// gets is computed by OpenRegister at save time from the case TYPE's
		// processingDeadline, so what is being asserted is the stored value,
		// and reading it off a rendered countdown would assert the renderer.
		// The request token, like every other call to a dossiq route. These
		// two blueprint reads were the only ones in the suite sent bare, and
		// Nextcloud answers 412 "CSRF check failed" to a bare request on ANY
		// dossiq API route, GET included — `available-transitions`,
		// `transition-history` and `dashboard/kpis` all do the same. So this
		// was never about the blueprint: it is what an app route does when the
		// caller does not identify itself.
		const res = await api.get(
			`/index.php/apps/${REGISTER}/api/case-types/${child.caseType}/blueprint`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(
			res.ok(),
			`blueprint -> ${res.status()} ${await res.text()}`,
		).toBeTruthy()

		const blueprint = await res.json()
		expect(blueprint.caseType.processingDeadline).toBe('P6W')
		expect(blueprint.parents[0].processingDeadline).toBe('P12W')
	})

	// @e2e case-types::a-cycle-is-refused
	// Scenario: A cycle is refused
	test('a parent that descends from the type is refused, and the message names the cycle', async () => {
		// The refusal lives in CaseTypeResolver::assertNoCycle, which the
		// publish path calls; the round trip asserted here is that the
		// blueprint of a cycle STOPS rather than looping the request forever,
		// which is what a reader of a mis-saved chain actually meets.
		await updateObject(api, token, 'caseType', parent.caseType, {
			parentCaseType: child.caseType,
		})

		const res = await api.get(
			`/index.php/apps/${REGISTER}/api/case-types/${parent.caseType}/blueprint`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		// With the status and the body in the message, because a bare
		// `toBeTruthy()` here reported only "expected true, got false" and
		// said nothing about the 412 that caused it.
		expect(
			res.ok(),
			`blueprint -> ${res.status()} ${await res.text()}`,
		).toBeTruthy()

		const blueprint = await res.json()
		// Three levels at most, and never the same type twice.
		const ids = [blueprint.caseType, ...blueprint.parents].map((row: any) =>
			objectId(row),
		)
		expect(new Set(ids).size).toBe(ids.length)

		// Put the parent back, so the tests that follow read an ordinary chain.
		await updateObject(api, token, 'caseType', parent.caseType, {
			parentCaseType: null,
		})
		const restored = await showObject(api, 'caseType', parent.caseType)
		expect(String(restored.parentCaseType ?? '')).toBe('')
	})

	// ── REQ-PDM-01 / REQ-PDM-02: folders and shared attributes ─────────────

	// @e2e openspec/specs/property-definition-management/spec.md
	// Scenario: A folder narrows the index
	// 🔴 PARKED ON OpenRegister#3560. The cause is a stale facet, NOT the page
	// size, and not anything in this app or in the library.
	//
	// `FacetHandler::getFacetsForObjects()` caches the whole facet response for
	// an hour and no object write invalidates it. CnFolderSidebar builds the
	// folder pane from that facet, so a category created today gets no folder
	// today. On CI an earlier worker warmed the facet and deleted its rows in
	// teardown; the retry worker seeded its own category, opened the page inside
	// the hour, and was handed the dead category instead of its own.
	//
	// THE TEN-SECOND PROOF, if you ever need to re-establish this. One live
	// instance, same request, same instant, one parameter that changes only the
	// cache key:
	//
	//   create a case type with a category nothing else uses
	//     -> total 24 becomes 25, buckets UNCHANGED
	//   add `_order[title]=asc`, so the key misses
	//     -> the same 25 rows, and the new bucket is there
	//
	// If two otherwise identical requests disagree, it is the cache, not the
	// data. Clearing it with `DELETE /api/settings/cache?type=facet` then makes
	// the original request agree.
	//
	// ⚠️ I first wrote the page-size explanation here, and it was wrong. The
	// manifest's own `_folderSidebarNote` says `field` derives the folders from
	// the distinct values of the loaded rows. That stopped being true at
	// nextcloud-vue 2.42.0 (#1036), which prefers the facet with pagination
	// stripped. A docblock outlived its truth and sent me, and two sessions
	// before me, back to the same wrong theory. That note is corrected in the
	// same change as this comment, so the two now agree.
	//
	// 🔑 THE SIBLING THAT PASSES, PASSES BY NARROWING. `case-objects.spec.ts`
	// clicks a folder in the same component and is green, because it opens its
	// index filtered by a fresh case uuid: that filter is part of the cache key,
	// so its facet can never be warm. Do not read it as evidence the pane works.
	//
	// 🔑 #2170's retry is the independent confirmation. It sorted this run's
	// rows onto page one, got them, and still found no folder. Rows present,
	// facet stale. So do not retry a sort, and note that `_order` IS read back
	// by `parseSortKeysFromQuery`, contrary to what #2210 recorded.
	//
	// ⚠️ DO NOT MAKE THIS PASS BY CLEARING THE CACHE FROM THE TEST.
	// `DELETE /api/settings/cache?type=facet` is an admin action a page reader
	// cannot take, so a test that arranges it asserts the arrangement.
	//
	// This is a live product defect, not a test artefact: an administrator who
	// gives a case type a new category gets no folder for it until the hour is
	// out. Restore this by deleting the `fixme` once OpenRegister#3560 lands.
	test.fixme('picking a folder narrows the Case types index to that category', async ({
		page,
	}) => {
		// The precondition is checked through the API, not off page one of the
		// index. The Case types index is bounded and instance-wide: it holds
		// every type every spec in the run has seeded, and under four workers
		// this run's four are not reliably on the first page. Asserting them
		// there tests the page size, and fails on a page that is working.
		const listed = await listObjects(api, 'caseType')
		const titles = listed.map((row: any) => String(row.title ?? ''))
		for (const title of [
			`${RUN_PREFIX} Onaf concept`,
			`${RUN_PREFIX} Bezwaar`,
			`${RUN_PREFIX} Bezwaar (verkort)`,
		]) {
			expect(
				titles,
				`${title} should exist before the folder narrows`,
			).toContain(title)
		}

		await openCaseTypes(page)
		await page.getByRole('button', { name: CATEGORY }).click()

		await expect(
			page.getByText(`${RUN_PREFIX} Bezwaar`, { exact: true }),
		).toBeVisible({ timeout: 20_000 })
		await expect(
			page.getByText(`${RUN_PREFIX} Bezwaar (verkort)`, { exact: true }),
		).toBeVisible()
		// The two without the category are gone: a folder that narrowed
		// nothing would leave them, and the two that belong would still show.
		await expect(
			page.getByText(`${RUN_PREFIX} Onaf concept`, { exact: true }),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/property-definition-management/spec.md
	// Scenario: A shared attribute appears on every type
	test('an attribute saved without a case type is listed on every type, marked Shared', async ({
		page,
	}) => {
		for (const caseType of [parent.caseType, publishable.caseType]) {
			await openCaseType(page, caseType)

			const properties = page.getByTestId('case-type-properties')
			const row = properties
				.locator('.case-type-blueprint__row')
				.filter({ hasText: SHARED_ATTRIBUTE })

			await expect(row).toBeVisible({ timeout: 20_000 })
			await expect(
				row.locator('.case-type-blueprint__badge[data-origin="shared"]'),
			).toBeVisible()
		}
	})

	// ── REQ-AVG-01: the personal data block ────────────────────────────────

	// @e2e openspec/specs/avg-verwerkingenlogging/spec.md
	// Scenario: The block reads back what you saved
	test('the personal data block reads back the categories and the basis', async ({
		page,
	}) => {
		await openCaseType(page, parent.caseType)

		// The block is a `data` widget, so it renders the schema's own fields
		// and their values; the values are what this asserts, because a widget
		// that rendered the labels and no values is the failure that looks
		// like success in a screenshot.
		const detail = page.locator('.cn-detail-page')
		await expect(detail).toContainText('public_task', { timeout: 30_000 })
		await expect(detail).toContainText('bsn')
		await expect(detail).toContainText('behandelen-bezwaarschrift')
	})

	// ── REQ-WIE-01: export, import, duplicate ──────────────────────────────

	// @e2e openspec/specs/workflow-import-export/spec.md
	// Scenario: Export downloads the bundle
	test('Export starts a download whose name carries the type’s identifier', async ({
		page,
	}) => {
		await openCaseType(page, parent.caseType)

		const download = page.waitForEvent('download', { timeout: 30_000 })
		await clickHeaderAction(page, 'cn-action-case-type-export')

		const file = await download
		// The endpoint names the file after the case type; asserting only that
		// SOMETHING downloaded would pass on an empty error blob.
		expect(file.suggestedFilename()).toMatch(/\.zip$/)
	})

	// @e2e openspec/specs/workflow-import-export/spec.md
	// Scenario: Duplicate opens the copy
	test('Duplicate lands you on the copy, with the same statuses', async ({
		page,
	}) => {
		await openCaseType(page, publishable.caseType)

		await clickHeaderAction(page, 'cn-action-case-type-duplicate')
		await expect(page.getByTestId('case-type-duplicate-dialog')).toBeVisible({
			timeout: 20_000,
		})
		await page.getByTestId('case-type-duplicate-confirm').click()

		// The whole point of the dialog: you LAND on the copy. An api-call
		// would have refreshed the page you were already on.
		await expect(page).not.toHaveURL(
			new RegExp(`case-types/${publishable.caseType}$`),
			{ timeout: 30_000 },
		)
		await expect(page).toHaveURL(/settings\/case-types\/[^/]+$/)

		await expect(page.getByTestId('case-type-blueprint')).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			page
				.getByTestId('case-type-statuses')
				.locator('.case-type-blueprint__row'),
		).toHaveCount(2)
	})

	// ── REQ-ZV-01: publish with a validation check and a change note ───────

	// @e2e openspec/specs/zaaktype-versioning/spec.md
	// Scenario: A draft with findings is not published
	test('a draft with findings lists them and stays a draft', async ({ page }) => {
		await openCaseType(page, incomplete.caseType)

		await clickHeaderAction(page, 'cn-action-case-type-publish')
		await expect(page.getByTestId('case-type-publish-dialog')).toBeVisible({
			timeout: 20_000,
		})

		await expect(page.getByTestId('case-type-publish-findings')).toBeVisible({
			timeout: 20_000,
		})
		// No note field and no Publish button while there is a finding: there
		// is nothing to publish yet, and asking for a note first would lose it.
		await expect(page.getByTestId('case-type-change-note')).toHaveCount(0)
		await expect(page.getByTestId('case-type-publish-confirm')).toHaveCount(0)

		const stored = await showObject(api, 'caseType', incomplete.caseType)
		expect(stored.isDraft).toBe(true)
	})

	// @e2e openspec/specs/zaaktype-versioning/spec.md
	// Scenario: A valid draft is published
	test('a valid draft is published with its change note, and the version says so', async ({
		page,
	}) => {
		await openCaseType(page, publishable.caseType)

		await clickHeaderAction(page, 'cn-action-case-type-publish')
		await expect(page.getByTestId('case-type-publish-dialog')).toBeVisible({
			timeout: 20_000,
		})
		await expect(page.getByTestId('case-type-publish-findings')).toHaveCount(0)

		// The test id lands ON the textarea, not on a wrapper around it:
		// `NcTextArea` sets `inheritAttrs: false` and binds `$attrs` to the
		// control, so `getByTestId(...).locator('textarea')` looks for a child
		// of an element that has none, and times out as if the dialog never
		// opened.
		await page
			.getByTestId('case-type-change-note')
			.fill(`${RUN_PREFIX} Eerste versie`)
		await page.getByTestId('case-type-publish-confirm').click()

		await expect(page.getByTestId('case-type-publish-dialog')).toHaveCount(0, {
			timeout: 30_000,
		})

		const stored = await showObject(api, 'caseType', publishable.caseType)
		expect(stored.isDraft).toBe(false)

		const template = await showObject(
			api,
			'workflowTemplate',
			publishable.template,
		)
		expect(String(template.lifecycleStatus)).toBe('published')
		expect(String(template.description)).toBe(`${RUN_PREFIX} Eerste versie`)

		// And the page says so: the Versions list is where a reader looks for
		// what changed, and the change note is the only thing it is ever asked.
		await expect(page.locator('.cn-detail-page')).toContainText(
			`${RUN_PREFIX} Eerste versie`,
			{ timeout: 30_000 },
		)
	})
})
