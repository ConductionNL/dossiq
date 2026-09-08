/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case detail — the KPI row, the tabbed panels and the right column.
 *
 * Every assertion here is one that a unit test could not make, because each
 * covers a seam between the manifest, the widget catalog and a live register:
 *
 *  - the countdown reads a DATE off the loaded record and turns it into words;
 *  - the case-type tile resolves a uuid to the referenced object's title;
 *  - the tabs widget renders one panel per configured tab and mounts only the
 *    open one;
 *  - the hoisted Actions menu sits beside the strip rather than inside it.
 *
 * The empty-state trap is worth stating, because this page has now hit it
 * twice: a widget whose query 404s renders "No X yet", which is exactly what
 * an empty result looks like. So the console/5xx tracker is asserted too — a
 * green-looking page is not evidence on this surface.
 */

import { expect, test } from '@playwright/test'
import {
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	seedCase,
} from './helpers/fixtures.ts'
import { trackDossiqErrors } from './helpers/nav.ts'

/**
 * Every title this spec matches, in either locale the instance may run.
 *
 * Nothing forces the language of the E2E instance, and the sibling
 * case-detail-flow-runs spec already guards its title the same way. An exact
 * English string here would pass or fail on the instance's locale rather than
 * on the feature, which is the least useful thing a test can assert.
 */
const TAB_LABELS = [
	/Notes|Notities/,
	/Files|Bestanden/,
	/Related cases|Gerelateerde zaken/,
	/Sub-cases|Deelzaken/,
	/Mail/,
	/Appointments|Afspraken/,
	// Decisions is decidiq's widget, not dossiq's own list — dossiq no longer
	// renders its `decision` schema at all. Locations moved in from the page
	// body, so the case's collections all live in one strip.
	/Decisions|Besluiten|Besluitvorming/,
	// Parties took the slot the Contacts tab held. Contacts was the Nextcloud
	// contacts integration leaf: it showed the address book, never the people
	// on this case, and the `role` rows it was standing in for now have a tab
	// of their own (parties-on-the-case task 1.2).
	/Parties|Betrokkenen/,
	/Locations|Locaties/,
]

/**
 * Tab labels the strip must NOT carry.
 *
 * A removed tab leaves no trace: the widget is simply gone from the manifest
 * and the strip renders one panel fewer, which no assertion above would
 * notice. This is the half that fails when the Contacts entry comes back.
 */
const RETIRED_TAB_LABELS = [/^(Contacts|Contacten|Connected contacts)$/]

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
		const caseTypes = await listObjects(api, 'caseType')
		const withDeadline = caseTypes.filter((ct: any) => ct.processingDeadline)
		const chosen = withDeadline[0] ?? caseTypes[0]
		expect(chosen, 'the instance must ship at least one case type').toBeTruthy()
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

	test('the KPI row headlines time left, case type and completion', async ({
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

		const kpis = page.locator('.cn-kpi-card')
		await expect(kpis.first()).toBeVisible({ timeout: 30_000 })

		// Time left is COMPUTED from the deadline, not printed from it.
		await expect(page.locator('.cn-countdown-widget')).toContainText(
			/day(s)? left|dag(en)? te gaan/,
			{
				timeout: 15_000,
			},
		)

		// The headline is painted with a FOREGROUND token. CnCountdownWidget
		// writes `var(--color-error)` inline, which on Nextcloud 34 is the pale
		// FILL colour (#FFE7E7): "26 days overdue" was pink on white. dossiq
		// repoints the token on the widget root to its `-text` variant, so
		// whichever band the seeded deadline lands in, the computed colour must
		// match that band's `-text` token and never the raw fill token.
		const countdown = page.locator('.cn-countdown-widget')
		const paint = await countdown.evaluate((el) => {
			const value = el.querySelector('.cn-countdown-widget__value')
			const root = getComputedStyle(document.documentElement)
			const resolve = (token: string) => {
				const probe = document.createElement('span')
				probe.style.color = root.getPropertyValue(token).trim()
				document.body.appendChild(probe)
				const color = getComputedStyle(probe).color
				probe.remove()
				return color
			}
			const variant = (
				[...el.classList].find((c) => /^cn-countdown-widget--/.test(c)) || ''
			).replace('cn-countdown-widget--', '')
			return {
				variant,
				color: value ? getComputedStyle(value).color : '',
				fill: resolve(`--color-${variant}`),
				text: resolve(`--color-${variant}-text`),
			}
		})
		if (['error', 'warning', 'success'].includes(paint.variant)) {
			expect(paint.color, `${paint.variant} band paints the -text token`).toBe(
				paint.text,
			)
			expect(
				paint.color,
				`${paint.variant} band must not paint the fill`,
			).not.toBe(paint.fill)
		} else {
			expect(
				paint.variant,
				'a countdown on a case with a deadline has a band',
			).toBe('default')
		}

		// The case type field holds a uuid. Showing the uuid would be a pass for
		// "renders something" and a failure for the feature.
		const caseTypeCard = kpis.filter({ hasText: /Case type|Zaaktype/ })
		await expect(caseTypeCard).toContainText(caseTypeTitle, {
			timeout: 20_000,
		})
		// A uuid is 36 chars with four dashes; the tile must show a NAME.
		await expect(caseTypeCard).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)

		// Completion comes from the milestone endpoint. 0% is the honest answer
		// for a case type with no milestones, so asserting the NUMBER would prove
		// nothing: a failed fetch renders 0% too. The request itself is asserted
		// below, which is the part that can actually break.
		await expect(kpis.filter({ hasText: /Completed|Afgerond/ })).toContainText(
			/%/,
			{
				timeout: 20_000,
			},
		)

		// The milestone request must actually succeed. It used to fire twice: once
		// with an empty `@object.caseType` path segment before the record loaded
		// (404), then correctly. The tile showed 0% throughout, so only the
		// request tells the two apart.
		expect(
			milestoneCalls.length,
			`milestone requests: ${milestoneCalls.join(', ')}`,
		).toBeGreaterThan(0)
		expect(
			milestoneCalls.every((c) => Number(c.split(' ')[0]) < 400),
			`milestone requests: ${milestoneCalls.join(', ')}`,
		).toBe(true)

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

	test('a KPI tile shows its label once, without widget chrome', async ({
		page,
	}) => {
		// The KPI cells carry `showTitle: false`, because a KPI tile already
		// renders its own label inside the card. Without the flag the grid draws
		// a CnWidgetWrapper header on top — the title twice, and an Actions menu
		// on a read-only tile, which the Cards-vs-Widgets split says a card must
		// not have.
		//
		// This regressed once already: rebuilding the layout from a list of
		// tuples silently dropped the flag from five cells, and nothing failed.
		// Every gate passed and the E2E passed, because no assertion described
		// what a KPI tile is supposed to look like. This is that assertion.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const timeLeft = page.locator('.cn-countdown-widget')
		await expect(timeLeft).toBeVisible({ timeout: 20_000 })

		// The label appears exactly once in the tile's own cell.
		const cell = page
			.locator('.cn-widget-grid__item, .grid-stack-item')
			.filter({ has: page.locator('.cn-countdown-widget') })
			.first()
		const labelCount = await cell
			.getByText(/^(Time left|Resterende tijd)$/)
			.count()
		expect(labelCount, 'the KPI label must render once, not twice').toBe(1)

		// And a read-only tile carries no Actions menu of its own.
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

		await strip.getByRole('tab', { name: /Sub-cases|Deelzaken/ }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)

		// Switching back must not tear the first panel down, or every switch
		// refetches.
		await strip.getByRole('tab', { name: /Notes|Notities/ }).click()
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

		// Decisions and Locations MOVED into the tab strip. Asserting only that
		// the column shows three things would still pass if they had stayed and
		// the page simply grew, so assert they are gone from the body: their
		// only remaining owner is a tab.
		for (const gone of [/Decisions|Besluiten/, /Locations|Locaties/]) {
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

		// Locations moved into the tab strip, and tab panels are LAZY — the
		// widget does not mount, so it does not query, until its tab is opened.
		// Without this click the poll below times out on zero responses and
		// reads as "the schema 404s again", which is the very thing this spec
		// exists to tell apart from an empty state.
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip.getByRole('tab', { name: /Locations|Locaties/ }).click()

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
