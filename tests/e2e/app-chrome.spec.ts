/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (no fallback, no console error, and four apps shipped it), an entry
 * whose `route` names a page the app does not host renders a row that goes
 * nowhere, and `nav.includePersonalSettings: false` silently removed the entry
 * reaching the user's notification preferences in two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const APP_BASE = '/index.php/apps/dossiq'

/**
 * The comparison data the Features & roadmap page renders, read from the same
 * file the bundle imports. The page DERIVES every number it shows from this
 * file (src/utils/capabilityComparison.js), so the test derives the expected
 * numbers the same way and asserts that the page states them. Reading it
 * rather than writing the numbers in keeps the test from going red every time
 * a reading round adds a row, while still failing when the page stops saying
 * what the file holds.
 */
const COMPARISON = JSON.parse(
	fs.readFileSync(
		path.join(__dirname, '..', '..', 'src', 'data', 'capabilityComparison.json'),
		'utf8',
	),
)

/** The competitor columns: every system except dossiq's own. */
const RIVALS: string[] = COMPARISON.systems
	.filter((system: any) => !system.isSelf)
	.map((system: any) => system.key)

/** The rows a later reading round added, which no competitor was read against. */
const ADDED_ROWS: any[] = COMPARISON.capabilities.filter((row: any) => row.addedOn)

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers, and not the entry ids either:
		// this app reaches its reports through an entry called AnalyticsGroup
		// LABELLED "Reports", which is compliant — ADR-114 constrains the label
		// and the position, not the id.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports carries the three reports as cards', async ({ page }) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Reports$/ })
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/dossiq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		// Named individually rather than counted: a count assertion reds on
		// ADDING a report, passes on a swap, and never names what went missing.
		for (const label of [
			'Processing time',
			'Deadline monitoring',
			'Process mining',
		]) {
			await expect(page.getByText(label, { exact: true }).first()).toBeVisible(
				{ timeout: 15_000 },
			)
		}
	})

	test('the report pages behind the cards are still routable', async ({
		page,
	}) => {
		test.slow()

		// Process mining had NO menu entry before it was carded — it was a
		// standalone route reachable only if you already knew the URL. Carding
		// it gave it an entry point; this proves the route still answers.
		for (const path of ['/process-mining', '/termijn-dashboard']) {
			// 🔴 `domcontentloaded`, NOT the default `load`. Nextcloud's
			// notification poll keeps the network busy, so waiting for the load
			// event waits for something that does not settle — the loop dies
			// partway through and names whichever route it was on, which reads
			// as a broken route. The SPA mounts after DOM ready, and the
			// assertions below are what prove the mount.
			await page.goto(`${APP_BASE}${path}`, {
				...PAGE_LOAD,
				waitUntil: 'domcontentloaded',
			})
			await expect(page).toHaveURL(new RegExp(`${path}(\\?|$)`), {
				timeout: 15_000,
			})
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	// No citation, on purpose. This test used to cite
	// openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
	// with no anchor, which names a change-local file gate 19 cannot read and
	// credits no scenario. Every scenario in it is about page topology (dashboard type,
	// one heading, no nesting) and this test asserts none of that. The nearest
	// canonical scenario, `termijn-reporting::dashboard-kpi-endpoint-returns-aggregates`,
	// names a path, a field and a cache the endpoint does not have, so moving
	// the citation there would make it false. It stays as the regression
	// guard for the rename it was written for (e2e-citation-integrity, audit
	// group 3).
	test('the deadline-monitoring report loads its KPIs from the dossiq route', async ({
		page,
	}) => {
		// The store addressed /apps/procest/ after the rename, so the page
		// mounted and every figure 404'd. Assert the REQUEST, because the
		// empty state and the broken state look the same on screen.
		const kpiResponses: Array<{ status: number; url: string }> = []
		page.on('response', (r) => {
			if (r.url().includes('/api/termijn/dashboard/kpi')) {
				kpiResponses.push({ status: r.status(), url: r.url() })
			}
		})
		await page.goto(`${APP_BASE}/termijn-dashboard`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect
			.poll(() => kpiResponses.length, { timeout: 30_000 })
			.toBeGreaterThan(0)
		for (const r of kpiResponses) {
			expect(r.url, 'the request must address the dossiq app id').toContain(
				'/apps/dossiq/',
			)
			expect(r.status, r.url).toBeLessThan(400)
		}
	})

	test('Features & roadmap lists the shipped features', async ({ page }) => {
		// The page reads its list from initial state (ADR-018); dossiq handed
		// it nothing, so the tab was empty while docs/features.json held 23.
		await page.goto(`${APP_BASE}/features-roadmap`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.cn-features-and-roadmap-view')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.locator('.cn-features-tab__card').first()).toBeVisible({
			timeout: 15_000,
		})
		expect(await page.locator('.cn-features-tab__card').count()).toBeGreaterThan(
			5,
		)
	})

	// @e2e openspec/specs/features-roadmap/spec.md#areas-summarise-before-they-expand
	// @e2e openspec/specs/features-roadmap/spec.md#a-reader-can-date-the-claim
	// @e2e openspec/specs/features-roadmap/spec.md#the-panel-advises-the-reader-to-test-for-themselves
	// @e2e openspec/specs/features-roadmap/spec.md#the-panel-accounts-for-rows-a-later-round-added
	//
	// MUTATION CHECK, NOT YET RUN. The permission to break the product for
	// these checks is pending, so the two clauses below are unverified. Each
	// line names the break and the assertion that must redden; restore after.
	//   areas-summarise-before-they-expand
	//     FeaturesRoadmapView.vue: add `open` to `<details class="features-roadmap__area">`
	//       -> "area intake must start collapsed"
	//     areaSummary(): `total: area.capabilities.length + 1`
	//       -> "area intake must state its capability count and how dossiq scored"
	//   the-panel-accounts-for-rows-a-later-round-added
	//     addedRowsText(): `count: added.length + 1`
	//       -> "the panel must say how many rows later rounds added"
	//     addedRowsText(): `date: formatComparedOn(comparison.comparedOn, ...)`
	//       -> "the panel must say when the most recent rows were added"
	//     addedRowsText(): `others: comparison.systems.length - 2`
	//       -> "the panel must say every competitor column is unrated on the added rows"
	//     capabilityComparison.json row 1.14: `"opencase": "yes"`
	//       -> "an added row must read Unknown for every competitor, never a guess"
	test('FeaturesRoadmapView compares dossiq and states the comparison limits', async ({
		page,
	}) => {
		// The comparison lives on the same page as the feature list, in the
		// second section of FeaturesRoadmapView. The features section is the
		// landing one, so this test has to switch before it can assert.
		await page.goto(`${APP_BASE}/features-roadmap`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.features-roadmap__sections')).toBeVisible({
			timeout: 30_000,
		})

		// This is the only test in this file that CLICKS, and the click is
		// exactly what CnAppRoot's support dialog and the first-time-setup
		// wizard swallow: their modal mask covers the app and the click lands
		// on the mask instead, which reports as a timeout rather than as a
		// mask. A fresh browser profile has no dismissal recorded, so the
		// wizard does open. global-setup.ts does not settle either one.
		await dismissSupportDialog(page)

		await page.getByRole('button', { name: 'How dossiq compares' }).click()

		const comparison = page.locator('.features-roadmap__comparison')
		await expect(comparison).toBeVisible({ timeout: 15_000 })

		// The three limits are the point of the section, not decoration: a
		// score with no scope, no date and no caveat is the thing we refuse to
		// publish. Each is asserted by the claim it makes, not by its wording
		// alone, so a rewrite that DROPS one fails here.
		await expect(comparison).toContainText('open source software we could')
		await expect(comparison).toContainText('already out of date')
		// The year of the reading date, interpolated into that same sentence.
		// Asserting the year rather than the formatted date keeps this off
		// Intl's month spelling while still failing if the date goes missing.
		await expect(comparison).toContainText('2026')
		await expect(comparison).toContainText('is not proof')
		// The advice to go and test. This is the caveat that tells the reader
		// what to DO, and it was missing from the first cut of this panel: the
		// other three only tell them what to discount, which reads as hedging
		// on its own.
		await expect(comparison).toContainText('run your own evaluation')

		// Rounds 3 and 4 added rows without re-reading the other four
		// products, so their cells on those rows say Unknown. The panel has to
		// account for that, or a reader sees four systems scored over a much
		// shorter list than ours and no reason why.
		await expect(comparison).toContainText('capabilities to the list')
		await expect(comparison).toContainText(
			'a guessed rating is worse than an empty cell',
		)

		// A column whose product owns no data loses rows to its architecture
		// on a list written in our shape, so its score is low for a reason
		// that is not about the product. That bias runs in our favour, which
		// is exactly why it has to be on the page beside the column.
		await expect(comparison).toContainText('owns no data')
		await expect(comparison).toContainText('which flatters us')
		// And that column was read on its own day, not on the shared one.
		await expect(comparison).toContainText('not on the date above')

		// `the-panel-accounts-for-rows-a-later-round-added`, clause by clause.
		// The two sentences above were all this test used to read, and neither
		// carries a number or a date, so a panel reporting the wrong count, no
		// date, or a guessed rating on an added row stayed green.
		//
		// THEN the panel says how many rows were added, and when. The date is
		// the most recent addition, formatted the way formatComparedOn does it.
		const latestAddition = ADDED_ROWS.map((row) => row.addedOn)
			.sort()
			.at(-1)
		const latestAdditionText = new Intl.DateTimeFormat('en', {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'UTC',
		}).format(new Date(`${latestAddition}T00:00:00Z`))
		expect(ADDED_ROWS.length, 'the data file holds added rows').toBeGreaterThan(
			0,
		)
		await expect(
			comparison,
			'the panel must say how many rows later rounds added',
		).toContainText(`we added ${ADDED_ROWS.length} capabilities to the list`)
		await expect(
			comparison,
			'the panel must say when the most recent rows were added',
		).toContainText(`the most recent of them on ${latestAdditionText}`)
		// AND it says every competitor column is unrated on those rows.
		await expect(
			comparison,
			'the panel must say every competitor column is unrated on the added rows',
		).toContainText(`The other ${RIVALS.length} columns read Unknown`)
		// AND those rows show Unknown for every competitor, never a guess. Read
		// off the rendered cells: the rows are in the DOM while their area is
		// collapsed, so one pass covers all of them without opening seventeen
		// disclosures.
		const addedIds = ADDED_ROWS.map((row) => String(row.id))
		const cells = await comparison
			.locator('.features-roadmap__area tbody tr')
			.evaluateAll(
				(rows, ids) =>
					rows
						.map((row) => ({
							id: (
								row.querySelector('.features-roadmap__num')
									?.textContent ?? ''
							).trim(),
							chips: Array.from(
								row.querySelectorAll(
									'td:not(.features-roadmap__num)',
								),
							)
								.filter(
									(cell) =>
										!cell.classList.contains(
											'features-roadmap__col--self',
										),
								)
								.map(
									(cell) =>
										cell.querySelector('.features-roadmap__chip')
											?.className ?? '',
								),
						}))
						.filter((row) => ids.includes(row.id)),
				addedIds,
			)
		expect(
			cells.map((row) => row.id).sort(),
			'every added row must be on the page',
		).toEqual([...addedIds].sort())
		const guessed = cells
			.filter(
				(row) =>
					row.chips.length !== RIVALS.length
					|| row.chips.some(
						(chip) => !chip.includes('features-roadmap__chip--unknown'),
					),
			)
			.map((row) => row.id)
		expect(
			guessed,
			'an added row must read Unknown for every competitor, never a guess',
		).toEqual([])

		// Seventeen areas, collapsed. Thirteen came from the audit and round 4
		// added four more, for capabilities that had nowhere to go: a case
		// plan of services, money on the case, offline field work, and one
		// instance serving several organisations. The rows live behind the
		// disclosure so the landing view stays readable; if a change flattens
		// 329 rows onto the page, this count is what notices.
		const areas = comparison.locator('.features-roadmap__area')
		await expect(areas).toHaveCount(17)

		// `areas-summarise-before-they-expand`. THEN each area states how many
		// capabilities it holds and how dossiq scored, and AND its rows stay
		// collapsed until the reader opens it. This test used to count the
		// areas and stop, so a summary with the wrong numbers, or none, and an
		// area that opened on its own, all passed.
		//
		// The numbers are read as a sequence, not as a sentence, so the check
		// does not depend on the instance's language: total, then dossiq's
		// yes, partly and missing, in the order `areaSummary` states them.
		const expected = COMPARISON.areas.map((area: any) => {
			const rows = COMPARISON.capabilities.filter(
				(row: any) => row.area === area.key,
			)
			const count = (rating: string) =>
				rows.filter((row: any) => row.dossiq === rating).length
			return {
				key: area.key,
				total: rows.length,
				numbers: [rows.length, count('yes'), count('partial'), count('no')],
			}
		})
		await expect(areas).toHaveCount(expected.length)
		for (const [index, area] of expected.entries()) {
			const disclosure = areas.nth(index)
			await expect(
				disclosure,
				`area ${area.key} must start collapsed`,
			).not.toHaveAttribute('open')
			await expect(
				disclosure.locator('tbody tr').first(),
				`the rows of area ${area.key} must stay hidden until it is opened`,
			).toBeHidden()
			const summary = await disclosure
				.locator('.features-roadmap__area-count')
				.innerText()
			expect(
				(summary.match(/\d+/g) ?? []).map(Number),
				`area ${area.key} must state its capability count and how dossiq scored: "${summary}"`,
			).toEqual(area.numbers)
		}

		// Opening an area is what shows its rows, and all of them.
		const first = areas.first()
		await first.locator('summary').click()
		await expect(first).toHaveAttribute('open', '')
		await expect(first.locator('tbody tr')).toHaveCount(expected[0].total)
		await expect(first.locator('tbody tr').first()).toBeVisible()
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		// ⚠️ The testid is on the <li> WRAPPER, not the <a>. Asserting href on
		// the wrapper reads back null and fails against a real browser, which
		// is invisible to `playwright test --list`.
		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/dossiq$/,
		)
	})

	test('Case types and Map layers stay in the settings foldout', async ({
		page,
	}) => {
		// Both configure how cases behave rather than reporting on them, so
		// ADR-114 keeps them out of the four-item footer. Asserted directly so
		// a later promotion fails here rather than in the footer-order test,
		// where the cause would be much harder to read.
		const nav = page.locator('[data-testid="cn-nav"]')
		for (const id of ['CaseTypesMenu', 'WmsLayersMenu']) {
			await expect(
				nav.locator(`[data-testid="cn-nav-entry-${id}"]`),
			).toBeAttached({ timeout: 15_000 })
		}
	})
})
