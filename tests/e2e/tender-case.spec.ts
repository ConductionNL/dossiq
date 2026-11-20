/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tenders-run-on-the-tender-case-type task 4.1: the three scenarios of
 * REQ-TND-CASE-01 (a tender is worked end to end on its case page).
 *
 *  - A late bid is refused: `SubmissionClosingDateGuard` stops the create of a
 *    `bid` whose submission day is after the tender case's `sluiting`, and the
 *    refusal names that day (`closingDate`, and in the message).
 *  - The formula ranks the bids: the Overview card `case-tender-ranking` lists
 *    the tender's `evaluation` rows by `weightedTotal`, highest first. The
 *    totals here are computed with the shipped `price-quality` scoring formula
 *    read from lib/Settings/register.d/88-tender-records.json, so a change to
 *    the formula changes the expectation instead of leaving a stale copy.
 *    Scoring a bid through the `bid-score` header action is task 3.2 and still
 *    open, so that half is `test.fixme`.
 *  - The award waits for the standstill: the flow's `Definitief gunnen`
 *    transition carries a `dateReached` guard on `opschortendeTermijnEinde`,
 *    and the engine answers 409 `transition-guard-failed` naming the day.
 *
 * THE CASE TYPE COMES FROM THE PACKAGE. `aanbesteding` installs on request, so
 * on a rig this suite owns it is installed here; on a shared instance where it
 * is absent the suite skips rather than writing a package into the register
 * everybody works in. Ids are read from lib/Settings/packages/aanbesteding/.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { IS_SHARED_INSTANCE } from './base-url.ts'
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
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** The package under test. */
const PACKAGE_ID = 'aanbesteding'

/** Where it lives in this checkout. */
const PACKAGE_DIR = path.join(__dirname, '..', '..', 'lib', 'Settings', 'packages', PACKAGE_ID)

/**
 * One JSON file of the checkout.
 *
 * @param parts Path segments under the repository root.
 * @return The parsed file.
 */
function repoJson(...parts: string[]): any {
	return JSON.parse(readFileSync(path.join(__dirname, '..', '..', ...parts), 'utf8'))
}

const CASE_TYPE_FILE = JSON.parse(readFileSync(path.join(PACKAGE_DIR, 'case-type.json'), 'utf8'))
const FLOW = JSON.parse(readFileSync(path.join(PACKAGE_DIR, 'flows', 'aanbesteding.json'), 'utf8'))

/** The tender case type as shipped. */
const TENDER_TYPE_ID: string = CASE_TYPE_FILE.caseType.id

/**
 * A status of the tender case type, by its shipped name.
 *
 * @param name The status name.
 * @return The status type id.
 */
function statusId(name: string): string {
	const row = (CASE_TYPE_FILE.statusTypes as any[]).find((s) => s.name === name)
	if (!row) throw new Error(`aanbesteding ships no status ${name}`)
	return String(row.id)
}

/**
 * A case property definition of the tender case type, by name.
 *
 * @param name The property name.
 * @return The definition id.
 */
function definitionId(name: string): string {
	const row = (CASE_TYPE_FILE.propertyDefinitions as any[]).find((d) => d.name === name)
	if (!row) throw new Error(`aanbesteding declares no property ${name}`)
	return String(row.id)
}

/** The flow's transitions, parsed out of the JSON string the template stores. */
const TRANSITIONS: any[] = JSON.parse(FLOW.transitions)

/** `Definitief gunnen`: Gunning to Gegund, guarded by the standstill end. */
const MAKE_FINAL = TRANSITIONS.find(
	(t) => (t.guards as any[]).some((g) => g.type === 'dateReached' && g.field === 'opschortendeTermijnEinde'),
)

/** The shipped price-quality scoring formula (REQ-EVA-003). */
const PRICE_QUALITY = (
	repoJson('lib', 'Settings', 'register.d', '88-tender-records.json').components.objects as any[]
).find((row) => row.key === 'price-quality')

/** dossiq's package routes and settings write. */
const PACKAGES = '/index.php/apps/dossiq/api/packages'
const SETTINGS_API = `/index.php/apps/${REGISTER}/api/settings`

/**
 * A calendar day relative to today, as `Y-m-d`.
 *
 * @param offset Days from today, negative for the past.
 * @return The day.
 */
function day(offset: number): string {
	const d = new Date()
	d.setUTCDate(d.getUTCDate() + offset)
	return d.toISOString().slice(0, 10)
}

/**
 * The value the stored formula gives for these named values.
 *
 * Evaluated rather than re-typed, with the functions the formula language
 * names (max, min, round with decimals, abs), so the expectation follows the
 * record the register ships.
 *
 * @param expression The stored expression.
 * @param values     The named values.
 * @return The total.
 */
function applyFormula(expression: string, values: Record<string, number>): number {
	const round = (x: number, decimals = 0): number => Math.round(x * 10 ** decimals) / 10 ** decimals
	const names = Object.keys(values)
	const fn = new Function('max', 'min', 'round', 'abs', ...names, `return (${expression})`)
	return Number(fn(Math.max, Math.min, round, Math.abs, ...names.map((n) => values[n])))
}

let api: APIRequestContext
let token = ''
let skipReason = ''
let menuBefore = ''

/**
 * Save the app's menu structure.
 *
 * @param value `simple` or `full`.
 */
async function saveMenuStructure(value: string): Promise<void> {
	const saved = await api.post(SETTINGS_API, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: { menu_structure: value },
	})
	expect(saved.status(), `POST /api/settings menu_structure=${value}`).toBe(200)
}

/**
 * Seed a tender case in one status with its dates as answered properties.
 *
 * @param label  A label after the run prefix.
 * @param status The status name.
 * @param dates  Case property name to `Y-m-d` value.
 * @return The case id.
 */
async function tenderCase(label: string, status: string, dates: Record<string, string>): Promise<string> {
	const created = await seedCase(api, token, {
		title: `${RUN_PREFIX} tender ${label}`,
		caseType: TENDER_TYPE_ID,
		status: statusId(status),
		properties: Object.entries(dates).map(([name, value]) => ({
			propertyDefinition: definitionId(name),
			name,
			value,
		})),
	})
	return objectId(created)
}

test.describe('A tender is worked end to end on its case page', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	let supplierId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		expect(MAKE_FINAL, 'the flow guards Definitief gunnen on the standstill end').toBeTruthy()
		expect(PRICE_QUALITY, 'the register ships the price-quality formula').toBeTruthy()

		const catalogue = await api.get(PACKAGES, { headers: { requesttoken: token } })
		expect(catalogue.ok(), `package catalogue -> ${catalogue.status()}`).toBeTruthy()
		const entry = ((await catalogue.json()).results as any[]).find((row) => row.id === PACKAGE_ID)
		if (!entry?.installed) {
			if (IS_SHARED_INSTANCE) {
				skipReason = 'the aanbesteding package is not installed on this shared instance, and installing it would write its case type and example tender into the register everybody works in'
				return
			}
			const installed = await api.post(`${PACKAGES}/${PACKAGE_ID}/install`, { headers: { requesttoken: token } })
			expect(installed.status(), await installed.text()).toBe(200)
		}

		supplierId = objectId(
			await createObject(api, token, 'supplier', {
				kvkNumber: String(90_000_000 + Math.floor(Math.random() * 999_999)),
				legalName: `${RUN_PREFIX} Inschrijver BV`,
				status: 'active',
			}),
		)

		// The evaluation card is drawn on the simple layout's Overview.
		const current = await api.get(SETTINGS_API, { headers: { 'OCS-APIRequest': 'true' } })
		const body = await current.json()
		menuBefore = String((body.config ?? body).menu_structure ?? '')
		await saveMenuStructure('simple')
	})

	test.afterAll(async () => {
		if (menuBefore !== '') {
			await saveMenuStructure(menuBefore === 'full' ? 'full' : 'simple')
		}
		await cleanupRunObjects(api, token, ['evaluation', 'bid', 'supplier', 'case'])
		await api?.dispose()
	})

	test.beforeEach(() => {
		test.skip(skipReason !== '', skipReason)
	})

	// @e2e openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#scenario-a-late-bid-is-refused
	test('a bid registered after the closing date is refused, naming that date', async () => {
		const closing = day(-1)
		const caseId = await tenderCase('late bid', 'Inschrijving', { sluiting: closing })

		// The same create the `bid-register` header action sends (its props:
		// tender and case this case, state received, responses {}).
		const res = await api.post(`/index.php/apps/openregister/api/objects/${REGISTER}/bid`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: {
				tender: caseId,
				case: caseId,
				supplier: supplierId,
				submittedAt: new Date().toISOString(),
				submissionRef: `${RUN_PREFIX}-LATE`,
				responses: {},
				state: 'received',
			},
		})
		const text = await res.text()
		if (res.ok()) {
			// Track it so teardown removes it even though the assertion below fails.
			trackCreatedObject('bid', objectId(JSON.parse(text)))
		}
		expect(res.ok(), `a late bid must be refused; got ${res.status()} ${text}`).toBe(false)
		expect(res.status(), 'a refusal, not a crash').toBeLessThan(500)
		expect(text, 'the refusal names the closing date').toContain(closing)
		expect(text).toContain('submission-after-closing-date')

		const stored = await listObjects(api, 'bid', { tender: caseId })
		expect(stored, 'nothing was written for the refused bid').toHaveLength(0)
	})

	// @e2e openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#scenario-the-formula-ranks-the-bids
	test('the evaluation lists the bids by the formula total, highest first', async ({ page }) => {
		const closing = day(-3)
		const caseId = await tenderCase('ranking', 'Beoordeling', { sluiting: closing })
		const submittedAt = `${day(-4)}T10:00:00Z`

		// Quality weighs 60, price 40. Created in an order that is neither the
		// ranking, nor the price order, nor the quality order.
		const bids = [
			{ ref: `${RUN_PREFIX}-BID-B`, quality: 60, price: 100_000 },
			{ ref: `${RUN_PREFIX}-BID-C`, quality: 90, price: 150_000 },
			{ ref: `${RUN_PREFIX}-BID-A`, quality: 80, price: 120_000 },
		]
		const lowestPrice = Math.min(...bids.map((b) => b.price))
		const scored = bids.map((b) => ({
			...b,
			total: applyFormula(PRICE_QUALITY.expression, {
				qualityWeight: 0.6,
				quality: b.quality,
				priceWeight: 0.4,
				maxPoints: 100,
				lowestPrice,
				price: b.price,
			}),
		}))

		for (const b of scored) {
			const bid = await createObject(api, token, 'bid', {
				tender: caseId,
				case: caseId,
				supplier: supplierId,
				submittedAt,
				submissionRef: b.ref,
				priceAmount: b.price,
				responses: { kwaliteit: 'Plan van aanpak' },
				state: 'admissible',
			})
			await createObject(api, token, 'evaluation', {
				bid: objectId(bid),
				tender: caseId,
				evaluator: `${RUN_PREFIX} panel`,
				criterionScores: { kwaliteit: b.quality, prijs: b.price },
				weightedTotal: b.total,
				state: 'submitted',
			})
		}

		const expected = [...scored].sort((x, y) => y.total - x.total).map((b) => b.ref)
		expect(expected, 'the fixture ranks differently from the order it was created in').not.toEqual(
			scored.map((b) => b.ref),
		)

		const card = await openRanking(page, caseId)
		const rows = card.locator('tr, [role="row"], li').filter({ hasText: RUN_PREFIX })
		await expect(rows, 'the card lists every evaluated bid').toHaveCount(scored.length, { timeout: 30_000 })

		const shown = (await rows.allInnerTexts()).map(
			(text) => scored.find((b) => text.includes(b.ref))?.ref ?? '',
		)
		expect(shown, 'highest total first').toEqual(expected)
	})

	// @e2e openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#scenario-the-formula-ranks-the-bids
	test('scoring a bid applies the price-quality formula to its criterion scores', async ({ page }) => {
		test.fixme(true, 'task 3.2: the bid-score header action is not built, so nothing applies FormulaScorer to an evaluation yet')

		const caseId = await tenderCase('scoring', 'Beoordeling', { sluiting: day(-3) })
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await clickHeaderAction(page, 'cn-action-bid-score')
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
	})

	// @e2e openspec/changes/tenders-run-on-the-tender-case-type/specs/dossiq-procurement-tender-management/spec.md#scenario-the-award-waits-for-the-standstill
	test('making the award final before the standstill ends is refused, naming the end date', async () => {
		// The award decision went out today, so the 20 calendar days of
		// standstill (Aw 2012 art. 2.127, the package's seeded term) end in 20
		// days. Nothing fills opschortendeTermijnEinde from the term yet, so the
		// proposed award is seeded with it, as `Gunningsbeslissing versturen`
		// leaves the case.
		const standstillEnd = day(20)
		const caseId = await tenderCase('standstill', 'Gunning', {
			sluiting: day(-30),
			gunningsdatum: day(0),
			opschortendeTermijnEinde: standstillEnd,
		})

		const res = await api.post(`/index.php/apps/${REGISTER}/api/case/${caseId}/transition`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { transitionId: MAKE_FINAL.id },
		})
		const body: any = await res.json().catch(() => ({}))
		expect(res.status(), `Definitief gunnen must be refused; got ${JSON.stringify(body)}`).toBe(409)
		expect(body.error).toBe('transition-guard-failed')

		const standstill = (body.failedGuards as any[] | undefined)?.find((g) => g.type === 'dateReached')
		expect(standstill, `the standstill guard is among the failed guards: ${JSON.stringify(body)}`).toBeTruthy()
		expect(String(standstill.failureMessage), 'the refusal names the day the standstill ends').toContain(
			standstillEnd,
		)

		const after = await showObject(api, 'case', caseId)
		expect(String(after.status), 'the case stayed in Gunning').toBe(statusId('Gunning'))
	})
})

/**
 * Open a tender case on its Overview and return the evaluation card.
 *
 * @param page   The page.
 * @param caseId The tender case.
 * @return The card.
 */
async function openRanking(page: Page, caseId: string) {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await dismissSupportDialog(page)
	const card = page
		.locator('[data-testid="cn-section-case-tender-ranking"]')
		.or(
			page
				.locator('section, .cn-card, .cn-detail-card, [class*="card"]')
				.filter({ hasText: 'Every bid with the total its scoring formula gives, highest first' }),
		)
		.first()
	await expect(card, 'the Evaluation card renders on the tender Overview').toBeVisible({ timeout: 45_000 })
	return card
}
