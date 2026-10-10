import type { APIRequestContext, Page } from '@playwright/test'

/**
 * The AI features panel on the case (board DqZaakAssistent).
 *
 * 🔴 HERMIQ'S ANSWERS ARE FAKED AT THE NETWORK, DOSSIQ'S PAGE IS REAL. The
 * e2e instance may not run hermiq, and a feature bound to an on-premise
 * provider, a redaction refusal and a group of two hundred reports are not
 * states a test can produce in hermiq on demand. So the three answers hermiq
 * gives (where a feature runs, a refusal, a group) are routed in the browser,
 * and everything dossiq renders from them is the real panel on a real case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

let api: APIRequestContext
let token: string
let caseId = ''

/** A feature hermiq binds to an on-premise provider. */
const SUMMARY = {
	slug: 'document-summary',
	surface: 'case',
	available: true,
	provider: 'Hermiq',
	residency: 'on-premise',
}

/**
 * Route hermiq's answers, and the declaration and documents the panel reads.
 *
 * @param page The page.
 * @param group The report-group answer.
 */
async function fakeHermiq(page: Page, group: object): Promise<void> {
	await page.route(
		'**/apps/openregister/api/objects/dossiq/caseType/**',
		async (route) => {
			const response = await route.fetch()
			const body = await response.json()
			await route.fulfill({
				response,
				json: { ...body, aiFeatures: { 'document-summary': 'case' } },
			})
		},
	)
	await page.route('**/apps/dossiq/api/assistant/ai-features**', (route) =>
		route.fulfill({ json: { features: [SUMMARY] } }),
	)
	await page.route('**/apps/dossiq/api/report-group**', (route) =>
		route.fulfill({ json: group }),
	)
	await page.route(
		`**/apps/openregister/api/objects/dossiq/case/${caseId}/files`,
		(route) =>
			route.fulfill({
				json: { results: [{ id: 42, title: 'offerte-verlichting.pdf' }] },
			}),
	)
	await page.route('**/apps/hermiq/api/ai-features/*/run-on-document', (route) =>
		route.fulfill({
			status: 422,
			json: { error: 'The document is not redacted.', gate: 'redaction' },
		}),
	)
}

/**
 * Open the case and its assistant tab.
 *
 * @param page The page.
 */
async function openAssistant(page: Page): Promise<void> {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await dismissSupportDialog(page)
	await page.getByRole('tab', { name: 'Assistant' }).click()
	await expect(page.getByTestId('case-ai-features')).toBeVisible()
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const caseType = await ensureCaseType(api, token)
	const seeded = await seedCase(api, token, {
		title: `${RUN_PREFIX} verlichting fietspad`,
		caseType: caseType.id,
	})
	caseId = objectId(seeded)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token, ['case'])
	await api.dispose()
})

test.describe('AI features on the case', () => {
	// @e2e openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#the-case-type-screen-shows-where-each-feature-runs
	test('each feature shows the provider and where it runs, as hermiq answers', async ({
		page,
	}) => {
		await fakeHermiq(page, { declared: false, available: false, group: null })
		await openAssistant(page)

		await expect(
			page.getByTestId('case-ai-feature-where-document-summary'),
		).toHaveText('Hermiq, runs in on-premise')
	})

	// @e2e openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#an-unredacted-document-is-refused-in-words-a-handler-can-act-on
	test('a redaction refusal names the feature and the gate', async ({ page }) => {
		await fakeHermiq(page, { declared: false, available: false, group: null })
		await openAssistant(page)

		await page
			.getByTestId('case-ai-feature-document-document-summary')
			.selectOption('42')
		await page.getByTestId('case-ai-feature-run-document-summary').click()

		const refusal = page.getByTestId('case-ai-feature-refusal-document-summary')
		await expect(refusal).toContainText(
			'document-summary will not read a document that has not been redacted.',
		)
		await expect(refusal).toContainText('redaction')
	})

	// @e2e openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#a-report-shows-the-count-of-its-group
	// @e2e openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#the-near-duplicates-are-visible-beside-the-group
	test('the group shows its count, with the uncertain member beside it', async ({
		page,
	}) => {
		await fakeHermiq(page, {
			declared: true,
			available: true,
			group: {
				groupId: 'g-7',
				count: 200,
				nearDuplicates: [
					{ reportId: 'case-9', score: 0.61, decidedBy: 'model' },
				],
			},
		})
		await openAssistant(page)

		await expect(page.getByTestId('case-ai-group-count')).toHaveText(
			'200 reports in this group',
		)
		await expect(page.getByTestId('case-ai-group-near-item')).toHaveCount(1)
		await expect(page.getByTestId('case-ai-group-near-item')).toContainText(
			'case-9',
		)
	})
	// @e2e openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#the-prompts-offered-on-a-case-come-from-hermiq
	test("the questions offered are hermiq's prompts, in hermiq's order", async ({
		page,
	}) => {
		await fakeHermiq(page, { declared: false, available: false, group: null })
		await page.route('**/apps/dossiq/api/assistant/prompts', (route) =>
			route.fulfill({
				json: {
					prompts: [
						{
							id: 'p-2',
							label: 'Samenvatting van de mails',
							prompt: 'Vat de mails samen.',
						},
						{
							id: 'p-1',
							label: 'Wat is de volgende stap?',
							prompt: 'Volgende stap?',
						},
					],
				},
			}),
		)
		await openAssistant(page)

		const questions = page.getByTestId('case-ai-questions').getByRole('button')
		await expect(questions).toHaveText([
			'Samenvatting van de mails',
			'Wat is de volgende stap?',
		])
	})
})
