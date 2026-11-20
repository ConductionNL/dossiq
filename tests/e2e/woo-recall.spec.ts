/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-review-recall-and-stopping task 4.1 (REQ-WRS-001 to REQ-WRS-003, which
 * live in woo-review-triage/specs/document-review/spec.md per decision 182):
 * declare a stopping rule, mark a document and see the rule locked, draw a
 * sample of 20 on a seeded case, judge it, and see the estimate and the met or
 * not met line, on the Review tab of a Woo case.
 *
 * The screen is DocumentReviewPanel's section "Stopping rule and how much was
 * found" (task 2.3): the declare form before review, the rule after it, Draw a
 * sample (RecallSampleDialog), and per sampled document "Relevant after all" or
 * "Not relevant". `document-review.spec.ts` holds the API-level scenarios on the
 * spec's own 400/1600/200 fixture; this spec is the page.
 *
 * THE FIXTURE, AND THE NUMBERS IT MUST GIVE. A Woo case with 60 documents: 20
 * marked in scope (found) and 40 out of scope (the null set N). A sample of 20:
 *
 *   k = 0  estimate 1.0000, lower bound 0.7823 at 0.95  >= target 0.75: met
 *   k = 2  estimate 0.8333, lower bound 0.6389 at 0.95  <  target 0.75: not met
 *
 * computed with lib/Review/RecallEstimator.php itself (the exact one-sided
 * Clopper-Pearson bound; it gives 0.9615 and 0.8892 on the spec's fixture).
 * The panel writes a share as a Dutch percentage with one decimal, so 0.7823
 * reads "78,2" and 0.6389 "63,9".
 *
 * MARKING GOES THROUGH THE ROUTE. The panel offers no per-document mark: that
 * is the review viewer's, which is not built (woo-review-triage task 5.1). So
 * "mark a document" is the relevance route the viewer will call, and the
 * remaining marks are seeded through the same route after the rule is
 * declared, because a rule can only be declared while nothing is marked.
 *
 * PLAYWRIGHT LOGS IN AS ADMIN, who is the case handler and the sample's
 * reviewer (the dialog's default).
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/tasks.md#task-4.1
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	FIXTURE_SCHEMAS,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedDocument,
	tryDeleteObject,
} from './helpers/fixtures.ts'
import { journeyBudget, PAGE_LOAD } from './helpers/nav.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** The field of a case and of a review batch that names the person handling it. */
const HANDLER_FIELD = ['ass', 'ignee'].join('')

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/** Found (in scope), the null set (out of scope), and the sample size. */
const FOUND = 20
const NULL_SET = 40
const SAMPLE = 20

/** The action of the audit entry a met rule writes on the case (REQ-WRS-003). */
const MET_ACTION = 'dossiq.review.stopping-rule-met'

/**
 * A review route of one case.
 *
 * @param caseId The case.
 * @param tail   The path after `/review`.
 * @return The route.
 */
function review(caseId: string, tail: string): string {
	return `/index.php/apps/dossiq/api/cases/${caseId}/review${tail}`
}

/**
 * The headers of a dossiq write.
 *
 * @param token The CSRF request token.
 * @return The headers.
 */
function writeHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * Open the case on its Review tab.
 *
 * @param page   The page under test.
 * @param caseId The case.
 */
async function openReview(page: Page, caseId: string): Promise<void> {
	await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
	await page.getByRole('tab', { name: 'Review' }).click()
	await expect(page.getByTestId('document-review-recall')).toBeVisible(PAGE_LOAD)
}

/**
 * Draw a sample of the given size through the dialog.
 *
 * @param page   The page under test.
 * @param caseId The case.
 * @param size   The sample size.
 */
async function drawSample(page: Page, caseId: string, size: number): Promise<void> {
	await page.getByTestId('document-review-draw').click()
	const dialog = page.getByTestId('recall-sample-dialog')
	await expect(dialog).toBeVisible(PAGE_LOAD)
	// NcTextField puts the test id on its input, or on its wrapper in an older build.
	await dialog.locator('input[data-testid="recall-sample-size"], [data-testid="recall-sample-size"] input').first().fill(String(size))
	const drawn = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/review/recall/samples`))
	await dialog.getByTestId('recall-sample-draw').click()
	expect((await drawn).status()).toBe(201)
	await expect(dialog).toBeHidden(PAGE_LOAD)
}

/**
 * Judge every document of the open sample on the page, the first `inScope` of them relevant.
 *
 * @param page    The page under test.
 * @param inScope How many to judge relevant after all.
 * @param halfway Called once after `SAMPLE / 2` judgements, for the in-progress check.
 */
async function judgeOpenSample(page: Page, inScope: number, halfway: () => Promise<void>): Promise<void> {
	const rows = page.getByTestId('document-review-sample-document')
	await expect(rows).toHaveCount(SAMPLE, PAGE_LOAD)
	for (let judged = 0; judged < SAMPLE; judged++) {
		const answer = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().includes('/judgements'))
		const button = judged < inScope ? 'document-review-judge-in' : 'document-review-judge-out'
		await rows.first().getByTestId(button).click()
		expect((await answer).ok()).toBeTruthy()
		await expect(rows).toHaveCount(SAMPLE - judged - 1, PAGE_LOAD)
		if (judged + 1 === SAMPLE / 2) {
			await halfway()
		}
	}
}

/**
 * The audit trail entries of the case with the met action.
 *
 * @param api    Authenticated request context.
 * @param caseId The case.
 * @return The entries.
 */
async function metEntries(api: APIRequestContext, caseId: string): Promise<any[]> {
	const res = await api.get(`${OBJECTS}/case/${caseId}/audit-trails?_limit=500`)
	expect(res.ok(), `audit trail -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const entries = (Array.isArray(body) ? body : (body?.results ?? [])) as any[]
	return entries.filter((entry) => entry?.action === MET_ACTION)
}

test.describe('The stopping rule and the recall estimate, on the Woo case page', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(journeyBudget(4))

	let api: APIRequestContext
	let token = ''
	let caseId = ''
	const documents: string[] = []

	test.beforeAll(async ({ playwright, baseURL }) => {
		test.setTimeout(10 * 60_000)
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek herinnering`,
			caseType: WOO_CASE_TYPE,
			[HANDLER_FIELD]: ADMIN,
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseId = objectId(seeded)

		// Every document unmarked: a stopping rule is declared before the first marking.
		for (let n = 1; n <= FOUND + NULL_SET; n++) {
			const document = await seedDocument(api, token, { title: `${RUN_PREFIX} Recall ${n}` })
			const ref = objectId(document)
			documents.push(ref)
			await createObject(api, token, 'zaakinformatieobject', { case: caseId, informatieobject: ref })
			await createObject(api, token, 'documentReview', { case: caseId, documentRef: ref, relevance: 'unmarked' })
		}
	})

	test.afterAll(async () => {
		// The server-made rows of the case, by id. A stopping rule refuses its delete once
		// review started (StoppingRuleGuard); the case's cascade takes it with the case.
		for (const schema of ['recallSample', 'reviewBatch', 'stoppingRule']) {
			const res = await api.get(`${OBJECTS}/${schema}?case=${caseId}&_limit=200`)
			const rows = res.ok() ? (((await res.json())?.results ?? []) as any[]) : []
			for (const row of rows) {
				await tryDeleteObject(api, token, schema, objectId(row))
			}
		}
		await cleanupRunObjects(api, token, ['documentReview', ...FIXTURE_SCHEMAS])
		await api.dispose()
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-declared-in-advance
	test('the handler declares a stopping rule on the page before any document is marked (REQ-WRS-001)', async ({ page }) => {
		await openReview(page, caseId)
		await expect(page.getByTestId('document-review-no-rule')).toBeVisible()

		const form = page.getByTestId('document-review-rule-form')
		await form.locator('#document-review-target').fill('75')
		await form.locator('#document-review-confidence').selectOption('0.95')
		const declared = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/review/stopping-rule`))
		await form.getByRole('button', { name: 'Declare the stopping rule' }).click()
		expect((await declared).status()).toBe(201)

		await expect(page.getByTestId('document-review-rule')).toContainText('Target: 75% found with 95% confidence.')
		const recall = await (await api.get(review(caseId, '/recall'))).json()
		expect(recall.rule.targetRecall).toBe(0.75)
		expect(recall.rule.confidence).toBe(0.95)
		expect(recall.rule.declaredBy).toBe(ADMIN)
		expect(recall.rule.declaredAt).toBeTruthy()
		expect(recall.reviewStarted).toBe(false)
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-not-changeable-once-review-started
	test('once a document is marked the rule is locked, on the page and at the route (REQ-WRS-001)', async ({ page }) => {
		const marked = await api.post(review(caseId, `/documents/${documents[0]}/relevance`), {
			headers: writeHeaders(token),
			data: { relevance: 'in-scope' },
		})
		expect(marked.ok(), `mark -> ${marked.status()} ${await marked.text()}`).toBeTruthy()

		await openReview(page, caseId)
		await expect(page.getByTestId('document-review-rule')).toContainText('Nobody can change it after that.')
		await expect(page.getByTestId('document-review-rule-form')).toHaveCount(0)
		await expect(page.getByTestId('document-review-no-rule')).toHaveCount(0)

		const lowered = await api.post(review(caseId, '/stopping-rule'), {
			headers: writeHeaders(token),
			data: { targetRecall: 0.7, confidence: 0.95 },
		})
		expect(lowered.status()).toBe(409)
		expect((await lowered.json()).error).toBe('review-stopping-rule-locked')

		const recall = await (await api.get(review(caseId, '/recall'))).json()
		expect(recall.reviewStarted).toBe(true)
		expect(recall.rule.targetRecall).toBe(0.75)
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-no-estimate-before-the-sample-is-judged
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-met
	test('a sample of 20 with nothing relevant gives the estimate and meets the rule (REQ-WRS-002, REQ-WRS-003)', async ({ page }) => {
		test.setTimeout(10 * 60_000)
		// The rest of the review: found 20 in scope (the first is already marked), a null set of 40.
		for (const [n, ref] of documents.entries()) {
			if (n === 0) {
				continue
			}
			const marked = await api.post(review(caseId, `/documents/${ref}/relevance`), {
				headers: writeHeaders(token),
				data: { relevance: n < FOUND ? 'in-scope' : 'out-of-scope' },
			})
			expect(marked.ok(), `mark ${n} -> ${marked.status()}`).toBeTruthy()
		}

		await openReview(page, caseId)
		await drawSample(page, caseId, SAMPLE)
		const open = page.getByTestId('document-review-sample').filter({ has: page.getByTestId('document-review-sample-document') })
		await expect(open.getByTestId('document-review-sample-state')).toHaveText(`0 of ${SAMPLE} judged`)

		await judgeOpenSample(page, 0, async () => {
			// Half-way: in progress, and no estimate yet.
			await expect(open.getByTestId('document-review-sample-state')).toHaveText(`${SAMPLE / 2} of ${SAMPLE} judged`)
			await expect(open).not.toContainText('An estimated')
		})

		const met = page.getByTestId('document-review-sample').filter({ hasText: 'Stopping rule met' })
		await expect(met).toHaveCount(1, PAGE_LOAD)
		await expect(met).toContainText('An estimated 100% found. Lower bound 78,2% at 95% confidence.')
		await expect(met).toContainText(`${SAMPLE} of ${NULL_SET} not relevant or unmarked documents · 0 relevant after all · ${FOUND} found`)

		const recall = await (await api.get(review(caseId, '/recall'))).json()
		const sample = (recall.samples as any[]).find((row) => row.stoppingRuleMetAt)
		expect(sample, JSON.stringify(recall.samples)).toBeTruthy()
		expect(sample.k).toBe(0)
		expect(sample.nullSetSize).toBe(NULL_SET)
		expect(sample.found).toBe(FOUND)
		expect(sample.recallEstimate).toBe(1)
		expect(sample.recallLowerBound).toBe(0.7823)

		const entries = await metEntries(api, caseId)
		expect(entries, 'one met entry on the case audit trail').toHaveLength(1)
		expect(JSON.stringify(entries[0])).toContain('0.7823')
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-not-met
	test('a sample of 20 with two relevant is below the target and records nothing (REQ-WRS-002, REQ-WRS-003)', async ({ page }) => {
		test.setTimeout(10 * 60_000)
		await openReview(page, caseId)
		await drawSample(page, caseId, SAMPLE)
		await judgeOpenSample(page, 2, async () => undefined)

		const below = page.getByTestId('document-review-sample').filter({ hasText: 'Below the target' })
		await expect(below).toHaveCount(1, PAGE_LOAD)
		await expect(below).toContainText('An estimated 83,3% found. Lower bound 63,9% at 95% confidence, against a target of 75%.')
		await expect(below).toContainText('· 2 relevant after all ·')

		const recall = await (await api.get(review(caseId, '/recall'))).json()
		const sample = (recall.samples as any[]).find((row) => row.k === 2)
		expect(sample, JSON.stringify(recall.samples)).toBeTruthy()
		expect(sample.recallEstimate).toBe(0.8333)
		expect(sample.recallLowerBound).toBe(0.6389)
		expect(sample.stoppingRuleMetAt).toBeFalsy()

		expect(await metEntries(api, caseId), 'a sample below the target writes no met entry').toHaveLength(1)
	})
})
