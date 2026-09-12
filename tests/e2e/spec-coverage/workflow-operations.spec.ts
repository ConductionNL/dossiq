/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioural UI coverage for the operational views that sit alongside the
 * case lists: the Workflow Board (kanban of statuses), the Case Map, the
 * Transfers index, the Subsidies / Grant-schemes intake lists and the
 * Features & roadmap page. Each is reached via its sidebar nav entry and
 * asserted on its distinct rendered surface (heading / empty-state /
 * primary control) independently of seeded data.
 */

import { expect, test } from '@playwright/test'
import {
	ensureCaseType,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'
import { navToRoute, trackDossiqErrors } from '../helpers/nav.ts'
// Routes named after the component that renders them, so this spec states
// WHICH screen it covers in executable code rather than in a comment.
import { CasesOnMapView, WorkflowBoard } from '../helpers/page-components.ts'

test.describe('Workflow Board page', () => {
	// @e2e exclude The citation this carried was taken down rather than
	// strengthened. DASH-V1-006a names three columns in status order with a
	// per-column case count, and the assertion below is
	// `.board-column, .workflow-board__empty`, an `or` that a board rendering
	// zero columns satisfies. The scenario is proven in full by
	// `tests/e2e/workflows/case-lifecycle.spec.ts`, which seeds four status
	// types out of creation order and asserts the column NAMES and the counts
	// `['2', '1', '0']`. Rebuilding that here would duplicate it without
	// adding a claim, and leaving the citation in place would credit the
	// scenario twice, once for a check that cannot fail. This stays what it
	// is: the board's shell, on the route, without a 500.
	test('workflow board renders its heading and a status/empty surface', async ({
		page,
	}) => {
		// The nav label is "Workflow board" (lower-case b) and it sits inside
		// the collapsed "Work queue" group — navigate by route instead.
		await navToRoute(page, WorkflowBoard)
		// The board view renders its own header h2 inside `.workflow-board__header`
		// (the page also has a dashboard-wrapper title + widget title with the
		// same text, so scope to the board's own header).
		await expect(page.locator('.workflow-board__header h2')).toBeVisible({
			timeout: 15000,
		})
		// With no status types configured the board shows its guidance
		// empty-state; with statuses it renders one `.board-column` per status
		// type (the seeded register uses the Dutch ZGW status names —
		// "Received", … — each with a per-column "No cases" surface). Assert
		// the data- and locale-independent kanban surface: a status column or
		// the no-statuses guidance, never an error render.
		await expect(
			page.locator('.board-column, .workflow-board__empty').first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// FIXED: WorkflowBoard.load() calls objectStore.fetchCollection('statusType')
	// / ('caseType'). Those types are registered in initializeStores()
	// (src/store/store.js), but the registration used to be skipped when the
	// app-config schema id (case_type_schema / status_type_schema) was blank —
	// which it is on a fresh OR register — leaving the types unregistered and
	// logging two dossiq-origin "Object type is not registered" console errors
	// per load while the kanban columns stayed empty. The same defect hit the
	// Doorlooptijd analytics view (caseType). store.js now falls back to the
	// canonical schema slug ('caseType' / 'statusType') when the config id is
	// empty, so the types are always registered and this contract holds.
	// @e2e exclude A console-error check cannot prove one column per non-final
	// status type, their order or their case counts. The same DASH-V1-006a
	// citation was carried here and on the shell test above, so the scenario
	// was credited twice over by two load checks while
	// `tests/e2e/workflows/case-lifecycle.spec.ts` did the actual proving. The
	// regression this guards is real and is described above it, so the test
	// stays; the citation does not.
	test('workflow board loads without dossiq console errors', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		// The nav label is "Workflow board" (lower-case b) and it sits inside
		// the collapsed "Work queue" group — navigate by route instead.
		await navToRoute(page, WorkflowBoard)
		await expect(page.locator('.workflow-board__header h2')).toBeVisible({
			timeout: 15000,
		})
		await page.waitForTimeout(1500)
		expect(errors, errors.join('\n')).toEqual([])
	})
})

/** GeoJSON geometry, JSON-encoded, the way the `case` schema stores it. */
const POINT = (lng: number, lat: number): string =>
	JSON.stringify({ type: 'Point', coordinates: [lng, lat] })

/** A small closed ring around `[lng, lat]`, as a GeoJSON Polygon. */
const POLYGON = (lng: number, lat: number): string =>
	JSON.stringify({
		type: 'Polygon',
		coordinates: [
			[
				[lng, lat],
				[lng + 0.001, lat],
				[lng + 0.001, lat + 0.001],
				[lng, lat + 0.001],
				[lng, lat],
			],
		],
	})

/**
 * Read the map sidebar's own tally: "Showing {filtered} of {total} located
 * cases".
 *
 * Deliberately the VIEW's numbers and not a second count computed from the
 * API. The claim under test is what the map plots, and a helper that recounted
 * the register would be asserting one reading of the data against another
 * reading of the same data — green whenever the two agreed, including when
 * both were wrong.
 *
 * @param page The Playwright page, already on /map.
 * @return The two numbers the summary prints.
 */
async function locatedTally(page): Promise<{ filtered: number; total: number }> {
	const text = await page
		.locator('.cases-on-map__summary')
		.first()
		.innerText({ timeout: 30_000 })
	const m = text.match(/(\d+)\D+(\d+)/)
	if (m === null) {
		throw new Error(`the map summary did not print two numbers: ${text}`)
	}
	return { filtered: Number(m[1]), total: Number(m[2]) }
}

/**
 * The tally once the view has finished loading and stopped changing.
 *
 * The summary renders before the fetch resolves, printing "Showing 0 of 0", so
 * a single read taken on first paint is a reading of the loading state rather
 * than of the data. Waiting for the spinner to go and then requiring two
 * readings a second apart to agree is what makes the number the view's answer
 * rather than its opening guess.
 *
 * @param page The Playwright page, already on /map.
 * @return The settled summary numbers.
 */
async function settledTally(page): Promise<{ filtered: number; total: number }> {
	await expect(page.locator('.cases-on-map__loading')).toHaveCount(0, {
		timeout: 60_000,
	})
	let last = await locatedTally(page)
	for (let attempt = 0; attempt < 6; attempt++) {
		await page.waitForTimeout(1000)
		const next = await locatedTally(page)
		if (next.total === last.total && next.filtered === last.filtered) {
			return next
		}
		last = next
	}
	throw new Error(
		`the map's located-case tally never settled (last read ${last.filtered}/${last.total})`,
	)
}

test.describe('Case Map page', () => {
	/**
	 * WHAT THIS USED TO ASSERT, and why none of it could fail.
	 *
	 * The old body navigated to /map, asserted the "Cases on map" heading, and
	 * then asserted `.leaflet-container, [class*="map"]` — a disjunction whose
	 * right half matches the view's own `.cases-on-map__map` wrapper, so the
	 * assertion was satisfied before Leaflet had done anything at all. It then
	 * checked for the absence of "Internal Server Error" and for no
	 * dossiq-origin console errors. A build that plotted NOTHING passed every
	 * line of it, which is precisely the claim OVERVIEW-01a makes.
	 *
	 * WHAT IT ASSERTS NOW. The scenario's subject is which cases reach the map:
	 * "a full-width map MUST be displayed showing all case locations". So this
	 * seeds four cases — three carrying geometry (two Points and a Polygon) and
	 * one carrying none — and requires the map's own located-case tally to rise
	 * by exactly three. Exactly, not at least: a build that plotted every case
	 * whether or not it has a location would move the number by four, and a
	 * build that parsed no geometry would not move it at all. The tally is read
	 * before and after from the same surface, so it needs no agreement with a
	 * second count of the register.
	 *
	 * TWO CLAUSES OF THE SCENARIO ARE NOT ASSERTED HERE, and saying which is
	 * part of the citation:
	 *
	 *  - "markers or polygons depending on geometry type" is not what shipped,
	 *    on purpose. `extractCoords` in src/services/mapFormatters.js takes the
	 *    arithmetic-mean centroid of a Polygon's outer ring and pins it, and
	 *    says so, citing case-location REQ-LOC-03b. That is a product decision
	 *    to amend or keep, not something to settle by writing a test around it;
	 *    the Polygon fixture above is here so the count proves a polygon does
	 *    at least reach the map.
	 *  - clustering and auto-fit are `CnMapWidget` props (`:clustering="true"`,
	 *    `:autoFit="features.length > 0"`), owned by the library and asserted
	 *    in its own suite. What this file can prove is that dossiq passes
	 *    features to it, which is what the tally reads.
	 */
	// @e2e openspec/specs/case-map-overview/spec.md#scenario-overview-01a-display-all-cases-on-map
	test('the map plots every case that carries geometry, and only those', async ({
		page,
	}) => {
		test.slow()
		const errors = trackDossiqErrors(page)

		await navToRoute(page, CasesOnMapView)
		// The rendered heading is "Cases on map" — measured on a CI runner
		// (2026-08-04). "Case map" is the manifest page TITLE, not the heading
		// the view renders.
		await expect(
			page.getByRole('heading', { name: 'Cases on map' }),
		).toBeVisible({ timeout: 15000 })
		const before = await settledTally(page)
		expect(
			before.filtered,
			'with no filter set the map shows every located case it loaded',
		).toBe(before.total)

		const token = await getRequestToken(page.request)
		const caseType = await ensureCaseType(page.request, token)
		const located: Array<[string, string]> = [
			['point a', POINT(4.8952, 52.3702)],
			['point b', POINT(4.4777, 51.9244)],
			['polygon', POLYGON(5.1214, 52.0907)],
		]
		for (const [label, geometry] of located) {
			await seedCase(page.request, token, {
				title: `${RUN_PREFIX} map ${label}`,
				caseType: caseType.id,
				geometry,
			})
		}
		// The control: a case of the same type, seeded the same way, carrying
		// no geometry. It is the half that makes "only those" falsifiable.
		await seedCase(page.request, token, {
			title: `${RUN_PREFIX} map unlocated`,
			caseType: caseType.id,
		})

		await navToRoute(page, CasesOnMapView)
		await expect(
			page.getByRole('heading', { name: 'Cases on map' }),
		).toBeVisible({ timeout: 15000 })
		await expect
			.poll(async () => (await locatedTally(page)).total, {
				timeout: 60_000,
				message:
					'the map must plot the three located cases and leave the fourth, '
					+ 'which carries no geometry, off',
			})
			.toBe(before.total + 3)

		// Leaflet's own container, without the `[class*="map"]` fallback that
		// used to make this assertion unfailable, and the marker pane it fills
		// once features arrive.
		await expect(page.locator('.leaflet-container').first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.locator('.leaflet-marker-icon, .marker-cluster').first(),
			'a located case reaches the map as something drawn on it',
		).toBeVisible({ timeout: 15_000 })

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

// Transfers list page removed — cases are transferred from their detail page
// (the TransferDetail route is kept for deep links). See
// feat(nav): streamline work queue.

test.describe('Subsidies intake page', () => {
	// UNPARKED, AND THE ASSERTION IT WAS WAITING ON WAS THE WRONG ONE.
	//
	// The old FIXME(#719) recorded that /subsidies shows "Add Case" rather than
	// "Add Subsidie", and parked the test until a subsidy-specific create
	// control appeared. It never will, and it should not: `Subsidies` in
	// src/manifest.d/50-subsidie.json is an index over register `dossiq`,
	// schema `case`, narrowed by `filter.caseType`. A subsidie-aanvraag IS a
	// case — that is the same decision ADR-044 records for grant schemes
	// becoming case types — so CnIndexPage labels the create button from the
	// `case` schema's title, and "Add Case" is the correct label.
	//
	// So the shell this test pins is not the create button. It is that
	// /subsidies is a NARROWED case list with its own column set, rather than
	// the unfiltered Cases index under a different name.
	// @e2e exclude No canonical spec covers the subsidies index shell. The
	// subsidie specs describe the keten (voorschot, termijnen, verplichting,
	// vaststelling) and the settlement of case costs, none of them the page
	// that lists the aanvragen. This test pins the shell until one is written.
	test('subsidies index renders the subsidy intake list shell', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		// "Subsidies" is a group header with no label in the subsidie manifest
		// fragment, so it renders no clickable nav entry — navigate by route.
		await navToRoute(page, '/subsidies')
		// View switcher renders as buttons, not radios.
		await expect(page.getByRole('button', { name: 'Cards' })).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByRole('button', { name: 'Table' })).toBeVisible()
		// Its own page, by its own title.
		await expect(
			page.getByRole('heading', { name: /^Subsidies$/i }).first(),
		).toBeVisible({ timeout: 30_000 })
		// 🔴 THE NARROWING IS THE CLAIM, AND AN ABSENCE IS HOW IT READS.
		// `Subsidies` declares `config.filter.caseType` and NO `quickFilters`,
		// while `Cases` declares six of them (All, Mine, Unclaimed, Closed,
		// Overdue, Due this week) and no filter. So the chip strip is present
		// on exactly one of the two pages, and its absence here is what says
		// this is the narrowed subsidy list rather than the Cases index under
		// another route. Measured rather than assumed: the column headers this
		// used to assert are not on the page at all, because the seeded
		// register holds no case of this caseType and the table renders its
		// empty state instead.
		for (const chip of ['All', 'Unclaimed', 'Overdue']) {
			await expect(
				page.getByRole('tab', { name: chip, exact: true }),
				`the subsidies index declares no quick filters, so there is no ${chip} chip`,
			).toHaveCount(0)
		}
		// The create control carries the `case` schema's label, because a
		// subsidie-aanvraag IS a case. This is the assertion the old FIXME was
		// waiting to see inverted.
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case)$/ }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Grant schemes are administered as case types', () => {
	// UPDATED BY subsidieregeling-is-a-casetype. This used to assert that
	// /subsidieregelingen rendered its own index. A grant scheme IS a case type
	// — four of its properties were caseType fields under another name — so the
	// bespoke page retired and schemes are administered on the Case types index.
	//
	// Asserting BOTH halves. The absence check alone would pass just as happily
	// on a build where the capability vanished entirely, which is what ADR-044
	// Decision 5 forbids.
	//
	// @e2e openspec/specs/case-types/spec.md#the-retired-route-falls-through-rather-than-erroring
	test('the retired scheme index is gone and Case types took it over', async ({
		page,
	}) => {
		await navToRoute(page, '/subsidieregelingen')
		await expect(
			page.getByRole('button', { name: 'Add Subsidieregeling' }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')

		await navToRoute(page, '/settings/case-types')
		await expect(
			page.getByRole('button', { name: /Add|New|Create/i }).first(),
		).toBeVisible({ timeout: 20000 })
	})
})

test.describe('Features & roadmap page', () => {
	// @e2e openspec/specs/features-roadmap/spec.md#features-page-renders-controls
	test('features & roadmap renders heading and its action controls', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await navToRoute(page, '/features-roadmap')
		await expect(
			page.getByRole('heading', { name: 'Features' }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByRole('button', { name: 'Show roadmap' }),
		).toBeVisible()
		await expect(
			// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
			// suggestion modal (team decision 2026-09-04: the forge is where the
			// conversation happens), and the CTA is an anchor to the forge's
			// feature-request issue form now. An `<a href>` has role `link`.
			page.getByRole('link', { name: 'Suggest feature' }),
		).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
