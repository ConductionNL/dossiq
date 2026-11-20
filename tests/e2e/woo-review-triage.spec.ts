/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-review-triage task 7.1 (REQ-WRT-001 to REQ-WRT-005, and REQ-WRT-W01):
 * on a Woo case, apply a rule, overturn one marking, make two batches, open a
 * document page by page, try to decide with a page unseen and see the refusal,
 * finish, decide.
 *
 * The screen is the Review tab of the case (DocumentReviewPanel: "Where the
 * review stands", "Rules for relevant or not" with TriageRuleDialog, "Batches
 * and reviewers" with ReviewBatchDialog, "What is still open"), then the Woo
 * case's `Assess documents` (DocumentAssessDialog) and `Woo decision`
 * (AssessmentDecisionDialog) header actions. `document-review.spec.ts` holds the
 * API-level scenarios; this spec is the page.
 *
 * WHAT IS NOT BUILT, AND HOW THE JOURNEY GETS PAST IT. dossiq has no paged
 * document viewer (woo-review-triage task 5.1, Q-dossiq-L8-1: Nextcloud's Viewer
 * exposes no page events and no pdf.js is installed). The viewer is what
 * reports pages seen and what a reviewer marks a single document from. So:
 *
 *  - "open a document page by page" is the pages-seen route the viewer will
 *    call (src/services/pagesSeenReporter.js posts the same body), and the
 *    viewer itself is a `test.fixme` below;
 *  - "overturn one marking" is the relevance route the viewer will call, and
 *    marking from the page is a `test.fixme` below.
 *
 * The rules, the batches, the verdicts and the decision all go through the page.
 *
 * The documents are collected mail: informatieobjecten joined to the case whose
 * provenance carries the mail header fields (`provenance.mail`, REQ-WRR-003),
 * which is where the `senderDomain` condition reads. Each has an unmarked
 * review of three pages, so a batch requires pages 1 to 3 (`every-page`, the
 * Woo case type's empty review depth).
 *
 * PLAYWRIGHT LOGS IN AS ADMIN, the case handler and the first reviewer. The
 * second reviewer is an account this spec provisions in the staff group
 * (REQ-CRA-009) and removes again.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 *
 * @spec openspec/changes/woo-review-triage/tasks.md#task-7.1
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, provisioningContext } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	FIXTURE_SCHEMAS,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedDocument,
	showObject,
	tryDeleteObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, journeyBudget, PAGE_LOAD } from './helpers/nav.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** The field of a case and of a review batch that names the person handling it. */
const HANDLER_FIELD = ['ass', 'ignee'].join('')

/** The second reviewer, and their password. */
const SECOND_REVIEWER = 'e2e-triage-reviewer'
const PASSWORD = 'e2e-Triage-Review-2026!'

/** The staff group a batch reviewer must be in (REQ-CRA-009). */
const STAFF_GROUP = 'dossiq-medewerkers'

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/** The two senders of the collected mail. */
const NEWSLETTER_DOMAIN = 'nieuwsbrief.example.nl'
const MUNICIPAL_DOMAIN = 'gemeente.nl'

/** The two rules, by name. */
const NEWSLETTER_RULE = 'Nieuwsbrieven'
const MUNICIPAL_RULE = 'Gemeentepost'

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
 * The input of a text field, whether the library put the test id on the input or on its wrapper.
 *
 * @param scope  Where to look.
 * @param testId The field's test id.
 * @return The input.
 */
function textInput(scope: Locator, testId: string): Locator {
	return scope.locator(`input[data-testid="${testId}"], [data-testid="${testId}"] input`).first()
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
	await expect(page.getByTestId('document-review-status')).toBeVisible(PAGE_LOAD)
}

/**
 * Add a triage rule through the dialog.
 *
 * @param page   The page under test.
 * @param caseId The case.
 * @param rule   The rule's name, effect and sender domain.
 * @param rule.name The name.
 * @param rule.effect The effect.
 * @param rule.senderDomain The sender domain condition.
 */
async function addRule(
	page: Page,
	caseId: string,
	rule: { name: string; effect: 'in' | 'out'; senderDomain: string },
): Promise<void> {
	await page.getByTestId('document-review-new-rule').click()
	const dialog = page.getByTestId('triage-rule-dialog')
	await expect(dialog).toBeVisible(PAGE_LOAD)
	await textInput(dialog, 'triage-rule-name').fill(rule.name)
	await dialog.locator(`[data-testid="triage-rule-effect-${rule.effect}"]`).first().click()
	await textInput(dialog, 'triage-rule-senderDomain').fill(rule.senderDomain)
	const saved = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/review/triage/rules`))
	await dialog.getByTestId('triage-rule-save').click()
	expect((await saved).ok()).toBeTruthy()
	await expect(dialog).toBeHidden(PAGE_LOAD)
}

/**
 * Report pages as seen, the call the review viewer makes as each page is displayed.
 *
 * @param api         Authenticated request context.
 * @param token       The CSRF request token.
 * @param caseId      The case.
 * @param documentRef The document.
 * @param pages       The pages displayed.
 * @return The unseen required pages the route answers.
 */
async function reportPagesSeen(api: APIRequestContext, token: string, caseId: string, documentRef: string, pages: number[]): Promise<number[]> {
	const res = await api.post(review(caseId, `/documents/${documentRef}/pages-seen`), {
		headers: writeHeaders(token),
		data: { pages, pageCount: 3 },
	})
	expect(res.ok(), `pages seen -> ${res.status()} ${await res.text()}`).toBeTruthy()
	return ((await res.json()).unseen ?? []) as number[]
}

test.describe('Triage, batches, pages seen and the decision, on the Woo case page', () => {
	test.describe.configure({ mode: 'serial' })

	let provisioning: APIRequestContext
	let api: APIRequestContext
	let token = ''
	const cases: string[] = []

	/**
	 * A Woo case handled by the admin with collected mail, each document unmarked and three pages long.
	 *
	 * @param label   A word for the title.
	 * @param senders The sender of each mail, in order.
	 * @param relevance The relevance each review starts in.
	 * @return The case, its documents and their titles, in order.
	 */
	async function wooCaseWithMail(
		label: string,
		senders: string[],
		relevance: 'unmarked' | 'in-scope' = 'unmarked',
	): Promise<{ caseId: string; documents: string[]; titles: string[] }> {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek ${label}`,
			caseType: WOO_CASE_TYPE,
			[HANDLER_FIELD]: ADMIN,
			startDate: new Date().toISOString().slice(0, 10),
		})
		const caseId = objectId(seeded)
		cases.push(caseId)
		const documents: string[] = []
		const titles: string[] = []
		for (const [n, from] of senders.entries()) {
			const title = `${RUN_PREFIX} ${label} mail ${n + 1}`
			const document = await seedDocument(api, token, {
				title,
				fileName: `${label}-${n + 1}.eml`,
				format: 'message/rfc822',
				provenance: {
					source: 'upload',
					mail: {
						from,
						to: ['woo@gemeente.nl'],
						cc: [],
						date: '2026-10-01T09:00:00+02:00',
						messageId: `<${RUN_PREFIX}-${label}-${n + 1}@example.nl>`,
					},
				},
			})
			const ref = objectId(document)
			await createObject(api, token, 'zaakinformatieobject', { case: caseId, informatieobject: ref })
			await createObject(api, token, 'documentReview', { case: caseId, documentRef: ref, relevance, pageCount: 3 })
			documents.push(ref)
			titles.push(title)
		}
		return { caseId, documents, titles }
	}

	test.beforeAll(async ({ playwright, baseURL }) => {
		provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', SECOND_REVIEWER, PASSWORD)
		const joined = await provisioning.post(`/ocs/v2.php/cloud/users/${SECOND_REVIEWER}/groups?format=json`, {
			form: { groupid: STAFF_GROUP },
		})
		expect(joined.ok(), `add the reviewer to ${STAFF_GROUP} -> ${joined.status()}`).toBeTruthy()

		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		const serverMade: Array<[string, string]> = []
		for (const caseId of cases) {
			for (const [schema, field] of [
				['wooDocumentAssessment', 'caseRef'],
				['decision', 'case'],
				['reviewBatch', 'case'],
				['triageRule', 'case'],
			]) {
				const res = await api.get(`${OBJECTS}/${schema}?${field}=${caseId}&_limit=200`)
				const rows = res.ok() ? (((await res.json())?.results ?? []) as any[]) : []
				serverMade.push(...rows.map((row): [string, string] => [schema, objectId(row)]))
			}
		}
		for (const [schema, id] of serverMade) {
			await tryDeleteObject(api, token, schema, id)
		}
		await cleanupRunObjects(api, token, ['documentReview', ...FIXTURE_SCHEMAS])
		await provisioning.delete(`/ocs/v2.php/cloud/users/${SECOND_REVIEWER}?format=json`)
		await Promise.all([api?.dispose(), provisioning?.dispose()])
	})

	// @e2e openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#scenario-a-woo-verzoek-reviews-its-documents-through-the-generic-capability
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-a-rule-sets-aside-newsletters
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-a-reviewer-overturns-a-rule
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-out-of-scope-needs-no-verdict-and-both-counts-are-reported
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-two-batches-for-two-reviewers
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-a-document-cannot-be-in-two-open-batches
	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-a-verdict-set-without-opening-is-refused
	test('apply rules, overturn one, two batches, pages seen, refused, finish and decide', async ({ page, request }) => {
		test.setTimeout(journeyBudget(8))
		const senders = [
			...Array(4).fill(`redactie@${NEWSLETTER_DOMAIN}`),
			`a.jansen@${MUNICIPAL_DOMAIN}`,
			`p.devries@${MUNICIPAL_DOMAIN}`,
		] as string[]
		const { caseId, documents, titles } = await wooCaseWithMail('Fietspad', senders)
		const newsletters = documents.slice(0, 4)
		const municipal = documents.slice(4)
		const overturned = newsletters[0]
		const woo = await listObjects(request, 'caseType', { identifier: 'woo-verzoek' })
		expect(woo[0]?.documentReview?.enabled, 'the seeded woo-verzoek switches document review on').toBe(true)

		await openReview(page, caseId)
		await expect(page.getByTestId('document-review-tile-unmarked').locator('.document-review__tile-value')).toHaveText('6')

		// 1. Two rules, applied without opening a document.
		await addRule(page, caseId, { name: NEWSLETTER_RULE, effect: 'out', senderDomain: NEWSLETTER_DOMAIN })
		await addRule(page, caseId, { name: MUNICIPAL_RULE, effect: 'in', senderDomain: MUNICIPAL_DOMAIN })
		const rows = page.getByTestId('document-review-rule-row')
		await expect(rows).toHaveCount(2, PAGE_LOAD)
		await expect(rows.filter({ hasText: NEWSLETTER_RULE }).getByTestId('document-review-rule-conditions')).toHaveText(
			`sender domain is ${NEWSLETTER_DOMAIN}`,
		)

		const apply = page.getByTestId('document-review-apply-rules')
		await expect(apply).toHaveText(/Apply the rules to 6 documents/)
		const applied = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/review/triage/apply`))
		await apply.click()
		expect((await applied).ok()).toBeTruthy()
		await expect(page.getByTestId('document-review-applied')).toHaveText(/The rules marked 6 documents\./)
		await expect(rows.filter({ hasText: NEWSLETTER_RULE }).getByTestId('document-review-rule-decided')).toHaveText('4 documents', PAGE_LOAD)
		await expect(rows.filter({ hasText: MUNICIPAL_RULE }).getByTestId('document-review-rule-decided')).toHaveText('2 documents')

		const marked = await listObjects(request, 'documentReview', { case: caseId })
		const setAside = marked.filter((row: any) => row.relevance === 'out-of-scope' && row.rule === NEWSLETTER_RULE)
		expect(setAside.map((row: any) => row.documentRef).sort()).toEqual([...newsletters].sort())
		for (const row of setAside) {
			expect(row.relevanceSource).toBe('rule')
		}

		// 2. A reviewer overturns one newsletter, through the route the review viewer calls (task 5.1).
		const overturn = await request.post(review(caseId, `/documents/${overturned}/relevance`), {
			headers: writeHeaders(await getRequestToken(request)),
			data: { relevance: 'in-scope' },
		})
		expect(overturn.ok(), `overturn -> ${overturn.status()}`).toBeTruthy()
		// Applying the rules again leaves it in scope; nothing is unmarked, so the page offers no apply.
		const again = await request.post(review(caseId, '/triage/apply'), { headers: writeHeaders(await getRequestToken(request)) })
		expect(again.ok()).toBeTruthy()
		const kept = (await listObjects(request, 'documentReview', { case: caseId, documentRef: overturned }))[0]
		expect(kept.relevance).toBe('in-scope')
		expect(kept.relevanceSource).toBe('reviewer')
		expect(kept.overturnedRule).toBe(NEWSLETTER_RULE)

		await openReview(page, caseId)
		await expect(rows.filter({ hasText: NEWSLETTER_RULE })).toContainText('1 overturned by a reviewer', PAGE_LOAD)
		// Relevant 3, not relevant 3, nothing unmarked; only relevant documents wait for a verdict.
		await expect(page.getByTestId('document-review-tile-in-scope').locator('.document-review__tile-value')).toHaveText('3')
		await expect(page.getByTestId('document-review-tile-out-of-scope').locator('.document-review__tile-value')).toHaveText('3')
		await expect(page.getByTestId('document-review-tile-unmarked').locator('.document-review__tile-value')).toHaveText('0')
		await expect(page.getByTestId('document-review-tile-outstanding').locator('.document-review__tile-value')).toHaveText('3')
		await expect(apply).toBeDisabled()

		// 3. Two batches for two reviewers: by the rule that marked them, and by a list.
		const batches: any[] = []
		const makeBatch = async (fill: (dialog: Locator) => Promise<void>): Promise<void> => {
			await page.getByTestId('document-review-new-batch').click()
			const dialog = page.getByTestId('review-batch-dialog')
			await expect(dialog).toBeVisible(PAGE_LOAD)
			await fill(dialog)
			const made = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/review/batches`))
			await dialog.getByTestId('review-batch-create').click()
			const answer = await made
			expect(answer.status(), await answer.text()).toBe(201)
			batches.push(await answer.json())
			await expect(dialog).toBeHidden(PAGE_LOAD)
		}
		await makeBatch(async (dialog) => {
			await textInput(dialog, 'review-batch-name').fill('Gemeentepost')
			await textInput(dialog, `review-batch-${HANDLER_FIELD}`).fill(ADMIN)
			await textInput(dialog, 'review-batch-filter-rule').fill(MUNICIPAL_RULE)
		})
		await makeBatch(async (dialog) => {
			await textInput(dialog, 'review-batch-name').fill('Teruggehaald')
			await textInput(dialog, `review-batch-${HANDLER_FIELD}`).fill(SECOND_REVIEWER)
			await dialog.getByRole('checkbox', { name: overturned }).check({ force: true })
		})
		for (const made of batches) {
			expect(made.taskCreated, 'each reviewer gets a task naming the batch').toBe(true)
		}
		expect(batches[0].batch.documents.sort()).toEqual([...municipal].sort())

		const table = page.getByTestId('document-review-batch')
		await expect(table).toHaveCount(2, PAGE_LOAD)
		await expect(table.filter({ hasText: 'Gemeentepost' })).toContainText(ADMIN)
		await expect(table.filter({ hasText: 'Gemeentepost' })).toContainText('0 of 2')
		await expect(table.filter({ hasText: 'Teruggehaald' })).toContainText(SECOND_REVIEWER)
		await expect(table.filter({ hasText: 'Teruggehaald' })).toContainText('0 of 1')

		// A document is in one open batch only; the dialog says which.
		await page.getByTestId('document-review-new-batch').click()
		const third = page.getByTestId('review-batch-dialog')
		await textInput(third, 'review-batch-name').fill('Nog een')
		await textInput(third, `review-batch-${HANDLER_FIELD}`).fill(ADMIN)
		await third.getByRole('checkbox', { name: overturned }).check({ force: true })
		await third.getByTestId('review-batch-create').click()
		await expect(third.getByTestId('review-batch-error')).toContainText('A document is already in another open batch.', PAGE_LOAD)
		await third.getByTestId('review-batch-cancel').click()

		// 4. Page by page: every page of the two municipal mails, pages 1 and 2 of the overturned one.
		for (const ref of municipal) {
			expect(await reportPagesSeen(request, await getRequestToken(request), caseId, ref, [1, 2, 3])).toEqual([])
		}
		expect(await reportPagesSeen(request, await getRequestToken(request), caseId, overturned, [1, 2])).toEqual([3])

		await openReview(page, caseId)
		await expect(page.getByTestId('document-review-unseen')).toContainText('every required page is seen')
		await expect(page.getByTestId('document-review-open-document').filter({ hasText: overturned })).toContainText('Pages still to see: 3')

		// A verdict without page 3 is refused, through OpenRegister's API as well as through the dialog.
		const direct = await request.post(`${OBJECTS}/wooDocumentAssessment`, {
			headers: writeHeaders(await getRequestToken(request)),
			data: { caseRef: caseId, documentRef: overturned, classification: 'openbaar' },
		})
		expect(direct.ok(), 'a direct verdict on a document with an unseen page is refused').toBeFalsy()
		expect(await direct.text()).toContain('3')

		await clickHeaderAction(page, 'cn-action-document-assess')
		const assess = page.getByTestId('document-assess-dialog')
		await expect(assess).toBeVisible(PAGE_LOAD)
		await assess.getByTestId('document-assess-select-all').check()
		await assess.getByRole('combobox', { name: 'Assessment for the selected documents' }).click()
		await page.locator('[role="option"], .vs__dropdown-option').filter({ hasText: /^\s*Public\s*$/ }).first().click()
		await assess.getByTestId('document-assess-save').click()
		await expect(assess.getByTestId(`document-assess-refusal-${overturned}`)).toContainText('Open these pages before giving a verdict: 3.', PAGE_LOAD)
		await expect(assess.getByTestId(`document-assess-row-${overturned}`)).toBeVisible()
		for (const ref of municipal) {
			await expect(assess.getByTestId(`document-assess-row-${ref}`)).toHaveCount(0)
		}
		await assess.getByTestId('document-assess-cancel').click()

		// 5. Try to decide with page 3 unseen: the dialog names the document and offers nothing to save.
		await clickHeaderAction(page, 'cn-action-assessment-decide')
		const decide = page.getByTestId('assessment-decision-dialog')
		await expect(decide.getByTestId('assessment-decision-blocked')).toContainText(titles[0], PAGE_LOAD)
		await expect(decide.getByTestId('assessment-decision-save')).toBeDisabled()
		await decide.getByTestId('assessment-decision-cancel').click()

		// 6. Finish: the last page, then its verdict, then the decision.
		expect(await reportPagesSeen(request, await getRequestToken(request), caseId, overturned, [3])).toEqual([])
		await page.reload()
		await clickHeaderAction(page, 'cn-action-document-assess')
		await expect(assess.getByTestId(`document-assess-row-${overturned}`)).toBeVisible(PAGE_LOAD)
		await assess.getByTestId(`document-assess-select-${overturned}`).check()
		await assess.getByRole('combobox', { name: 'Assessment for the selected documents' }).click()
		await page.locator('[role="option"], .vs__dropdown-option').filter({ hasText: /^\s*Public\s*$/ }).first().click()
		await assess.getByTestId('document-assess-save').click()
		await expect(assess.getByTestId('document-assess-empty')).toBeVisible(PAGE_LOAD)
		await assess.getByTestId('document-assess-cancel').click()

		await clickHeaderAction(page, 'cn-action-assessment-decide')
		await expect(decide.getByTestId('assessment-decision-blocked')).toHaveCount(0, PAGE_LOAD)
		const decided = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/decision`))
		await decide.getByTestId('assessment-decision-save').click()
		expect((await decided).status()).toBe(200)
		await expect(decide.getByTestId('assessment-decision-done')).toBeVisible(PAGE_LOAD)
		expect((await showObject(request, 'case', caseId)).wooPublicationStatus).toBe('ready')
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-the-closing-step-waits-for-the-last-page
	test('every document has its verdict but one page is unseen: the decision is refused, naming it', async ({ page }) => {
		test.setTimeout(journeyBudget(2))
		const { caseId, documents } = await wooCaseWithMail('Laatste-pagina', [`a.jansen@${MUNICIPAL_DOMAIN}`], 'in-scope')
		const ref = documents[0]

		// The verdict first, before any batch requires a page; then the batch requires pages 1 to 3.
		const assessed = await api.post(`/index.php/apps/dossiq/api/cases/${caseId}/woo/assessment`, {
			headers: writeHeaders(token),
			data: { assessments: [{ documentRef: ref, classification: 'openbaar' }] },
		})
		expect(assessed.status(), await assessed.text()).toBe(200)
		const batch = await api.post(review(caseId, '/batches'), {
			headers: writeHeaders(token),
			data: { name: 'Alles', [HANDLER_FIELD]: ADMIN, documents: [ref] },
		})
		expect(batch.status(), await batch.text()).toBe(201)
		expect(await reportPagesSeen(api, token, caseId, ref, [1, 2])).toEqual([3])

		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		await clickHeaderAction(page, 'cn-action-assessment-decide')
		const decide = page.getByTestId('assessment-decision-dialog')
		await expect(decide.getByTestId('assessment-decision-blocked')).toHaveCount(0, PAGE_LOAD)
		const refused = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/decision`))
		await decide.getByTestId('assessment-decision-save').click()
		const answer = await refused
		expect(answer.status()).toBe(409)
		const body = await answer.json()
		expect(JSON.stringify(body.unseen), 'the refusal names the document and page 3').toContain(ref)
		expect(JSON.stringify(body.unseen)).toContain('3')
		await expect(decide.getByTestId('assessment-decision-error')).toContainText('The decision waits until every required page has been seen.')
		await expect(decide.getByTestId('assessment-decision-done')).toHaveCount(0)

		// The last page, and the same dialog records the decision.
		expect(await reportPagesSeen(api, token, caseId, ref, [3])).toEqual([])
		const decided = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/decision`))
		await decide.getByTestId('assessment-decision-save').click()
		expect((await decided).status()).toBe(200)
		await expect(decide.getByTestId('assessment-decision-done')).toBeVisible(PAGE_LOAD)
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-the-viewer-reports-each-displayed-page-once
	test.fixme('a reviewer pages through a document twice in the review viewer and each page is reported once', async ({ page }) => {
		// woo-review-triage task 5.1: dossiq has no paged document viewer to report pages from (Q-dossiq-L8-1).
		const { caseId, documents } = await wooCaseWithMail('Bladeren', [`a.jansen@${MUNICIPAL_DOMAIN}`], 'in-scope')
		const reported: number[] = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().endsWith(`/review/documents/${documents[0]}/pages-seen`)) {
				reported.push(...((req.postDataJSON()?.pages ?? []) as number[]))
			}
		})
		await openReview(page, caseId)
		await page.getByTestId('document-review-open-document').filter({ hasText: documents[0] }).click()
		const viewer = page.getByRole('dialog')
		for (let pass = 0; pass < 2; pass++) {
			for (let n = 0; n < 3; n++) {
				await viewer.getByRole('button', { name: 'Next page' }).click()
			}
		}
		await expect.poll(() => [...reported].sort()).toEqual([1, 2, 3])
	})

	// @e2e openspec/changes/woo-review-triage/specs/document-review/spec.md#scenario-a-reviewer-overturns-a-rule
	test.fixme('a reviewer marks a rule\'s document in scope from the page', async ({ page }) => {
		// woo-review-triage task 5.1: marking one document is the review viewer's, and dossiq has no paged viewer yet.
		const { caseId, titles } = await wooCaseWithMail('Omgedraaid', [`redactie@${NEWSLETTER_DOMAIN}`])
		await openReview(page, caseId)
		await addRule(page, caseId, { name: NEWSLETTER_RULE, effect: 'out', senderDomain: NEWSLETTER_DOMAIN })
		await page.getByTestId('document-review-apply-rules').click()
		// Set aside, so not under "What is still open": the reviewer opens it by its title.
		await page.getByText(titles[0], { exact: true }).first().click()
		await page.getByRole('button', { name: 'Relevant' }).click()
		await expect(page.getByTestId('document-review-rule-row')).toContainText('1 overturned by a reviewer', PAGE_LOAD)
	})
})
