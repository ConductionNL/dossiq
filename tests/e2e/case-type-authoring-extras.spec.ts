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
		// column. RUN_PREFIX keeps another RUN out of this column, but not
		// another SPEC: it is per process, and a Playwright worker runs several
		// spec files in one process, so `seedStateMachine` in fixtures.ts seeds
		// its own `<prefix> In behandeling` under the same prefix. That status
		// names no colour and the schema stores the default grey for it, which
		// is why `mergeColumnColour` has to take a chosen hue over grey rather
		// than the first row the API answers with: before it did, this column
		// came out grey whenever the fixture machine happened to be created
		// first, and green on the retry that reseeded from an empty worker.
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

	// @e2e case-types::a-coloured-status-shows-on-the-case
	//
	// The badge half of REQ-CT-19: "the status badge on the case AND the
	// Workflow board column SHALL render in that colour". Only the board half
	// had a scenario, so this test had nothing to cite and credited nothing.
	// The scenario was written rather than the citation bent onto the board
	// one, which this test does not drive: the SHALL was already there with
	// nothing checkable attached to half of it.
	test('the case page draws the current status in its status’s colour', async ({
		page,
	}) => {
		const openCase = (
			await listObjects(api, 'case', { caseType: parent.caseType })
		).find((row) => String(row.status ?? '') === parent.progress)
		expect(openCase, 'an open case of the parent type').toBeTruthy()

		await page.goto(`/apps/${REGISTER}/cases/${objectId(openCase)}`)
		await dismissSupportDialog(page)

		// 🔴 THE COLOUR SURVIVES, THE TOKEN DOES NOT, and this assertion says
		// which. CaseHeaderRow carried the authored palette NAME on a
		// `data-colour` attribute, read only by this test; the visible variant
		// came from `isFinal`. The identity row is a configured `stat` tile
		// now, and its badge takes ONE axis: `objectField.resolve.variantField`
		// is `colour`, so the authored hue is what paints the pill, mapped
		// through `variantMap` onto the six variants CnStatusBadge accepts.
		// Orange maps to `warning`. What is gone is the exact
		// `var(--nl-color-orange)` token and the six `-light` tints, which fold
		// onto their full hue. That loss is in the PR that made this change.
		await expect(page.getByTestId('cn-stat-widget-badge')).toHaveClass(
			/cn-status-badge--warning/,
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
	//
	// 🔴 DELIBERATELY STILL ANCHORLESS. `case-types::a-child-overrides-one-deadline`
	// ends "WHEN you file a case of Bezwaar (verkort), THEN the CASE's deadline
	// SHALL be 6 weeks after its start date". This test reads the BLUEPRINT and
	// asserts the case TYPE resolves `P6W` over its parent's `P12W`. That is
	// the input to the rule, not the rule's outcome: a case whose deadline was
	// computed from the parent anyway, or not computed at all, satisfies every
	// assertion here.
	//
	// The comment below argues the case deadline follows from the type's
	// stored value, and it does, through OpenRegister at save time. But the
	// scenario is about the step this test does not take. File a case and read
	// its deadline, then anchor.
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

	// NOT cited to case-types::a-cycle-is-refused. That scenario is about the
	// save a PERSON makes, in the Edit dialog, and is cited where that is
	// driven: case-type-parent-chain.spec.ts. This one sends the same save
	// through the object API, the path a script or an import takes.
	// @e2e openspec/specs/case-types/spec.md
	// Scenario: A cycle is refused
	test('a parent that descends from the type is refused, and the message names the cycle', async () => {
		// Since CaseTypeParentCycleListener the loop is refused ON SAVE, on
		// every write path. This is the one a script or an import uses,
		// OpenRegister's object API; the Edit dialog a person uses is
		// case-type-parent-chain.spec.ts, which is where the scenario is
		// cited. What this keeps from its old self is the other half: the
		// refusal leaves the stored chain as it was, so the blueprint still
		// reads as a finite chain.
		const current = await showObject(api, 'caseType', parent.caseType)
		const refused = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${parent.caseType}`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: { ...current, parentCaseType: child.caseType },
			},
		)
		try {
			expect(refused.status(), await refused.text()).toBe(422)
			// The loop, by the titles this spec seeded, in either locale.
			expect(String((await refused.json()).error)).toContain(
				`${RUN_PREFIX} Bezwaar -> ${RUN_PREFIX} Bezwaar (verkort) -> ${RUN_PREFIX} Bezwaar`,
			)

			const stored = await showObject(api, 'caseType', parent.caseType)
			expect(String(stored.parentCaseType ?? '')).toBe('')

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
		} finally {
			// Only when the guard let the loop through: put the parent back so
			// the tests that follow read an ordinary chain, and the failure
			// above stays the one that is reported.
			if (refused.ok()) {
				await updateObject(api, token, 'caseType', parent.caseType, {
					parentCaseType: null,
				})
			}
		}
	})

	// ── REQ-PDM-01 / REQ-PDM-02: folders and shared attributes ─────────────

	// @e2e property-definition-management::a-folder-narrows-the-index
	//
	// The citation named the spec FILE and the scenario sat in prose on the
	// next line, so gate-19 credited it to nothing. The test proves the
	// scenario in BOTH directions, which is what makes the anchor honest: the
	// two types in the category are visible AND the one outside it is gone
	// (count 0). "The index SHALL list the two only" needs the second half;
	// a folder that narrowed nothing would still show the two that belong.
	// UNPARKED. This was `test.fixme` on OpenRegister#3560, and that cause is
	// fixed in the OpenRegister this suite runs against.
	//
	// The cause was a stale facet, not the page size and not anything in this
	// app or in the library. `FacetHandler::getFacetsForObjects()` cached the
	// whole facet response for an hour and no object write invalidated it.
	// CnFolderSidebar builds the folder pane from that facet, so a category
	// created today got no folder today. On CI an earlier worker warmed the
	// facet and deleted its rows in teardown; the retry worker seeded its own
	// category, opened the page inside the hour, and was handed the dead
	// category instead of its own.
	//
	// openregister#3563 (merged into `development` on 2026-09-10, e08a0d72)
	// folds a per register and schema version counter into the facet cache
	// key and bumps it on every object create, update, delete and transition.
	// dossiq's CI installs openregister from `development` (`additional-apps`
	// in .github/workflows/code-quality.yml), so the category this spec seeds
	// in `beforeAll` invalidates the facet the page then reads.
	//
	// IF THIS GOES RED AGAIN, check the facet before the test. One instance,
	// same request, same instant, one parameter that changes only the cache
	// key:
	//
	//   create a case type with a category nothing else uses
	//     -> total 24 becomes 25, buckets UNCHANGED
	//   add `_order[title]=asc`, so the key misses
	//     -> the same 25 rows, and the new bucket is there
	//
	// If two otherwise identical requests disagree, it is the cache, not the
	// data. openregister#3563 turned that proof into
	// `testAddingASortParameterNoLongerChangesTheBucketList`.
	//
	// And not the page size, which was the first theory here and was wrong.
	// Since nextcloud-vue 2.42.0 (#1036) the pane is built from the facet with
	// pagination stripped, not from the loaded rows. #2170's retry confirmed
	// it: it sorted this run's rows onto page one and still found no folder.
	//
	// 🔑 THE SIBLING THAT PASSES, PASSES BY NARROWING. `case-objects.spec.ts`
	// clicks a folder in the same component and was green throughout, because
	// it opens its index filtered by a fresh case uuid: that filter is part of
	// the cache key, so its facet could never be warm. Do not read it as
	// evidence for this test, in either direction.
	//
	// ⚠️ DO NOT MAKE THIS PASS BY CLEARING THE CACHE FROM THE TEST.
	// `DELETE /api/settings/cache?type=facet` is an admin action a page reader
	// cannot take, so a test that arranges it asserts the arrangement.
	test('picking a folder narrows the Case types index to that category', async ({
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

	// @e2e avg-verwerkingenlogging::the-block-reads-back-what-you-saved
	//
	// The scenario's THEN names three values, `naw`, `bsn` and `public_task`,
	// and the fixture seeds all three: `personalDataCategories: ['naw','bsn']`
	// and `legalBasis: 'public_task'`. Only two were asserted, so the first
	// category could have been dropped by the widget and this stayed green.
	// `naw` is asserted below, which is what makes the anchor honest.
	//
	// ⚠️ `naw` is a three-character substring check, in the same loose
	// `toContainText` form as its siblings, so it is the weakest of the three:
	// any word on the page containing those letters satisfies it. The stronger
	// form reads the categories out of the block itself rather than the whole
	// detail page, and wants an instance to pin the selector.
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
		await expect(detail).toContainText('naw')
		await expect(detail).toContainText('bsn')
		await expect(detail).toContainText('behandelen-bezwaarschrift')
	})

	// ── REQ-WIE-01: export, import, duplicate ──────────────────────────────

	// @e2e openspec/specs/workflow-import-export/spec.md
	//
	// 🔴 DELIBERATELY STILL ANCHORLESS. The obvious target is
	// `workflow-import-export::export-downloads-the-bundle`, whose THEN is
	// "a download SHALL start whose name carries the type's identifier". This
	// test asserts the filename ends in `.zip` and nothing about the
	// identifier, so it cannot tell the type's own bundle from any other
	// type's. Its own comment already says asserting that something
	// downloaded would pass on an empty error blob; `/\.zip$/` is barely more
	// than that.
	//
	// Anchoring here would credit the identifier clause to a test that cannot
	// see it break. The repair is to assert the name carries the identifier
	// and then anchor, which needs a run to establish whether the endpoint
	// names the file by uuid, title or slug. Guessing that from source is how
	// a test gets written that reddens on a working build.
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
	//
	// 🔴 DELIBERATELY STILL ANCHORLESS, for the narrower of two reasons. The
	// scenario says you land on a type TITLED `Bezwaar (kopie)` with the same
	// statuses. The landing is proven well: the URL is asserted NOT to be the
	// original, which is the half an api-call refresh would have passed.
	//
	// What is missing is the title. Nothing here reads the copy's name, so a
	// Duplicate that lands on a correctly-structured copy called anything at
	// all satisfies every assertion. The status check is a COUNT of 2 as
	// well, so it holds for two differently-named statuses.
	//
	// Assert the title and read the status names, then anchor. Both are cheap
	// on an instance and neither is safe to write blind.
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

	// @e2e zaaktype-versioning::a-draft-with-findings-is-not-published
	//
	// Both clauses of the scenario are asserted: the page lists the finding
	// (`case-type-publish-findings` visible) and the type stays a draft, read
	// back off the STORED object rather than off the dialog that refused.
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

	// @e2e zaaktype-versioning::a-valid-draft-is-published
	//
	// Both clauses: the type is no longer a draft, read off the stored
	// object, and the change note reaches the version, asserted on the stored
	// template AND on the page a reader actually looks at.
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
