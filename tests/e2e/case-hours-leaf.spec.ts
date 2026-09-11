/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case detail, the hours surface: dossiq places it, humaniq renders it.
 *
 * WHAT CHANGED, AND WHY THIS FILE EXISTS
 * --------------------------------------
 * `case-kpis-hours` used to be a `stats-block` that summed humaniq's
 * `TimeEntry` register straight out of dossiq's manifest. On an install
 * without humaniq that query 404s and the tile renders `0`, which is exactly
 * what a case with no hours booked renders. ADR-113 names that class: no
 * error, no empty state, nothing a reader would notice.
 *
 * The widget is now `{"type": "integration", "integrationId":
 * "humaniq-hours"}`. A leaf whose app is absent is never registered, so
 * CnDetailWidgetHost resolves no renderer and the cell stays empty. The
 * surface goes missing rather than lying.
 *
 * WHICH HALF RUNS WHERE
 * ---------------------
 * dossiq's CI instance installs openregister and nothing else
 * (`.github/workflows/code-quality.yml`, `additional-apps`), so the absence
 * half is the half CI can prove. The journey half needs humaniq, and it is
 * registered only when the runner says so with `DOSSIQ_E2E_HUMANIQ=1`.
 *
 * That flag is deliberately not a `test.skip()`. A skipped test reads like a
 * passed one in every summary that counts failures, so the two halves are
 * never both collected: the file prints which half it registered and why, and
 * each half then VERIFIES the flag against the live instance in `beforeAll`.
 * Declare humaniq where it is not enabled and the journey fails naming the
 * apps it looked for; leave the flag off where humaniq IS enabled and the
 * absence half fails naming the flag to set. Neither state can pass quietly.
 *
 * THE JOURNEY HALF HAS NOW RUN. That bundle used to be unshipped, and this
 * comment used to say so. It ships: `humaniq/js/humaniq-leaves.js` carries
 * `humaniq-hours` and the `hq-hours-*` hooks, and on an instance with humaniq
 * enabled the leaf mounts, reads and books. First run 2026-09-11.
 *
 * That first run found a defect in this file rather than in the leaf, which is
 * the usual result of running a test nobody has run: see `tryReadFigure`.
 *
 * 🔴 IT STILL DOES NOT RUN IN CI. The journey half registers only under
 * `DOSSIQ_E2E_HUMANIQ=1`, and nothing in this repo or in the shared workflow
 * sets it, so every assertion below is one no pipeline executes. CI installs
 * openregister and nothing else, so enabling it there means adding humaniq to
 * `additional-apps` as well as setting the flag.
 *
 * WHERE THE CITATIONS POINT. The delta is not synced into `openspec/specs/`
 * yet, so a citation naming the canonical path would resolve to nothing. They
 * name the change instead. Archiving it moves the spec, and these five `@e2e`
 * lines have to move with it, or they resolve to nothing the other way round:
 * dossiq#2057 left 119 citations doing exactly that.
 *
 * @spec openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	getRequestToken,
	objectId,
	REGISTER,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/**
 * The app ids humaniq answers to.
 *
 * The fleet rename moves an app's id one app at a time and `<id>` in
 * `appinfo/info.xml` is the only authority, so both are probed and the one
 * that was found is printed. Matching only the new id would report an
 * instance running the old one as "humaniq absent", and the absence half
 * would then pass for the wrong reason.
 */
const HUMANIQ_APP_IDS = ['humaniq', 'hrmq']

/** The widget title as the manifest declares it, in either locale. */
const HOURS_TITLE = /Hours booked|Geboekte uren/

/** Every stable hook the leaf publishes, so a rename here is one edit. */
const HOOK = {
	widget: 'hq-hours-widget',
	caption: 'hq-hours-caption',
	total: 'hq-hours-total',
	own: 'hq-hours-own',
	timer: 'hq-hours-timer',
	running: 'hq-hours-running',
	book: 'hq-hours-book',
	view: 'hq-hours-view',
	dialog: 'hq-hours-booking-dialog',
	date: 'hq-hours-booking-date',
	hours: 'hq-hours-booking-hours',
	description: 'hq-hours-booking-description',
	submit: 'hq-hours-booking-submit',
}

/** The hours booked by this run's booking test. */
const BOOKED_HOURS = 2.5

/**
 * The `<app>:<schema>` literal the leaf must derive for a dossiq case.
 *
 * Nothing in `src/manifest.json` declares it. The widget host forwards
 * `register`, `schema` and `objectId` to every leaf it mounts, and this page's
 * config carries `dossiq` and `case`, so the literal comes out `dossiq:case`.
 * Hard-coding the expectation here is the point: if the derivation ever starts
 * reading the widget definition instead, this is what catches it.
 */
const HOST_TYPE = 'dossiq:case'

/** One host-filtered read the leaf made, as it went over the wire. */
type HostRead = { url: string; type: string | null; ref: string | null }

/** One host-referencing write the leaf made, as it went over the wire. */
type HostWrite = { body: string; type: unknown; ref: unknown }

/**
 * Collect every OpenRegister object read that carries a host-object filter.
 *
 * Attach BEFORE navigating: the leaf's first read fires as it mounts.
 *
 * A read with no `domainObjectRef` is deliberately not collected, so an
 * unfiltered leaf produces an EMPTY list rather than a list that passes a
 * per-item check vacuously. The empty list is the failure.
 *
 * @param page The page to listen on.
 * @return The list, filled as requests are made.
 */
function collectHostFilteredReads(page: Page): HostRead[] {
	const reads: HostRead[] = []
	page.on('request', (request) => {
		if (request.method() !== 'GET') return
		const url = new URL(request.url())
		if (!url.pathname.includes('/apps/openregister/api/objects/')) return
		if (!url.searchParams.has('domainObjectRef')) return
		reads.push({
			url: request.url(),
			type: url.searchParams.get('domainObjectType'),
			ref: url.searchParams.get('domainObjectRef'),
		})
	})
	return reads
}

/**
 * Collect every OpenRegister object write whose body names a host object.
 *
 * @param page The page to listen on.
 * @return The list, filled as requests are made.
 */
function collectHostWrites(page: Page): HostWrite[] {
	const writes: HostWrite[] = []
	page.on('request', (request) => {
		if (request.method() !== 'POST') return
		if (!request.url().includes('/apps/openregister/api/objects/')) return
		const body = request.postData() ?? ''
		let parsed: Record<string, unknown>
		try {
			parsed = JSON.parse(body) as Record<string, unknown>
		} catch {
			return
		}
		if (parsed.domainObjectRef === undefined) return
		writes.push({
			body,
			type: parsed.domainObjectType,
			ref: parsed.domainObjectRef,
		})
	})
	return writes
}

const FLAG = (process.env.DOSSIQ_E2E_HUMANIQ ?? '').trim().toLowerCase()

/** Whether the runner declared humaniq present on the instance under test. */
const HUMANIQ_DECLARED = ['1', 'true', 'yes', 'on'].includes(FLAG)

console.log(
	`[dossiq e2e] hours leaf: DOSSIQ_E2E_HUMANIQ=${FLAG === '' ? '(unset)' : FLAG}, `
		+ `so the ${HUMANIQ_DECLARED ? 'FULL JOURNEY' : 'ABSENCE'} half is registered.`
		+ (HUMANIQ_DECLARED
			? ''
			: ' Set DOSSIQ_E2E_HUMANIQ=1 on an instance that has humaniq enabled to run the journey instead.'),
)

/**
 * The humaniq app id this instance has enabled, or null.
 *
 * Asks the instance rather than trusting the flag. The OCS apps list is
 * admin-only and the suite's stored session is the admin's; the
 * `OCS-APIRequest` header is load-bearing, without it Nextcloud answers 412
 * and the app list comes back empty, which reads exactly like "humaniq is not
 * installed".
 *
 * @param api An authenticated request context.
 * @return The enabled app id, or null when neither is enabled.
 */
async function enabledHumaniqApp(api: APIRequestContext): Promise<string | null> {
	const response = await api.get('/ocs/v2.php/cloud/apps?filter=enabled', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	expect(
		response.ok(),
		`the OCS apps list must answer, or this file cannot tell humaniq's absence from its own broken probe (HTTP ${response.status()})`,
	).toBeTruthy()
	const body = await response.json()
	const apps: string[] = body?.ocs?.data?.apps ?? []
	expect(
		apps.length,
		'the OCS apps list came back empty, so the probe is broken rather than the instance bare',
	).toBeGreaterThan(0)
	return HUMANIQ_APP_IDS.find((id) => apps.includes(id)) ?? null
}

/**
 * Seed one case to open, adopting a published case type.
 *
 * Adoption rather than creation, for the reason
 * `case-detail-kpis-and-tabs.spec.ts` records: `dossiq/case` is archival, so a
 * seeded case cannot be deleted afterwards, and a case type this spec removed
 * would leave every leftover case pointing at nothing.
 *
 * @param api An authenticated request context.
 * @return The seeded case uuid.
 */
async function seedHoursCase(api: APIRequestContext): Promise<string> {
	const token = await getRequestToken(api)
	const caseTypes = await adoptableCaseTypes(api)
	const chosen = caseTypes[0]
	expect(
		chosen,
		'the instance must ship at least one published case type, or there is no case to open',
	).toBeTruthy()

	const seeded = await seedCase(api, token, {
		title: `E2E hours case ${Date.now().toString(36)}`,
		caseType: objectId(chosen),
		startDate: new Date().toISOString().slice(0, 10),
	})
	return objectId(seeded)
}

/**
 * Open a case detail page and wait for the page shell.
 *
 * @param page   The page.
 * @param caseId The seeded case uuid.
 */
async function openCase(page: Page, caseId: string): Promise<void> {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
	await expect(
		page.locator('.cn-detail-page'),
		'the case detail page must render, or nothing below is an observation about the hours surface',
	).toBeVisible({ timeout: 30_000 })
	// The support dialog and the setup wizard both mask every click behind them.
	await dismissSupportDialog(page)
}

/**
 * Read the number a leaf figure prints, or null while it prints none.
 *
 * The leaf formats its own figures and may print a unit or a Dutch decimal
 * comma, so the number is extracted rather than compared as text.
 *
 * NULL RATHER THAN A THROW, because this is what a poll calls. The tile
 * prints an en-dash placeholder while it refetches after a write, and that
 * state is transient by design. `readFigure` throws on it, and a throw inside
 * `expect.poll`'s function ABORTS the poll instead of retrying it, so the
 * first ever run of this spec died on a placeholder that would have been a
 * number a moment later. Returning null lets the matcher fail and the poll
 * retry; a placeholder that never resolves still fails, on the budget.
 *
 * @param figure The locator holding the figure.
 * @return The number, or null when the figure prints no digits yet.
 */
async function tryReadFigure(figure: Locator): Promise<number | null> {
	const text = (await figure.innerText()).trim()
	const match = text.match(/-?\d+(?:[.,]\d+)?/)
	return match === null ? null : Number(String(match[0]).replace(',', '.'))
}

/**
 * Read the number a leaf figure prints, failing when it does not print one.
 *
 * For ONE-SHOT reads, where a placeholder is a real failure. Inside an
 * `expect.poll` use `tryReadFigure`: this one throws, and a throw inside the
 * polled function aborts the poll rather than retrying it.
 *
 * @param figure The locator holding the figure.
 * @param what   What the figure is, for the failure message.
 * @return The number.
 */
async function readFigure(figure: Locator, what: string): Promise<number> {
	const text = (await figure.innerText()).trim()
	const match = text.match(/-?\d+(?:[.,]\d+)?/)
	expect(
		match,
		`${what} must print a number, and it printed "${text}"`,
	).not.toBeNull()
	return Number(String(match?.[0]).replace(',', '.'))
}

/**
 * Fill a leaf form field whether its hook sits on the control or its wrapper.
 *
 * nc-vue field components carry `inheritAttrs: false` inconsistently, so a
 * `data-testid` can land on either. Resolving the control is locator work, not
 * an assertion: when neither shape matches, `fill()` still fails loudly.
 *
 * @param field The located field.
 * @param value The value to type.
 */
async function fillField(field: Locator, value: string): Promise<void> {
	const inner = field.locator('input, textarea')
	const target = (await inner.count()) > 0 ? inner.first() : field
	await target.fill(value)
}

if (!HUMANIQ_DECLARED) {
	test.describe('Case detail, no humaniq and so no hours surface', () => {
		test.setTimeout(180_000)

		let caseId = ''

		test.beforeAll(async ({ playwright, baseURL }) => {
			const api = await playwright.request.newContext({ baseURL })
			const found = await enabledHumaniqApp(api)
			expect(
				found,
				`humaniq is enabled here as "${found}", so a page with no hours surface is not the truth on this instance. `
					+ 'Re-run with DOSSIQ_E2E_HUMANIQ=1 to exercise the journey instead.',
			).toBeNull()
			console.log(
				`[dossiq e2e] hours leaf: none of ${HUMANIQ_APP_IDS.join(', ')} is enabled, `
					+ 'so the case page must render no hours surface at all.',
			)

			caseId = await seedHoursCase(api)
			await api.dispose()
		})

		// No afterAll: the case is archival and cannot be deleted, and the case
		// type is adopted rather than owned. Nothing is left dangling either.

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#the-surface-is-absent-when-humaniq-is
		test('the page renders its own widgets and no hours surface at all', async ({
			page,
		}) => {
			await openCase(page, caseId)

			// The page is loaded and populated, so an absent surface is a
			// decision rather than a render that has not happened yet. Both of
			// these are dossiq's own and neither depends on humaniq.
			await expect(
				page.getByTestId('case-header'),
				'the identity row must render, or the page is simply not finished loading',
			).toBeVisible({ timeout: 30_000 })
			await expect(
				page.locator('.cn-tabs-widget'),
				'the tab strip must render, or the page is simply not finished loading',
			).toBeVisible({ timeout: 30_000 })

			await expect(
				page.getByTestId(HOOK.widget),
				'humaniq is absent, so its hours leaf must not be on the page',
			).toHaveCount(0)

			// The heading is the tell that matters, and it belongs to the leaf
			// now: humaniq renders its own `<h3>` caption, because a mount-mode
			// leaf is handed no title. So it goes when the leaf goes. A cell that
			// still printed "Hours booked" over an empty body would be the
			// reserved void ADR-062 forbids, and one that printed it over a `0`
			// would be the ADR-113 lie this change removed.
			await expect(
				page.getByTestId(HOOK.caption),
				"the caption is drawn inside humaniq's leaf, so it cannot render without it",
			).toHaveCount(0)
			await expect(
				page.getByText(HOURS_TITLE),
				'no hours heading may render when the leaf behind it is not registered, by any route',
			).toHaveCount(0)

			// The booking affordances belong to the leaf, so they go with it.
			for (const hook of [HOOK.book, HOOK.view, HOOK.timer]) {
				await expect(
					page.getByTestId(hook),
					`${hook} belongs to humaniq's leaf, which is not registered here`,
				).toHaveCount(0)
			}
		})

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#no-cross-app-register-query-survives
		test('the page asks humaniq for nothing', async ({ page }) => {
			// This is the regression the change exists to prevent, and it is
			// assertable exactly where humaniq is absent: a manifest query
			// against another app's register 404s and renders `0`. Assert on the
			// REQUESTS, because the rendered result of that query is
			// indistinguishable from a case with no hours booked.
			const asked: string[] = []
			page.on('request', (request) => {
				const url = request.url()
				if (
					/\/apps\/(humaniq|hrmq)\/|\/objects\/(humaniq|hrmq)\//.test(url)
				) {
					asked.push(url)
				}
			})

			await openCase(page, caseId)
			await expect(page.getByTestId('case-header')).toBeVisible({
				timeout: 30_000,
			})

			expect(
				asked,
				`the case page queried humaniq: ${asked.join(' | ')}. Hours are placed as a leaf, not read out of another app's register.`,
			).toEqual([])
		})
	})
} else {
	test.describe("Case detail, humaniq's hours leaf on the page", () => {
		// Serial: the booking test changes the total the timer test reads back,
		// and each test opens the same seeded case.
		test.describe.configure({ mode: 'serial' })
		test.setTimeout(180_000)

		let caseId = ''

		test.beforeAll(async ({ playwright, baseURL }) => {
			const api = await playwright.request.newContext({ baseURL })
			const found = await enabledHumaniqApp(api)
			expect(
				found,
				`DOSSIQ_E2E_HUMANIQ declared humaniq present, but none of ${HUMANIQ_APP_IDS.join(', ')} is enabled on this instance. `
					+ 'Enable it, or unset the flag to assert the absence instead.',
			).not.toBeNull()
			console.log(
				`[dossiq e2e] hours leaf: humaniq is enabled here as "${found}", so the full journey runs.`,
			)

			caseId = await seedHoursCase(api)
			await api.dispose()
		})

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#hours-render-on-a-case-with-humaniq-installed
		test("the tile leads with the hours on the case and the caller's own beneath", async ({
			page,
		}) => {
			await openCase(page, caseId)

			const widget = page.getByTestId(HOOK.widget)
			await expect(
				widget,
				'humaniq is enabled, so its hours leaf must render in the widget cell',
			).toBeVisible({ timeout: 30_000 })

			// THE TILE NAMES ITSELF. A KPI card that is only a number says
			// nothing about what was counted, and this cell cannot be titled from
			// outside: CnDetailWidgetHost hands a mount-mode leaf its surface,
			// register, schema, objectId and integration context, and no title.
			// So the caption is the leaf's own `<h3>`, and it is a requirement of
			// the tile rather than decoration on it.
			await expect(
				widget.getByTestId(HOOK.caption),
				'the tile must name what it counts, or it is a bare number',
			).toBeVisible()
			await expect(
				widget.getByTestId(HOOK.caption),
				'the caption must read as the hours heading, in either locale',
			).toHaveText(HOURS_TITLE)

			await expect(
				widget.getByTestId(HOOK.total),
				'the headline is the total booked on this case',
			).toBeVisible()
			await expect(
				widget.getByTestId(HOOK.own),
				"the caller's own hours read beneath the headline",
			).toBeVisible()

			// Both figures are numbers, not a spinner and not a dash. A tile that
			// renders its labels while its figures never arrive looks right in a
			// screenshot and says nothing.
			await readFigure(widget.getByTestId(HOOK.total), 'the total')
			await readFigure(widget.getByTestId(HOOK.own), "the caller's own hours")

			// The three affordances the leaf places on the tile.
			await expect(
				widget.getByTestId(HOOK.timer),
				'the timer button must be on the tile',
			).toBeVisible()
			await expect(
				widget.getByTestId(HOOK.book),
				'Book hours must be on the tile',
			).toBeVisible()
			// `administrationUrl()` in humaniq's `src/integrations/hoursApi.js`
			// builds this as `/apps/humaniq/time-entries` with the filter query
			// appended, so the pattern is unanchored on the right. The `hrmq`
			// alternative cannot match today, because humaniq's `<id>` is already
			// `humaniq`; it costs nothing and it survives a rename in reverse.
			await expect(
				widget.getByTestId(HOOK.view),
				'View hours must link out to humaniq',
			).toHaveAttribute('href', /\/apps\/(humaniq|hrmq)\/time-entries/)

			// The timer sits LEFT of the two buttons, which is the placement, not
			// a detail: it is the one affordance a caseworker reaches for mid
			// call, and reading it out of the DOM order would pass on a tile that
			// paints it anywhere.
			const timerBox = await widget.getByTestId(HOOK.timer).boundingBox()
			const bookBox = await widget.getByTestId(HOOK.book).boundingBox()
			expect(
				timerBox,
				'the timer button must be painted somewhere',
			).not.toBeNull()
			expect(bookBox, 'Book hours must be painted somewhere').not.toBeNull()
			expect(
				Number(timerBox?.x),
				'the timer button sits left of Book hours on the tile',
			).toBeLessThan(Number(bookBox?.x))
		})

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#the-leaf-reads-the-right-case
		test('the leaf reads and books against this case, and the headline follows', async ({
			page,
		}) => {
			// THE DELTA IS NOT THE REQUIREMENT. This test used to book 2.5
			// hours and assert the headline rose by 2.5, which is exactly what
			// an UNFILTERED leaf summing every case's hours in the instance
			// also does: the booking lands, the sum rises by the same amount,
			// and the scenario's actual clause — filter on `domainObjectType`
			// = `dossiq:case` and `domainObjectRef` = this case's uuid — was
			// never read. On a one-case instance the two are indistinguishable,
			// which is every CI instance this file has ever run on.
			//
			// MUTATION CHECKED 2026-09-11 against a live instance with humaniq
			// enabled. humaniq's `fetchEntries` was made to send `_limit` only,
			// dropping both filter keys, so the leaf reads EVERY time entry in
			// the instance. This test reddened on the read assertion:
			//
			//   the leaf must read its hours filtered to this case; no request
			//   carried a domainObjectRef at all
			//   Expected: > 0   Received: 0
			//
			// The delta assertion alone would NOT have caught it: the booking
			// still lands and the unfiltered sum still rises by 2.5.
			//
			// So the filter is asserted on the wire, before the arithmetic.
			// The listener is attached BEFORE the navigation: the leaf's first
			// read happens as it mounts, and a listener attached afterwards
			// misses exactly the request that matters.
			const reads = collectHostFilteredReads(page)
			const writes = collectHostWrites(page)

			await openCase(page, caseId)
			const widget = page.getByTestId(HOOK.widget)
			await expect(widget).toBeVisible({ timeout: 30_000 })

			// The read half of the scenario. An unfiltered leaf sends no
			// request carrying `domainObjectRef` at all, so this is the
			// assertion that reddens on it, and the message prints every
			// openregister object read the page made so the absence can be
			// read rather than guessed.
			await expect
				.poll(() => reads.length, {
					timeout: 20_000,
					message:
						'the leaf must read its hours filtered to this case; no request carried a domainObjectRef at all, '
						+ 'which is what an unfiltered leaf summing every case looks like',
				})
				.toBeGreaterThan(0)
			for (const read of reads) {
				expect(
					read.type,
					`the leaf must filter on the host type derived from the page config: ${read.url}`,
				).toBe(HOST_TYPE)
				expect(
					read.ref,
					`the leaf must filter on THIS case's uuid, not another object's: ${read.url}`,
				).toBe(caseId)
			}

			const before = await readFigure(
				widget.getByTestId(HOOK.total),
				'the total before booking',
			)

			await widget.getByTestId(HOOK.book).click()
			const dialog = page.getByTestId(HOOK.dialog)
			await expect(
				dialog,
				"Book hours must open humaniq's booking dialog",
			).toBeVisible({ timeout: 15_000 })

			await fillField(
				dialog.getByTestId(HOOK.date),
				new Date().toISOString().slice(0, 10),
			)
			await fillField(dialog.getByTestId(HOOK.hours), String(BOOKED_HOURS))
			await fillField(
				dialog.getByTestId(HOOK.description),
				'E2E booking from the dossiq case page',
			)
			await dialog.getByTestId(HOOK.submit).click()

			await expect(
				dialog,
				'the dialog must close once the booking is accepted',
			).toBeHidden({ timeout: 15_000 })

			// The write half. The entry has to be FILED against this case, or
			// the headline that rises is counting somebody else's hours. Read
			// off the posted body rather than off the tile, because the tile
			// is a rendering of the answer and this is the answer.
			expect(
				writes.length,
				'booking must POST a time entry; nothing was posted to the objects endpoint',
			).toBeGreaterThan(0)
			for (const write of writes) {
				expect(
					write.type,
					`the booking must carry the host type: ${write.body}`,
				).toBe(HOST_TYPE)
				expect(
					write.ref,
					`the booking must be filed against THIS case: ${write.body}`,
				).toBe(caseId)
			}

			// Only now the arithmetic, which is a consequence of the two
			// assertions above rather than a substitute for them. Poll rather
			// than read once: the tile refetches after the write, and reading
			// between the two is a race that reports the booking as lost.
			await expect
				.poll(async () => tryReadFigure(widget.getByTestId(HOOK.total)), {
					timeout: 20_000,
					message: `the headline must count the ${BOOKED_HOURS} hours just booked, on top of the ${before} it showed`,
				})
				.toBeCloseTo(before + BOOKED_HOURS, 2)
		})

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#hours-render-on-a-case-with-humaniq-installed
		test('a running timer survives a reload, and stopping it returns the figures', async ({
			page,
		}) => {
			await openCase(page, caseId)
			const widget = page.getByTestId(HOOK.widget)
			await expect(widget).toBeVisible({ timeout: 30_000 })

			const before = await readFigure(
				widget.getByTestId(HOOK.total),
				'the total before the timer ran',
			)
			await expect(
				widget.getByTestId(HOOK.running),
				'no timer may already be running when this test starts',
			).toHaveCount(0)

			await widget.getByTestId(HOOK.timer).click()
			await expect(
				widget.getByTestId(HOOK.running),
				'starting the timer must show that it is running',
			).toBeVisible({ timeout: 15_000 })

			// THE POINT OF THE FEATURE. A timer held in component state looks
			// identical to one held on the server until the page is reloaded, and
			// a caseworker who reloads is the whole reason it is stored.
			await page.reload()
			await dismissSupportDialog(page)
			const reloaded = page.getByTestId(HOOK.widget)
			await expect(reloaded).toBeVisible({ timeout: 30_000 })
			await expect(
				reloaded.getByTestId(HOOK.running),
				'the timer was started before the reload, so it must still be running after it',
			).toBeVisible({ timeout: 20_000 })

			await reloaded.getByTestId(HOOK.timer).click()
			await expect(
				reloaded.getByTestId(HOOK.running),
				'stopping the timer must clear the running state',
			).toHaveCount(0, { timeout: 15_000 })

			// The tile goes back to being a tile: figures, not a stopwatch. The
			// stopped run is time booked, so the total may only have grown.
			await expect(
				reloaded.getByTestId(HOOK.total),
				'the headline must read again once the timer stops',
			).toBeVisible({ timeout: 15_000 })
			await expect
				.poll(
					async () =>
						readFigure(reloaded.getByTestId(HOOK.total), 'the total'),
					{
						timeout: 20_000,
						message: `stopping the timer books the run, so the total may not fall below the ${before} it showed before`,
					},
				)
				.toBeGreaterThanOrEqual(before)
		})
	})
}
