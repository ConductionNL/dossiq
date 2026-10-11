/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * document-review (woo-review-triage and woo-review-recall-and-stopping,
 * generic per decision 182): relevance apart from the verdict, triage rules,
 * batches, review depth, pages seen, the stopping rule and the recall estimate,
 * on a case whose case type sets `documentReview.enabled`.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here; the live-pass
 * agent owns the run. The triage-rule tests wait on task 2.1 (the rules route is
 * not built yet) and will be red until it is; they are written now so the
 * scenarios carry their test from the day the spec names them.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The review routes of a case. */
const review = (caseId: string, path: string): string =>
	`/index.php/apps/dossiq/api/cases/${caseId}/review${path}`

/** A case type that reviews its documents, with exports sampled at five pages. */
async function reviewingCaseType(
	api: APIRequestContext,
	token: string,
): Promise<string> {
	const type = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} Documentbeoordeling`,
		identifier: `${RUN_PREFIX.toLowerCase()}-beoordeling`,
		description: 'Throwaway case type that reviews its documents for relevance.',
		isDraft: false,
		documentReview: {
			enabled: true,
			reviewDepth: { export: { mode: 'sample', sampleSize: 5 } },
		},
	})
	return objectId(type)
}

/**
 * A case with `count` documents, each with a review in the given relevance.
 *
 * @return The case id and the document ids in order.
 */
async function caseWithDocuments(
	api: APIRequestContext,
	token: string,
	marks: Array<'in-scope' | 'out-of-scope' | 'unmarked'>,
	extra: Record<string, unknown> = {},
): Promise<{ caseId: string; documents: string[] }> {
	const created = await seedCase(api, token, {
		title: `${RUN_PREFIX} Verzoek`,
		caseType: await reviewingCaseType(api, token),
	})
	const caseId = objectId(created)
	const documents: string[] = []
	for (const [n, relevance] of marks.entries()) {
		const doc = await createObject(api, token, 'document', {
			case: caseId,
			title: `Document ${n + 1}`,
			pageCount: 3,
			...extra,
		})
		documents.push(objectId(doc))
		await createObject(api, token, 'documentReview', {
			case: caseId,
			documentRef: objectId(doc),
			relevance,
			pageCount: 3,
		})
	}
	return { caseId, documents }
}

/** Headers for a dossiq write. */
const headers = (token: string): Record<string, string> => ({
	requesttoken: token,
	'OCS-APIRequest': 'true',
})

test.describe('Document review', () => {
	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	// @e2e document-review::out-of-scope-needs-no-verdict-and-both-counts-are-reported
	test('the summary counts relevance beside the verdicts and lists what is open', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const marks = [
			...Array(6).fill('in-scope'),
			...Array(3).fill('out-of-scope'),
			'unmarked',
		] as Array<'in-scope' | 'out-of-scope' | 'unmarked'>
		const { caseId, documents } = await caseWithDocuments(request, token, marks)
		for (const ref of documents.slice(0, 4)) {
			await createObject(request, token, 'wooDocumentAssessment', {
				caseRef: caseId,
				documentRef: ref,
				classification: 'openbaar',
			})
		}

		const summary = await (await request.get(review(caseId, '/summary'))).json()
		expect(summary.enabled).toBe(true)
		expect(summary.relevance).toEqual({ unmarked: 1, inScope: 6, outOfScope: 3 })
		expect(summary.verdicts.total).toBe(4)
		expect(summary.outstanding.count).toBe(3)
	})

	// @e2e document-review::a-rule-sets-aside-newsletters
	// @e2e document-review::a-reviewer-overturns-a-rule
	test('a rule sets aside newsletters, and a reviewer overturns one', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId, documents } = await caseWithDocuments(
			request,
			token,
			Array(5).fill('unmarked'),
			{ senderDomain: 'nieuwsbrief.example.nl' },
		)
		await createObject(request, token, 'triageRule', {
			case: caseId,
			name: 'Nieuwsbrieven',
			conditions: { senderDomain: 'nieuwsbrief.example.nl' },
			effect: 'out-of-scope',
			order: 1,
			active: true,
		})

		const applied = await request.post(review(caseId, '/triage/apply'), {
			headers: headers(token),
		})
		expect(applied.ok()).toBeTruthy()
		const reviews = await listObjects(request, 'documentReview', {
			case: caseId,
		})
		expect(
			reviews.filter(
				(row: any) =>
					row.relevance === 'out-of-scope' && row.rule === 'Nieuwsbrieven',
			),
		).toHaveLength(5)

		await request.post(review(caseId, `/documents/${documents[0]}/relevance`), {
			headers: headers(token),
			data: { relevance: 'in-scope' },
		})
		await request.post(review(caseId, '/triage/apply'), {
			headers: headers(token),
		})
		const overturned = (
			await listObjects(request, 'documentReview', {
				case: caseId,
				documentRef: documents[0],
			})
		)[0]
		expect(overturned.relevance).toBe('in-scope')
		expect(overturned.relevanceSource).toBe('reviewer')
		expect(overturned.overturnedRule).toBe('Nieuwsbrieven')
	})

	// @e2e document-review::two-batches-for-two-reviewers
	// @e2e document-review::a-document-cannot-be-in-two-open-batches
	test('two batches for two reviewers, and a document in only one open batch', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId, documents } = await caseWithDocuments(
			request,
			token,
			Array(4).fill('in-scope'),
		)

		const first = await request.post(review(caseId, '/batches'), {
			headers: headers(token),
			data: {
				name: 'Mail 2025',
				assignee: 'admin',
				documents: documents.slice(0, 2),
			},
		})
		expect(first.status()).toBe(201)
		const second = await request.post(review(caseId, '/batches'), {
			headers: headers(token),
			data: {
				name: 'Notities',
				assignee: 'admin',
				documents: documents.slice(2),
			},
		})
		expect(second.status()).toBe(201)
		const batches = (
			await (await request.get(review(caseId, '/batches'))).json()
		).results
		expect(batches.map((batch: any) => batch.progress)).toEqual([
			{ assessed: 0, total: 2 },
			{ assessed: 0, total: 2 },
		])

		const taken = await request.post(review(caseId, '/batches'), {
			headers: headers(token),
			data: { name: 'Nog een', assignee: 'admin', documents: [documents[0]] },
		})
		expect(taken.status()).toBe(409)
		expect((await taken.json()).taken[documents[0]]).toBe('Mail 2025')
	})

	// @e2e document-review::sampling-large-spreadsheet-exports
	test('a sampled type records five required pages with a seed, a letter every page', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} Diepte`,
			caseType: await reviewingCaseType(request, token),
		})
		const caseId = objectId(created)
		const exportDoc = await createObject(request, token, 'document', {
			case: caseId,
			title: 'Export',
			informatieobjecttype: 'export',
			pageCount: 200,
		})
		const letter = await createObject(request, token, 'document', {
			case: caseId,
			title: 'Brief',
			informatieobjecttype: 'brief',
			pageCount: 3,
		})
		for (const doc of [exportDoc, letter]) {
			await createObject(request, token, 'documentReview', {
				case: caseId,
				documentRef: objectId(doc),
				relevance: 'in-scope',
				pageCount: doc === letter ? 3 : 200,
			})
		}

		await request.post(review(caseId, '/batches'), {
			headers: headers(token),
			data: {
				name: 'Diepte',
				assignee: 'admin',
				documents: [objectId(exportDoc), objectId(letter)],
			},
		})
		const rows = await listObjects(request, 'documentReview', { case: caseId })
		const byRef = Object.fromEntries(
			rows.map((row: any) => [row.documentRef, row]),
		)
		expect(byRef[objectId(exportDoc)].depth.mode).toBe('sample')
		expect(byRef[objectId(exportDoc)].pagesRequired).toHaveLength(5)
		expect(byRef[objectId(exportDoc)].depth.seed).toBeTruthy()
		expect(byRef[objectId(letter)].pagesRequired).toEqual([1, 2, 3])
	})

	// @e2e document-review::a-verdict-set-without-opening-is-refused
	// @e2e document-review::the-closing-step-waits-for-the-last-page
	// @e2e document-review::the-viewer-reports-each-displayed-page-once
	test('no verdict and no publication before every required page is seen', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId, documents } = await caseWithDocuments(request, token, [
			'in-scope',
		])
		await request.post(review(caseId, '/batches'), {
			headers: headers(token),
			data: { name: 'Alles', assignee: 'admin', documents },
		})

		const refused = await request.post(
			`/index.php/apps/openregister/api/objects/dossiq/wooDocumentAssessment`,
			{
				headers: headers(token),
				data: {
					caseRef: caseId,
					documentRef: documents[0],
					classification: 'openbaar',
				},
			},
		)
		expect(refused.ok()).toBeFalsy()
		expect(await refused.text()).toContain('1')

		const seen = await request.post(
			review(caseId, `/documents/${documents[0]}/pages-seen`),
			{ headers: headers(token), data: { pages: [1, 2], pageCount: 3 } },
		)
		expect((await seen.json()).unseen).toEqual([3])
		const publish = await request.post(
			`/index.php/apps/dossiq/api/cases/${caseId}/woo/publish`,
			{ headers: headers(token) },
		)
		expect(publish.status()).toBe(409)

		const last = await request.post(
			review(caseId, `/documents/${documents[0]}/pages-seen`),
			{ headers: headers(token), data: { pages: [3] } },
		)
		expect((await last.json()).unseen).toEqual([])
	})

	// @e2e document-review::declared-in-advance
	// @e2e document-review::not-changeable-once-review-started
	test('a stopping rule is declared before review and fixed after the first marking', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId, documents } = await caseWithDocuments(request, token, [
			'unmarked',
			'unmarked',
		])

		const declared = await request.post(review(caseId, '/stopping-rule'), {
			headers: headers(token),
			data: { targetRecall: 0.8, confidence: 0.95 },
		})
		expect(declared.status()).toBe(201)
		expect((await declared.json()).declaredBy).toBeTruthy()

		await request.post(review(caseId, `/documents/${documents[0]}/relevance`), {
			headers: headers(token),
			data: { relevance: 'in-scope' },
		})
		const lowered = await request.post(review(caseId, '/stopping-rule'), {
			headers: headers(token),
			data: { targetRecall: 0.7, confidence: 0.95 },
		})
		expect(lowered.status()).toBe(409)
		const recall = await (await request.get(review(caseId, '/recall'))).json()
		expect(recall.rule.targetRecall).toBe(0.8)
	})

	// @e2e document-review::few-relevant-documents-in-the-null-set
	// @e2e document-review::more-relevant-documents-in-the-null-set
	// @e2e document-review::no-estimate-before-the-sample-is-judged
	// @e2e document-review::met
	// @e2e document-review::not-met
	test('a recall sample is judged, estimated, and the stopping rule recorded as met once', async ({
		request,
	}) => {
		test.setTimeout(10 * 60_000)
		const token = await getRequestToken(request)
		// 400 found and a null set of 1600, as in the spec's scenarios.
		const marks = [
			...Array(400).fill('in-scope'),
			...Array(1600).fill('out-of-scope'),
		] as Array<'in-scope' | 'out-of-scope'>
		const { caseId } = await caseWithDocuments(request, token, [])
		const created: string[] = []
		for (const relevance of marks) {
			const doc = await createObject(request, token, 'document', {
				case: caseId,
				title: 'D',
			})
			created.push(objectId(doc))
			await createObject(request, token, 'documentReview', {
				case: caseId,
				documentRef: objectId(doc),
				relevance,
			})
		}
		await createObject(request, token, 'stoppingRule', {
			case: caseId,
			targetRecall: 0.8,
			confidence: 0.95,
		})

		/** Draw a sample of 200 and judge `inScope` of them relevant, checking the half-way state on the way. */
		const sampleWith = async (inScope: number): Promise<any> => {
			const drawn = await (
				await request.post(review(caseId, '/recall/samples'), {
					headers: headers(token),
					data: { size: 200 },
				})
			).json()
			let judged: any = null
			for (const [i, ref] of drawn.documents.entries()) {
				judged = await (
					await request.post(
						review(caseId, `/recall/samples/${drawn.id}/judgements`),
						{
							headers: headers(token),
							data: { documentRef: ref, inScope: i < inScope },
						},
					)
				).json()
				if (i === 149) {
					expect(judged.progress).toEqual({ judged: 150, total: 200 })
					expect(judged.recallEstimate).toBeUndefined()
				}
			}
			return judged
		}

		const met = await sampleWith(2)
		expect(met.recallEstimate).toBe(0.9615)
		expect(met.recallLowerBound).toBe(0.8892)
		expect(met.stoppingRuleMetAt).toBeTruthy()

		const below = await sampleWith(10)
		expect(below.recallEstimate).toBe(0.8333)
		expect(below.recallLowerBound).toBe(0.75)
		expect(below.stoppingRuleMetAt).toBeUndefined()
	})

	// @e2e woo-review-triage::a-woo-verzoek-reviews-its-documents-through-the-generic-capability
	test('the seeded woo-verzoek case type switches document review on, and the case shows the Review tab', async ({
		page,
		request,
	}) => {
		const types = await listObjects(request, 'caseType', {
			identifier: 'woo-verzoek',
		})
		expect(types.length).toBeGreaterThan(0)
		expect(types[0].documentReview?.enabled).toBe(true)

		const token = await getRequestToken(request)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} Woo`,
			caseType: objectId(types[0]),
		})
		await page.goto(
			`/index.php/apps/dossiq/cases/${objectId(created)}`,
			PAGE_LOAD,
		)
		await page.getByRole('tab', { name: 'Review' }).click()
		await expect(page.getByTestId('document-review-status')).toBeVisible()
	})
})
