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
const FOLDED_SECTIONS: Array<[string, string, 'registry' | 'integration']> = [
	['Documents', 'case-section-case-documents', 'registry'],
	['Documents', 'case-section-case-files', 'integration'],
	['People', 'case-section-case-roles', 'registry'],
	['People', 'case-section-case-communication', 'registry'],
	['Work', 'case-section-case-tasks', 'registry'],
	['Work', 'case-section-case-calendar', 'integration'],
	['Related', 'case-section-case-related', 'registry'],
	['Related', 'case-section-case-sub-cases', 'registry'],
	['Objects and locations', 'case-section-case-objects', 'registry'],
	['Objects and locations', 'case-section-case-locaties', 'registry'],
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

	test('the identity card carries its title and no actions menu', async ({
		page,
	}) => {
		// This assertion used to read the other way, and it flipped on purpose.
		//
		// The identity block was a full-width band carrying `showTitle: false`,
		// because facts laid out in a line label themselves and a heading over
		// them printed the same word twice. dossiq#2322 rebuilt it as a titled
		// card in the four-column rail, where the same facts stack instead: a
		// stacked group with no heading says nothing about what the group is,
		// so the cell now carries `showTitle: true` and the heading is the
		// design rather than the defect.
		//
		// The second half did NOT flip, and the reason is worth keeping. This
		// is a consumer slot widget (`#widget-case-header`), so CnDetailPage
		// renders a bare grid `<h3 class="cn-detail-page__widget-title">` for
		// it — not a CnWidgetWrapper header. Only the wrapper carries the
		// overflow Actions menu, and it nests that menu inside its own
		// `v-if="showTitle"` header, so a titled slot widget gains the heading
		// without gaining the menu. Read-only content still must not offer one.
		//
		// The absence half regressed once already on the tiles this row
		// replaced: rebuilding the layout from a list of tuples silently
		// dropped the flag from five cells, and nothing failed. Every gate
		// passed and the E2E passed, because no assertion described what the
		// cell is supposed to look like. This is still that assertion, pointed
		// at the shape the page has now.
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

		// The card names the group it stacks, exactly once.
		expect(
			await cell.getByText(/^(Case identity|Zaakgegevens)$/).count(),
			'the identity card must carry exactly one grid heading',
		).toBe(1)

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

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-strip-holds-six-tabs-and-no-more
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

		for (const [tabLabel, testid, kind] of FOLDED_SECTIONS) {
			await strip.getByRole('tab', { name: tabLabel, exact: true }).click()
			const panel = strip.locator(
				'.cn-tabs__content > [role="tabpanel"]:not([hidden])',
			)
			const section = panel.locator(`[data-testid="${testid}"]`)
			// ATTACHED, not visible, and the difference matters now that
			// `case-sections` hides an empty section's heading and drops its
			// divider: such a section has zero height, so `toBeVisible` would
			// fail on it for the very reason the component is correct. The
			// registry sections get the visibility check below, where it is a
			// real claim rather than a measurement of CSS.
			await expect(
				section,
				`${testid} did not render inside ${tabLabel}`,
			).toBeAttached({ timeout: 30_000 })

			// THE SECTION WRAPPER IS NOT THE EVIDENCE. CnDetailWidgetHost
			// renders NOTHING for a widget type it cannot resolve, so a broken
			// `case-sections` registration leaves a section that is present and
			// says nothing, and the visibility check above passes on it.
			//
			// The invariant, for BOTH kinds: a section is ABSENT OR NON-EMPTY,
			// never a heading over a void. `case-sections` hides the heading
			// until its host has content precisely so that state cannot exist,
			// and this is what holds it to that.
			//
			// One instrument, not two: the heading and the host are read in the
			// SAME evaluate, inside a poll. Reading the host with a snapshot and
			// the heading with a retrying matcher would compare two different
			// moments and could pass on either being briefly right.
			const state = async () =>
				await section.evaluate((el) => {
					const heading = el.querySelector('h3')
					const host = el.querySelector(':scope > *:not(h3)')
					const filled =
						!!host && (host.textContent || '').trim().length > 0
					if (!heading)
						return filled ? 'heading-missing' : 'empty-and-silent'
					return filled ? 'filled-with-heading' : 'heading-over-void'
				})

			await expect
				.poll(state, {
					timeout: 30_000,
					message: `${testid} must never be a heading over nothing, which is worse than the empty tab it replaced`,
				})
				.not.toBe('heading-over-void')

			// ⚠️ THE `integration` SECTIONS STOP HERE, AND THAT IS A NARROWING
			// RATHER THAN A WEAKENING, so: an integration leaf is served by
			// OpenRegister's global registry bundle, which CI NEVER HAS,
			// because openregister gitignores its `/js/`. Demanding content
			// from `case-files` or `case-calendar` asserts a property of the
			// environment rather than of this change, and it went red on CI
			// while passing locally for exactly that reason.
			//
			// The eight that resolve from dossiq's OWN bundle keep the full
			// assertion, and they are the eight a broken registration would
			// take down, which is the mutation this test was proved against. So
			// the teeth are still where the failure mode is.
			if (kind === 'registry') {
				await expect
					.poll(state, {
						timeout: 30_000,
						message: `${testid} rendered nothing, which is what a widget type the registry cannot resolve looks like`,
					})
					.toBe('filled-with-heading')
				await expect(
					section,
					`${testid} resolved but is not on screen`,
				).toBeVisible({ timeout: 30_000 })
			}
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
		// ATTACHED, not visible, and NOT compared by bounding box. The files
		// leaf is served by OpenRegister's global registry bundle, which CI
		// never has, and `case-sections` now hides an empty section's heading
		// and drops its divider, so on CI this section is correctly present and
		// zero-height. A box comparison would fail there for the very reason
		// the component is right, and it would be measuring CSS rather than the
		// claim, which is ORDER: the registered documents first, loose
		// attachments under them.
		await expect(files).toBeAttached({ timeout: 30_000 })

		const order = await panel.evaluate((el) => {
			const nodes = Array.from(
				el.querySelectorAll('[data-testid^="case-section-"]'),
			).map((n) => n.getAttribute('data-testid'))
			return {
				documents: nodes.indexOf('case-section-case-documents'),
				files: nodes.indexOf('case-section-case-files'),
			}
		})
		expect(
			order.documents,
			'the documents section is absent',
		).toBeGreaterThanOrEqual(0)
		expect(order.files, 'the files section is absent').toBeGreaterThanOrEqual(0)
		expect(
			order.documents,
			'the dossier must read before the loose attachments',
		).toBeLessThan(order.files)
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

		// The strip's OWN panels. A panel can contain another `role="tabpanel"`
		// — the related-objects widget renders a `<section>` with that role
		// inside its panel — and a descendant query counts that as a second
		// mounted panel.
		const mounted = () =>
			strip
				.locator('.cn-tabs__content > [role="tabpanel"]')
				.evaluateAll(
					(panels) => panels.filter((p) => p.children.length > 0).length,
				)

		await expect.poll(mounted, { timeout: 15_000 }).toBe(1)

		await strip.getByRole('tab', { name: 'Related', exact: true }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)

		// Switching BACK must not tear the first panel down, or every switch
		// refetches. Back to the tab that was open on load, which is what this
		// asserts: opening a THIRD tab would leave three panels mounted, since
		// staying mounted is the property under test, and the count would be
		// right while the scenario said nothing.
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
