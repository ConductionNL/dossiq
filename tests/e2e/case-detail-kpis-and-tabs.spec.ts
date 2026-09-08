/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case detail — the identity row, the tabbed panels and the right column.
 *
 * Every assertion here is one that a unit test could not make, because each
 * covers a seam between the manifest, the widget catalog and a live register:
 *
 *  - the countdown reads a DATE off the loaded record and turns it into words;
 *  - the identity row resolves a case-type uuid to the referenced title;
 *  - the stepper shows the case's STEP, and the milestone endpoint the
 *    retired Completed tile called is no longer asked at all;
 *  - the tabs widget renders one panel per configured tab and mounts only the
 *    open one;
 *  - the hoisted Actions menu sits beside the strip rather than inside it.
 *
 * The Time left and Case type TILES are gone (case-header, row A01). Both
 * facts now read in the identity row on the first grid cell, and this spec
 * asserts they read there AND that no tile of either kind survives beside it:
 * a fold that leaves the old tile standing prints the same fact twice, and
 * only the second half of that pair fails visibly.
 *
 * The empty-state trap is worth stating, because this page has now hit it
 * twice: a widget whose query 404s renders "No X yet", which is exactly what
 * an empty result looks like. So the console/5xx tracker is asserted too — a
 * green-looking page is not evidence on this surface.
 */

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	getRequestToken,
	objectId,
	REGISTER,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, trackDossiqErrors } from './helpers/nav.ts'

/**
 * The SIX tabs the strip holds, in order.
 *
 * These are exact strings and not locale alternatives, unlike almost every
 * other title this spec matches. A tab label is not translated: it is read
 * verbatim out of `content.tabs[].label` in the manifest, with no `t()`
 * anywhere on the path, so an instance running in Dutch shows these English
 * words too. The `|Gegevens` half of the old patterns could never match, and
 * a pattern that can only match one of its alternatives hides which one.
 */
const TAB_LABELS = [
	'Data',
	'Documents',
	'People',
	'Work',
	'Related',
	'Objects and locations',
]

/**
 * The panels that were folded INTO a tab, and the section each one is now.
 *
 * The count is the headline of this change, and a count is exactly the kind
 * of assertion that can be satisfied by deleting things. These are what make
 * the difference between six tabs and four missing features.
 */
const FOLDED_SECTIONS: Array<[string, string]> = [
	['Documents', 'case-section-case-documents'],
	['Documents', 'case-section-case-files'],
	['People', 'case-section-case-roles'],
	['People', 'case-section-case-communication'],
	['Work', 'case-section-case-tasks'],
	['Work', 'case-section-case-calendar'],
	['Related', 'case-section-case-related'],
	['Related', 'case-section-case-sub-cases'],
	['Objects and locations', 'case-section-case-objects'],
	['Objects and locations', 'case-section-case-locaties'],
]

/**
 * Tab labels the strip must NOT carry.
 *
 * A removed tab leaves no trace: the widget is simply gone from the manifest
 * and the strip renders one panel fewer, which a count alone would notice but
 * a name-based assertion would not. `Contacts` is the tab an earlier change
 * retired. The other four are the tabs THIS change retired, three of them
 * because a sidebar tab already carried the same thing, and re-adding one
 * looks like adding a feature rather than restoring a duplicate.
 */
const RETIRED_TAB_LABELS = [
	/^(Contacts|Contacten|Connected contacts)$/,
	/^(Notes|Notities)$/,
	/^Mail$/,
	/^(Decisions|Besluiten|Besluitvorming)$/,
	/^(Files|Bestanden)$/,
]

/**
 * The right column, top to bottom.
 *
 * `Flow runs` is development's widget, added independently while this branch
 * was open (#1615); it supersedes the `case-runs` this branch had. Only the
 * PLACEMENT is ours — the right column beside Hours, rather than the middle
 * cell it shipped in.
 */
const COLUMN_TITLES = [
	/Hours booked|Geboekte uren/,
	/Flow runs|Flow-uitvoeringen/,
	/Tasks|Taken/,
]

test.describe('Case detail — KPI row, tabbed panels, right column', () => {
	test.setTimeout(180_000)

	let caseId = ''
	let caseTypeTitle = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// REUSE a seeded case type; do not create one.
		//
		// The `case` schema is archival (x-openregister-archival), so a
		// user-driven DELETE is refused with 403 by design — Dutch archiving law,
		// not a bug. An earlier version of this spec created its own case type,
		// and cleanup then deleted that type while the undeletable case still
		// pointed at it. Every leftover case carried a dangling `caseType`, the
		// dashboard 404'd resolving it, and FIFTEEN unrelated specs went red:
		// settings pages, the workflow board, the case map, cases CRUD. A fixture
		// that cannot clean up after itself breaks its neighbours.
		//
		// `processingDeadline` is what makes the countdown testable: `case.deadline`
		// is COMPUTED by OpenRegister from the type's duration, so a type without
		// one yields no deadline and the tile correctly shows a dash.
		const caseTypes = await adoptableCaseTypes(api)
		const withDeadline = caseTypes.filter((ct: any) => ct.processingDeadline)
		const chosen = withDeadline[0] ?? caseTypes[0]
		expect(
			chosen,
			'the instance must ship at least one PUBLISHED case type — adoptableCaseTypes() excludes drafts (isDraft !== false) and fixture-owned rows',
		).toBeTruthy()
		caseTypeTitle = String(chosen.title ?? chosen.name ?? '')

		const seeded = await seedCase(api, token, {
			title: `E2E KPI case ${Date.now().toString(36)}`,
			caseType: objectId(chosen),
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseId = objectId(seeded)

		await api.dispose()
	})

	// No afterAll. The case cannot be deleted (archival, 403) and the case type
	// is not ours to remove, so there is nothing to tear down — and nothing is
	// left dangling either, which is the point.

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-progress-tile-is-gone
	test('the identity row headlines the case, and the KPI tiles are gone', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		const milestoneCalls: string[] = []
		const failures: Array<{ status: number; url: string }> = []
		page.on('response', (r) => {
			if (r.url().includes('milestones/progress')) {
				milestoneCalls.push(`${r.status()} ${r.url()}`)
			}
			if (r.status() >= 400) {
				failures.push({ status: r.status(), url: r.url() })
			}
		})
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)

		const header = page.getByTestId('case-header')
		await expect(header).toBeVisible({ timeout: 30_000 })

		// Time left is COMPUTED from the deadline, not printed from it. It used
		// to be its own KPI tile; case-header folded it into the identity row,
		// which is why this reads a testid rather than `.cn-countdown-widget`.
		const countdown = header.getByTestId('case-header-countdown')
		await expect(countdown).toContainText(/\d+ (days?|dagen?)/, {
			timeout: 15_000,
		})

		// The countdown is painted with a FOREGROUND token. The band tokens on
		// Nextcloud 34 are pale FILL colours (#FFE7E7 for error): "26 days
		// overdue" once rendered pink on white. The row paints `-text` with the
		// raw token only as a fallback, so a banded countdown must never come
		// out as the fill colour.
		const paint = await countdown.evaluate((el) => {
			const root = getComputedStyle(document.documentElement)
			const resolve = (token: string) => {
				const probe = document.createElement('span')
				probe.style.color = root.getPropertyValue(token).trim()
				document.body.appendChild(probe)
				const color = getComputedStyle(probe).color
				probe.remove()
				return color
			}
			const band = el.classList.contains('is-danger')
				? 'error'
				: el.classList.contains('is-warning')
					? 'warning'
					: ''
			return {
				band,
				color: getComputedStyle(el).color,
				fill: band ? resolve(`--color-${band}`) : '',
				text: band ? resolve(`--color-${band}-text`) : '',
			}
		})
		if (paint.band && paint.text && paint.fill && paint.text !== paint.fill) {
			expect(paint.color, `${paint.band} band paints the -text token`).toBe(
				paint.text,
			)
		}

		// The case type field holds a uuid. Showing the uuid would be a pass for
		// "renders something" and a failure for the feature.
		const caseTypeChip = header.getByTestId('case-header-casetype')
		await expect(caseTypeChip).toContainText(caseTypeTitle, { timeout: 20_000 })
		// A uuid is 36 chars with four dashes; the row must show a NAME.
		await expect(caseTypeChip).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)

		// The identity row also carries the case number and a status badge, so
		// the page says which case you are on without opening the Data tab.
		await expect(header.getByTestId('case-header-identifier')).toBeVisible({
			timeout: 15_000,
		})
		await expect(header.getByTestId('case-header-status')).toBeVisible({
			timeout: 15_000,
		})

		// Both folded tiles are GONE (case-header, row A01). Asserting only that
		// the row shows the two facts would still pass if the tiles had stayed
		// and the page simply grew, which is the duplication the fold retires.
		await expect(page.locator('.cn-countdown-widget')).toHaveCount(0)
		await expect(
			page.locator('.cn-kpi-card').filter({ hasText: /Case type|Zaaktype/ }),
		).toHaveCount(0)
		await expect(page.getByText(/^(Time left|Resterende tijd)$/)).toHaveCount(0)

		// The Completed tile is GONE (case-lifecycle-on-the-page, REQ-CDV-13).
		// It read milestone progress, milestones are configured on almost no
		// case type, and it therefore showed 0% on every case — an honest number
		// that told a handler nothing. The stepper over the case type's statuses
		// took its cell; the milestone endpoint stays for its other readers.
		//
		// Both halves are asserted, because either alone passes for the wrong
		// reason: a tile can be removed from the layout while its widget still
		// fires the request, and a request can stop while the tile still renders
		// from cache.
		await expect(
			page.locator('.cn-kpi-card').filter({ hasText: /Completed|Afgerond/ }),
		).toHaveCount(0)
		await expect(page.getByTestId('case-steps')).toBeVisible({ timeout: 20_000 })
		expect(
			milestoneCalls,
			'the case page no longer asks for milestone progress',
		).toEqual([])

		// Assert on the RESPONSES, not on the console text.
		//
		// The console message for a failed fetch is "Failed to load resource: the
		// server responded with a status of 404" with no URL in it, so a console
		// assertion can say only THAT something 404'd, never WHAT. That is not
		// enough to tell the defect this guard exists for — a dossiq query whose
		// slug is wrong, which renders as an empty state — apart from an optional
		// app that is simply not installed on this instance, where 404 is the
		// correct answer.
		//
		// So: nothing may 5xx, and nothing dossiq owns may 4xx. Requests to apps
		// this instance does not have are allowed, and named rather than matched
		// loosely, so installing one of them here turns its failures back on.
		const ABSENT_APPS = /\/apps\/(hermiq|humaniq)\//
		const OWN = /\/apps\/dossiq\/|\/objects\/dossiq\//
		const serverErrors = failures.filter((f) => f.status >= 500)
		// The CMMN panel probes whether this case is CMMN-managed and is answered
		// 409 for a BPMN-managed one. That is the app saying "no", not failing,
		// and it is a dossiq URL — so it has to be excluded HERE, not only from
		// the console list, or this assertion fails on correct behaviour.
		const ownClientErrors = failures.filter(
			(f) =>
				f.status >= 400
				&& f.status < 500
				&& OWN.test(f.url)
				&& !(f.status === 409 || /cmmn-plan/.test(f.url)),
		)
		expect(
			serverErrors,
			`5xx: ${serverErrors.map((f) => `${f.status} ${f.url}`).join(' | ')}`,
		).toEqual([])
		expect(
			ownClientErrors,
			`dossiq 4xx: ${ownClientErrors.map((f) => `${f.status} ${f.url}`).join(' | ')}`,
		).toEqual([])

		// KNOWN GAP, deliberately not asserted away. The hours tile queries
		// humaniq's register, so on an instance without humaniq it 404s and
		// renders 0 — indistinguishable from a real zero. The `Log hours` action
		// beside it IS gated on `visibleWhen: { appInstalled: "humaniq" }`; the
		// tile cannot be, because the manifest schema allows `visibleWhen` on
		// actions and fields but not on a widget or a layout cell. Closing it
		// needs that gating in nextcloud-vue's CnDetailPage, not a change here.
		const absent = failures.filter((f) => ABSENT_APPS.test(f.url))
		if (absent.length > 0) {
			console.log(
				`absent-app requests (expected on this instance): ${absent
					.map((f) => `${f.status} ${f.url}`)
					.join(' | ')}`,
			)
		}

		// The CMMN panel probes whether this case is CMMN-managed and gets a 409
		// for a BPMN-managed one, which is the app answering "no" rather than
		// failing.
		const unexpected = errors.filter(
			(e) => !/\b409\b|cmmn-plan|Failed to load resource/.test(e),
		)
		expect(unexpected, `console errors: ${unexpected.join(' | ')}`).toEqual([])
	})

	test('the identity row carries no widget chrome of its own', async ({
		page,
	}) => {
		// The identity row's cell carries `showTitle: false`, because the row
		// already labels every fact it shows. Without the flag the grid draws a
		// CnWidgetWrapper header on top: the widget title above a row that names
		// itself, plus an Actions menu on read-only content, which the
		// Cards-vs-Widgets split says a card must not have.
		//
		// This regressed once already on the tiles this row replaced: rebuilding
		// the layout from a list of tuples silently dropped the flag from five
		// cells, and nothing failed. Every gate passed and the E2E passed,
		// because no assertion described what the cell is supposed to look like.
		// This is that assertion.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const header = page.getByTestId('case-header')
		await expect(header).toBeVisible({ timeout: 20_000 })

		const cell = page
			.locator('.cn-widget-grid__item, .grid-stack-item')
			.filter({ has: page.getByTestId('case-header') })
			.first()

		// The widget title does not print above a row that labels itself.
		expect(
			await cell.getByText(/^(Case identity|Zaakgegevens)$/).count(),
			'the identity row must not carry a grid heading',
		).toBe(0)

		// And read-only content carries no Actions menu of its own.
		await expect(
			cell.getByRole('button', { name: /^(Actions|Acties)$/ }),
		).toHaveCount(0)
	})

	test('the tabs widget renders one tab per configured panel', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		for (const label of TAB_LABELS) {
			await expect(strip.getByRole('tab', { name: label })).toBeVisible({
				timeout: 15_000,
			})
		}

		for (const label of RETIRED_TAB_LABELS) {
			await expect(strip.getByRole('tab', { name: label })).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#the-five-work-tabs-come-first-in-order
	test('the strip holds exactly six tabs, in order, and no more', async ({
		page,
	}) => {
		// THE NUMBER IS THE FEATURE. The strip grew from ten tabs to fourteen
		// over one programme while the app menu held at four, because the menu
		// had a stated ceiling and the strip had nothing counting it. This is
		// the thing that counts it, and it has to be an exact count: asserting
		// that six named tabs are PRESENT would pass on a strip of nine.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await expect(strip.getByRole('tab')).toHaveCount(TAB_LABELS.length)

		const labels = (await strip.getByRole('tab').allInnerTexts()).map((l) =>
			l.trim(),
		)
		expect(labels).toEqual(TAB_LABELS)

		for (const label of RETIRED_TAB_LABELS) {
			await expect(strip.getByRole('tab', { name: label })).toHaveCount(0)
		}
	})

	test('every folded panel still renders, inside the tab it moved to', async ({
		page,
	}) => {
		// The half of the fold that fails silently. `case-sections` renders its
		// children through CnDetailWidgetHost, which renders NOTHING and logs
		// nothing for a type it cannot resolve, and a section whose widget did
		// not resolve leaves a tab that opens onto an empty panel. Four tabs
		// that each dropped half their content would satisfy the count test
		// above and look like a successful consolidation.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		// The support dialog and the first-run wizard each mount a modal mask
		// that swallows every click behind it, and a swallowed click reports as
		// a locator timeout, which reads here as a missing tab. Dismissed AFTER
		// the navigation, because that is when they mount.
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		for (const [tabLabel, testid] of FOLDED_SECTIONS) {
			await strip.getByRole('tab', { name: tabLabel, exact: true }).click()
			const panel = strip.locator(
				'.cn-tabs__content > [role="tabpanel"]:not([hidden])',
			)
			const section = panel.locator(`[data-testid="${testid}"]`)
			await expect(
				section,
				`${testid} did not render inside ${tabLabel}`,
			).toBeVisible({ timeout: 30_000 })

			// The section WRAPPER is not the evidence. `case-sections` renders
			// the heading and the host element whether or not the child
			// resolved, and CnDetailWidgetHost renders NOTHING for a type it
			// cannot resolve, so a broken registration leaves a headed, empty
			// block and the visibility check above passes on it. Assert the
			// host has content: a widget that resolved renders its rows or its
			// empty state, and one that did not renders an empty div.
			const host = section.locator('> *:not(h3)')
			await expect(
				host,
				`${testid} rendered its heading and nothing under it, which is what a widget type the registry cannot resolve looks like`,
			).not.toBeEmpty({ timeout: 30_000 })
		}
	})

	test('Files is a section of Documents, not a tab of its own', async ({
		page,
	}) => {
		// Files did not leave the page, it left the STRIP. Dropping it would
		// have taken the files leaf's share and comment surface with it, which
		// design D5 was right to protect and which the dossier list does not
		// have. The order inside the tab says which one is the case file: the
		// registered documents first, loose attachments under them.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await expect(
			strip.getByRole('tab', { name: /^(Files|Bestanden)$/ }),
		).toHaveCount(0)

		await strip.getByRole('tab', { name: 'Documents', exact: true }).click()
		const panel = strip.locator(
			'.cn-tabs__content > [role="tabpanel"]:not([hidden])',
		)

		const documents = panel.locator(
			'[data-testid="case-section-case-documents"]',
		)
		const files = panel.locator('[data-testid="case-section-case-files"]')
		await expect(documents).toBeVisible({ timeout: 30_000 })
		await expect(files).toBeVisible({ timeout: 30_000 })

		const documentsBox = await documents.boundingBox()
		const filesBox = await files.boundingBox()
		expect(documentsBox, 'the documents section has no box').toBeTruthy()
		expect(filesBox, 'the files section has no box').toBeTruthy()
		expect(documentsBox!.y).toBeLessThan(filesBox!.y)
	})

	test('the Actions menu sits beside the strip, not inside the tablist', async ({
		page,
	}) => {
		// A control nested in role="tablist" is announced as one of the tabs, so
		// a reader counting six tabs would hear seven.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await expect(strip.locator('.cn-tabs__nav-end')).toBeVisible()
		await expect(
			strip.locator('[role="tablist"] .cn-tabs__nav-end'),
		).toHaveCount(0)
	})

	test('only the open tab mounts, and a switched-to tab stays mounted', async ({
		page,
	}) => {
		// Six eager panels would fire six requests on load to answer five
		// questions nobody asked. This is the assertion that keeps them lazy.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		const mounted = () =>
			strip
				.locator('[role="tabpanel"]')
				.evaluateAll(
					(panels) => panels.filter((p) => p.children.length > 0).length,
				)

		await expect.poll(mounted, { timeout: 15_000 }).toBe(1)

		await strip.getByRole('tab', { name: 'Related', exact: true }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)

		// Switching back must not tear the first panel down, or every switch
		// refetches.
		await strip.getByRole('tab', { name: 'Data', exact: true }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)
	})

	test('the right column carries the case collections with their own chrome', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		for (const title of COLUMN_TITLES) {
			await expect(page.getByText(title).first()).toBeVisible({
				timeout: 20_000,
			})
		}

		// Locations MOVED into the tab strip. Asserting only that the column
		// shows three things would still pass if it had stayed and the page
		// simply grew, so assert it is gone from the body: its only remaining
		// owner is a section of the Objects and locations tab. Decisions is no
		// longer checked here because it is no longer on the body at all: it
		// duplicated the Besluitvorming sidebar tab and the body copy went.
		for (const gone of [/Locations|Locaties/]) {
			await expect(
				page.locator('.cn-widget-wrapper').filter({ hasText: gone }),
			).toHaveCount(0)
		}
	})

	test('the Locations widget can actually query its schema', async ({ page }) => {
		// This widget rendered "No locations linked to this case yet" for weeks
		// because its schema slug 404'd — the empty state and the broken state
		// look identical, so assert the REQUEST, not the text.
		const responses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/objects/dossiq/case-location'))
				responses.push(r.status())
		})

		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		// Locations is a SECTION of the Objects and locations tab, and tab
		// panels are LAZY: the widget does not mount, so it does not query,
		// until its tab is opened. Without this the poll below times out on
		// zero responses and reads as "the schema 404s again", which is the
		// very thing this spec exists to tell apart from an empty state.
		await openCasePanel(page, 'locations')

		await expect
			.poll(() => responses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(
			responses.every((s) => s < 400),
			`statuses: ${responses.join(',')}`,
		).toBe(true)
	})

	// @e2e openspec/specs/ncvue-w2-leaves-adoption/spec.md
	test('the sidebar Notes tab renders the notes leaf, not an unknown element', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		// NcAppSidebar renders its own toggle while closed; open it when so.
		const toggle = page.locator('.app-sidebar__toggle')
		if (await toggle.isVisible()) await toggle.click()
		const sidebar = page.locator('.app-sidebar')
		await expect(sidebar).toBeVisible({ timeout: 15_000 })

		// The leaf this tab renders comes from dossiq's OWN bundle
		// (`builtinIntegrations` in @conduction/nextcloud-vue, resolved by
		// `leafTab('notes')`), not from OpenRegister's global registry bundle,
		// which CI never has (openregister gitignores `/js/`). Probe that
		// registry anyway and record the answer, so a failure here can be read
		// against it, but do not skip on it: the tab must render without it.
		const registryHasNotes = await page.evaluate(() => {
			const w = window as unknown as {
				OCA?: {
					OpenRegister?: {
						integrations?: { has?: (id: string) => boolean }
					}
				}
			}
			const registry = w.OCA?.OpenRegister?.integrations
			return registry ? Boolean(registry.has?.('notes')) : null
		})
		test.info().annotations.push({
			type: 'openregister-integrations-registry',
			description:
				registryHasNotes === null
					? 'absent (no global registry bundle on this instance)'
					: `has('notes') = ${registryHasNotes}`,
		})

		// By id, not label: NcAppSidebarTab renders `#tab-button-<id>` for the
		// manifest tab id, which is the same on an English and a Dutch instance.
		const tabButton = sidebar.locator('#tab-button-notes')
		test.skip(
			(await tabButton.count()) === 0,
			'the case sidebar declares no `notes` tab in this build; nothing to render',
		)
		await tabButton.click()
		const panel = sidebar.locator('[data-testid="cn-object-sidebar-tab-notes"]')
		await expect(panel).toBeVisible({ timeout: 15_000 })

		// The tab used to write its resolved leaf as a TAG, which Vue emits as
		// a literal unknown element with nothing inside. Assert the element is
		// absent AND that real content mounted, so an empty panel cannot pass.
		await expect(panel.locator('cnnotestabcomponent')).toHaveCount(0)
		await expect
			.poll(() => panel.evaluate((el) => el.querySelectorAll('*').length), {
				timeout: 15_000,
			})
			.toBeGreaterThan(1)
		// And the thing that mounted is the notes leaf itself, not the panel's
		// own chrome: CnNotesTab renders a `.cn-sidebar-tab` root with the
		// add-note composer inside it.
		await expect(panel.locator('.cn-sidebar-tab').first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(panel.locator('.cn-sidebar-tab__composer').first()).toBeVisible(
			{ timeout: 15_000 },
		)
	})
})
