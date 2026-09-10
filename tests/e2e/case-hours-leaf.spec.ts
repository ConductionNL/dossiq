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
 * Read the number a leaf figure prints.
 *
 * The leaf formats its own figures and may print a unit or a Dutch decimal
 * comma, so the number is extracted rather than compared as text.
 *
 * @param figure The locator holding the figure.
 * @param what   What the figure is, for the failure message.
 * @return The number.
 */
async function readFigure(figure: Locator, what: string): Promise<number> {
	const text = (await figure.innerText()).trim()
	const match = text.match(/-?\d+(?:[.,]\d+)?/)
	expect(match, `${what} must print a number, and it printed "${text}"`).not.toBeNull()
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

			// The title is the tell that matters. A cell that still printed
			// "Hours booked" over an empty body would be the reserved void
			// ADR-062 forbids, and a cell that printed it over a `0` would be
			// the ADR-113 lie this change removed.
			await expect(
				page.getByText(HOURS_TITLE),
				'no hours heading may render when the leaf behind it is not registered',
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
				if (/\/apps\/(humaniq|hrmq)\/|\/objects\/(humaniq|hrmq)\//.test(url)) {
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
		test('the tile leads with the hours on the case and the caller\'s own beneath', async ({
			page,
		}) => {
			await openCase(page, caseId)

			const widget = page.getByTestId(HOOK.widget)
			await expect(
				widget,
				'humaniq is enabled, so its hours leaf must render in the widget cell',
			).toBeVisible({ timeout: 30_000 })

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
			expect(timerBox, 'the timer button must be painted somewhere').not.toBeNull()
			expect(bookBox, 'Book hours must be painted somewhere').not.toBeNull()
			expect(
				Number(timerBox?.x),
				'the timer button sits left of Book hours on the tile',
			).toBeLessThan(Number(bookBox?.x))
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

			await widget.getByTestId(HOOK.book).click()
			const dialog = page.getByTestId(HOOK.dialog)
			await expect(
				dialog,
				'Book hours must open humaniq\'s booking dialog',
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
				.poll(
					async () => readFigure(widget.getByTestId(HOOK.total), 'the total'),
					{
						timeout: 20_000,
						message: `the headline must count the ${BOOKED_HOURS} hours just booked, on top of the ${before} it showed`,
					},
				)
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
					async () => readFigure(reloaded.getByTestId(HOOK.total), 'the total'),
					{
						timeout: 20_000,
						message: `stopping the timer books the run, so the total may not fall below the ${before} it showed before`,
					},
				)
				.toBeGreaterThanOrEqual(before)
		})
	})
}
