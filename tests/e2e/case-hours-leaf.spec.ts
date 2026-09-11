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
 * 🔴 THE JOURNEY HALF IS UNPROVEN. It is written against the leaf's published
 * `data-testid` contract and has not been run against an instance serving the
 * `humaniq-hours` bundle, because that bundle is not shipped yet
 * (humaniq `hours-leaf-for-any-object`). Read a first green run as evidence,
 * not this comment.
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
	purgeObject,
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
	// ONE action button whose menu holds Book hours and View hours (humaniq#418).
	// Those two are not on the card until the menu is open.
	actions: 'hq-hours-actions',
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
/**
 * Remove the case a half seeded, and fail loudly if it stays.
 *
 * `dossiq/case` is archival, so every HTTP delete is refused (openregister#3428)
 * and `purgeObject` falls through to `occ openregister:objects:purge`. That path
 * did not exist when this file was written, which is why it once said a seeded
 * case could never be removed and left one behind on every run. A teardown that
 * swallows a failed purge would report a clean instance it did not observe, so a
 * leak throws.
 *
 * @param baseURL The instance under test.
 * @param caseId  The seeded case, or '' when setup never got that far.
 */
async function purgeHoursCase(
	playwright: {
		request: {
			newContext: (o: { baseURL?: string }) => Promise<APIRequestContext>
		}
	},
	baseURL: string | undefined,
	caseId: string,
): Promise<void> {
	if (caseId === '') return
	const api = await playwright.request.newContext({ baseURL })
	try {
		const token = await getRequestToken(api)
		if ((await purgeObject(api, token, 'case', caseId)) === false) {
			throw new Error(
				'e2e teardown left the seeded hours case behind, so the next run on this '
					+ `instance starts dirty: case ${caseId}`,
			)
		}
	} finally {
		await api.dispose()
	}
}

/**
 * Stop any running timer, then delete every TimeEntry booked against a case.
 *
 * Filters on the BARE key `domainObjectRef`. OpenRegister's objects endpoint
 * reads `filter[x]` as a filter on a property literally named `filter[x]`, so
 * that spelling returns the empty set and a cleanup written with it would
 * report success having removed nothing (openregister#3611).
 *
 * A leftover entry is a failure, for the same reason a leftover case is: it is
 * hours on a real person's timesheet that nobody worked.
 *
 * @param baseURL The instance under test.
 * @param caseId  The seeded case, or '' when setup never got that far.
 */
async function purgeHoursEntries(
	playwright: {
		request: {
			newContext: (o: { baseURL?: string }) => Promise<APIRequestContext>
		}
	},
	baseURL: string | undefined,
	caseId: string,
): Promise<void> {
	if (caseId === '') return
	const api = await playwright.request.newContext({ baseURL })
	const headers = { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
	try {
		await api.post('/index.php/apps/humaniq/api/time-entries/timer/stop', {
			headers,
			data: {},
		})

		const list = async (): Promise<Array<Record<string, unknown>>> => {
			const res = await api.get(
				`/index.php/apps/openregister/api/objects/humaniq/TimeEntry?_limit=200&domainObjectRef=${caseId}`,
				{ headers },
			)
			const body = await res.json()
			return Array.isArray(body) ? body : body.results || []
		}

		for (const row of await list()) {
			const self = (row['@self'] || {}) as Record<string, unknown>
			const id = String(self.id || row.id || '')
			if (id !== '') {
				await api.delete(
					`/index.php/apps/openregister/api/objects/humaniq/TimeEntry/${id}`,
					{
						headers,
					},
				)
			}
		}

		const left = await list()
		if (left.length > 0) {
			throw new Error(
				`e2e teardown left ${left.length} time entr${left.length === 1 ? 'y' : 'ies'} `
					+ `on case ${caseId}, which stay on the acting user's real timesheet`,
			)
		}
	} finally {
		await api.dispose()
	}
}

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
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`, {
		waitUntil: 'domcontentloaded',
		timeout: 60_000,
	})
	await expect(
		page.locator('.cn-detail-page'),
		'the case detail page must render, or nothing below is an observation about the hours surface',
	).toBeVisible({ timeout: 30_000 })
	// The support dialog and the setup wizard both mask every click behind them.
	await dismissSupportDialog(page)
}

/**
 * Read the number a leaf figure prints.
 *
 * The leaf formats its own figures and may print a unit or a Dutch decimal
 * comma, so the number is extracted rather than compared as text.
 *
 * @param figure The locator holding the figure.
 * @param what   What the figure is, for the failure message.
 * @return The number.
 */
/**
 * Read a figure for use INSIDE `expect.poll`, where a throw is fatal.
 *
 * `readFigure` asserts, and an assertion that throws inside a poll callback ends
 * the poll on its first try instead of letting it retry. That defeated the only
 * reason the poll was there: the leaf refetches after a write, and on a case
 * with no prior hours it prints `–` while the read is in flight, which is its
 * rule for "not known yet" rather than a zero it cannot vouch for. The first
 * poll caught exactly that transient state and failed the booking as lost.
 *
 * Returns NaN for a figure that is not a number yet, which matches no
 * `toBeCloseTo` or `toBeGreaterThanOrEqual`, so the poll keeps trying until the
 * real figure lands or its timeout says it never did.
 *
 * @param figure The locator holding the figure.
 * @return The number, or NaN while the leaf prints no number.
 */
async function pollFigure(figure: Locator): Promise<number> {
	const text = (await figure.innerText().catch(() => '')).trim()
	const match = text.match(/-?\d+(?:[.,]\d+)?/)
	return match === null ? Number.NaN : Number(match[0].replace(',', '.'))
}

async function readFigure(figure: Locator, what: string): Promise<number> {
	// WAIT for a number, then return it. The leaf prints `–` while its read is
	// in flight, which is its rule for "not known yet" rather than a zero it
	// cannot vouch for. A single read right after mount races that fetch, and on
	// a loaded instance it lost: the first figure read `–` and the test failed
	// on timing rather than on the leaf. A figure that never becomes a number
	// still fails, with this message, once the timeout says it never arrived.
	let value = Number.NaN
	await expect
		.poll(
			async () => {
				value = await pollFigure(figure)
				return Number.isNaN(value) ? null : value
			},
			{
				timeout: 30_000,
				message: `${what} must print a number, and it printed none within 30 s`,
			},
		)
		.not.toBeNull()
	return value
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

		// The case type is adopted rather than owned, so only the case goes.
		test.afterAll(async ({ playwright, baseURL }) => {
			await purgeHoursCase(playwright, baseURL, caseId)
		})

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
			for (const hook of [HOOK.actions, HOOK.book, HOOK.view, HOOK.timer]) {
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

		// The booking and the stopped timer are rows in humaniq's register, and
		// they are NOT harmless: humaniq files every TimeEntry onto the acting
		// user's monthly timesheet and recomputes its total from them. Measured
		// on the shared dev instance 2026-09-11, four earlier runs had left 10
		// hours on admin's real September timesheet (12.5 h, of which 2.5 real),
		// each pointing at a case that no longer existed. So the entries go
		// first, then the case. A running timer is stopped before that, or the
		// next run's stopwatch is disabled and the timer test fails on state.
		test.afterAll(async ({ playwright, baseURL }) => {
			await purgeHoursEntries(playwright, baseURL, caseId)
			await purgeHoursCase(playwright, baseURL, caseId)
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

			// The card lists NO bookings (humaniq#418). A KPI answers one question
			// and the rows behind the total are one press away through View hours.
			await expect(
				widget.locator('li'),
				'the hours card must not list the bookings behind its total',
			).toHaveCount(0)

			// Two controls in the card header: the stopwatch and ONE action
			// button. Book hours and View hours are menu items, not tile buttons,
			// so they must NOT be on the card until the menu opens. Asserting them
			// visible without opening it would fail; asserting them absent first
			// proves the menu is what reveals them.
			await expect(
				widget.getByTestId(HOOK.timer),
				'the stopwatch must be on the card',
			).toBeVisible()
			await expect(
				widget.getByTestId(HOOK.actions),
				'the card must carry one action button',
			).toBeVisible()
			await expect(
				widget.getByTestId(HOOK.book),
				'Book hours lives in the action menu, not on the card',
			).toHaveCount(0)

			await widget.getByTestId(HOOK.actions).click()
			await expect(
				widget.getByTestId(HOOK.book),
				'Book hours must be in the action menu',
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

			// The stopwatch sits LEFT of the action button, which is the placement,
			// not a detail: it is the one affordance a caseworker reaches for mid
			// call, and reading it out of the DOM order would pass on a card that
			// paints it anywhere.
			const timerBox = await widget.getByTestId(HOOK.timer).boundingBox()
			const actionsBox = await widget.getByTestId(HOOK.actions).boundingBox()
			expect(
				timerBox,
				'the stopwatch must be painted somewhere',
			).not.toBeNull()
			expect(
				actionsBox,
				'the action button must be painted somewhere',
			).not.toBeNull()
			expect(
				Number(timerBox?.x),
				'the stopwatch sits left of the action button on the card',
			).toBeLessThan(Number(actionsBox?.x))
		})

		// @e2e openspec/changes/hours-onto-humaniq-leaf/specs/case-hours-via-humaniq-leaf/spec.md#the-leaf-reads-the-right-case
		test('booking hours through the dialog raises the headline', async ({
			page,
		}) => {
			await openCase(page, caseId)
			const widget = page.getByTestId(HOOK.widget)
			await expect(widget).toBeVisible({ timeout: 30_000 })

			const before = await readFigure(
				widget.getByTestId(HOOK.total),
				'the total before booking',
			)

			await widget.getByTestId(HOOK.actions).click()
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

			// Poll the headline rather than read it once: the tile refetches after
			// the write, and reading between the two is a race that reports the
			// booking as lost.
			await expect
				.poll(async () => pollFigure(widget.getByTestId(HOOK.total)), {
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
			// Same budget and wait condition as openCase: `load` waits for every
			// asset on a heavy page, and on a shared instance that outruns 30 s while
			// the DOM is long ready. The widget expect below proves the render.
			await page.reload({ waitUntil: 'domcontentloaded', timeout: 60_000 })
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
				.poll(async () => pollFigure(reloaded.getByTestId(HOOK.total)), {
					timeout: 20_000,
					message: `stopping the timer books the run, so the total may not fall below the ${before} it showed before`,
				})
				.toBeGreaterThanOrEqual(before)
		})
	})
}
