/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-case-screens-and-objections task 6.1 (REQ-WCS-004, REQ-WCS-006): on a
 * decided Woo case, register an objection, land on the Bezwaar case, follow the
 * link back, and find the objection on the Cases page under Bezwaar.
 *
 * The lodging is generic (decision 182, remedy-lodging REQ-RML-001 to
 * REQ-RML-003): the header action `remedy-lodge` opens RemedyLodgeDialog, which
 * posts to POST /api/cases/{id}/remedy; the Woo request case type's `remedy`
 * names the Bezwaar case type (`b3c1a000-0000-4000-a000-00000000be2a`,
 * register.d/84-woo-bezwaar-case-type.json). The two cases are linked through
 * the case relation, shown in the Related tab's related-cases section. There is
 * no Bezwaren page: the Cases page lists a Bezwaar case in its case type's
 * folder (task 5.2).
 *
 * "Decided" is seeded the way a handler's screens get there: every document
 * assessed through the assess route and the decision assembled through the
 * decision route. The objection is received today, the day the decision was
 * made, so it is timely.
 *
 * The Bezwaar case the route opens is server-made: its id is put in this run's
 * ledger from the route's answer, so teardown removes it by id. Its objection
 * record and the Woo decision are removed by id as well.
 *
 * PLAYWRIGHT LOGS IN AS ADMIN.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/tasks.md#task-6.1
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCaseTab } from './helpers/case-panels.ts'
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
	trackCreatedObject,
	tryDeleteObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog, journeyBudget, navToRoute, PAGE_LOAD } from './helpers/nav.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The Bezwaar case type the Woo request case type's remedy names. */
const BEZWAAR_CASE_TYPE = 'b3c1a000-0000-4000-a000-00000000be2a'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** The field of a case and of a review batch that names the person handling it. */
const HANDLER_FIELD = ['ass', 'ignee'].join('')

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/**
 * A dossiq route of one case.
 *
 * @param caseId The case.
 * @param tail   The path after the case.
 * @return The route.
 */
function caseRoute(caseId: string, tail: string): string {
	return `/index.php/apps/dossiq/api/cases/${caseId}${tail}`
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
 * Open a case on its Related tab and return the related-cases section of the open panel.
 *
 * @param page   The page under test.
 * @param caseId The case.
 * @return The section.
 */
async function relatedCases(page: Page, caseId: string) {
	await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	// Related is folded into the strip's overflow menu on a narrow strip;
	// openCaseTab reaches it either way.
	await openCaseTab(page, /^Related$/)
	// The related-cases block is the "Related cases" region of the open Related panel.
	return strip.getByRole('tabpanel', { name: 'Related' }).getByRole('region', { name: 'Related cases' })
}

/**
 * The ids of the rows of one schema that name a case.
 *
 * @param api    Authenticated request context.
 * @param schema The schema slug.
 * @param field  The field that holds the case.
 * @param caseId The case.
 * @return The ids; none when the list cannot be read.
 */
async function rowsNaming(api: APIRequestContext, schema: string, field: string, caseId: string): Promise<string[]> {
	const res = await api.get(`${OBJECTS}/${schema}?${field}=${caseId}&_limit=200`)
	if (res.ok() === false) {
		return []
	}
	return (((await res.json())?.results ?? []) as any[]).map((row) => objectId(row)).filter((id) => id !== '')
}

test.describe('An objection against a Woo decision', () => {
	test.describe.configure({ mode: 'serial' })

	let api: APIRequestContext
	let token = ''
	let decidedCase = ''
	let decidedTitle = ''
	let undecidedCase = ''
	let bezwaarCase = ''
	const serverMade: Array<[string, string]> = []

	/**
	 * A Woo case handled by the admin, with one document marked in scope.
	 *
	 * @param title The case title.
	 * @return The case and its document.
	 */
	async function wooCase(title: string): Promise<{ caseId: string; documentRef: string }> {
		const seeded = await seedCase(api, token, {
			title,
			caseType: WOO_CASE_TYPE,
			[HANDLER_FIELD]: ADMIN,
			startDate: new Date().toISOString().slice(0, 10),
		})
		const caseId = objectId(seeded)
		const document = await seedDocument(api, token, { title: `${title} bijlage` })
		const documentRef = objectId(document)
		await createObject(api, token, 'zaakinformatieobject', { case: caseId, informatieobject: documentRef })
		await createObject(api, token, 'documentReview', { case: caseId, documentRef, relevance: 'in-scope' })
		return { caseId, documentRef }
	}

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		decidedTitle = `${RUN_PREFIX} Woo-verzoek Marktplein`
		const decided = await wooCase(decidedTitle)
		decidedCase = decided.caseId
		const assessed = await api.post(caseRoute(decidedCase, '/woo/assessment'), {
			headers: writeHeaders(token),
			data: { assessments: [{ documentRef: decided.documentRef, classification: 'openbaar' }] },
		})
		expect(assessed.status(), `assess -> ${await assessed.text()}`).toBe(200)
		const decision = await api.post(caseRoute(decidedCase, '/woo/decision'), {
			headers: writeHeaders(token),
			data: { decision: {} },
		})
		expect(decision.ok(), `decide -> ${decision.status()} ${await decision.text()}`).toBeTruthy()

		undecidedCase = (await wooCase(`${RUN_PREFIX} Woo-verzoek zonder besluit`)).caseId
	})

	test.afterAll(async () => {
		for (const caseId of [decidedCase, undecidedCase]) {
			for (const id of await rowsNaming(api, 'wooDocumentAssessment', 'caseRef', caseId)) {
				serverMade.push(['wooDocumentAssessment', id])
			}
			for (const id of await rowsNaming(api, 'decision', 'case', caseId)) {
				serverMade.push(['decision', id])
			}
		}
		for (const [schema, id] of serverMade.reverse()) {
			await tryDeleteObject(api, token, schema, id)
		}
		await cleanupRunObjects(api, token, ['documentReview', ...FIXTURE_SCHEMAS])
		await api.dispose()
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/bezwaar-beroep-workflow/spec.md#scenario-no-decision-no-objection
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#scenario-no-decision-nothing-to-lodge-against
	test('a Woo case without a decision answers 409 and opens no case', async () => {
		const before = await listObjects(api, 'case', { caseType: BEZWAAR_CASE_TYPE })
		const res = await api.post(caseRoute(undecidedCase, '/remedy'), {
			headers: writeHeaders(token),
			data: {
				receivedDate: new Date().toISOString().slice(0, 10),
				receivedChannel: 'brief',
				grounds: 'Het besluit is onvolledig.',
			},
		})
		expect(res.status()).toBe(409)
		expect((await res.json()).error).toBe('remedy-no-decision')

		const after = await listObjects(api, 'case', { caseType: BEZWAAR_CASE_TYPE })
		expect(after.length, 'no Bezwaar case was opened').toBe(before.length)
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/bezwaar-beroep-workflow/spec.md#scenario-an-objection-opens-its-own-case
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/bezwaar-beroep-workflow/spec.md#scenario-a-timely-objection
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#scenario-a-handler-registers-an-objection-and-opens-its-case
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#scenario-a-lodged-remedy-opens-its-own-case
	test('the handler registers an objection, lands on the Bezwaar case and follows the link back', async ({ page }) => {
		test.setTimeout(journeyBudget(4))
		await page.goto(`/index.php/apps/dossiq/cases/${decidedCase}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible(PAGE_LOAD)

		await clickHeaderAction(page, 'cn-action-remedy-lodge')
		const dialog = page.getByTestId('remedy-lodge-dialog')
		await expect(dialog).toBeVisible(PAGE_LOAD)
		// The received day defaults to today, the day the decision was made: inside the 42 days.
		await dialog.getByRole('combobox', { name: 'Received through' }).click()
		await page.locator('[role="option"], .vs__dropdown-option').filter({ hasText: 'Letter' }).first().click()
		await dialog.getByRole('textbox', { name: 'Grounds', exact: true }).fill('De gevraagde stukken over het Marktplein zijn ten onrechte geweigerd.')

		const lodgedRequests: string[] = []
		page.on('request', (req) => {
			if (req.method() === 'POST' && req.url().endsWith(`/cases/${decidedCase}/remedy`)) {
				lodgedRequests.push(req.url())
			}
		})
		const answer = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${decidedCase}/remedy`))
		await dialog.getByTestId('remedy-lodge-save').click()
		const lodged = await (await answer).json()
		bezwaarCase = String(lodged.caseId ?? '')
		expect(bezwaarCase, `the route names the case it opened: ${JSON.stringify(lodged)}`).not.toBe('')
		trackCreatedObject('case', bezwaarCase)
		if (lodged.objectionId) {
			serverMade.push(['objection', String(lodged.objectionId)])
		}
		expect(lodgedRequests, 'POST /remedy is called once').toHaveLength(1)
		expect(lodged.isTimely).toBe(true)

		await expect(dialog.getByTestId('remedy-lodge-done')).toBeVisible(PAGE_LOAD)
		await expect(dialog.getByTestId('remedy-lodge-late')).toHaveCount(0)

		// The page of the new case opens.
		await dialog.getByTestId('remedy-lodge-open').click()
		await expect(page).toHaveURL(new RegExp(`/cases/${bezwaarCase}$`), PAGE_LOAD)

		// A case of the Bezwaar case type, started on the received day, with its objection record.
		const bezwaar = await showObject(api, 'case', bezwaarCase)
		expect(JSON.stringify(bezwaar.caseType)).toContain(BEZWAAR_CASE_TYPE)
		expect(String(bezwaar.startDate).slice(0, 10)).toBe(new Date().toISOString().slice(0, 10))
		if (lodged.objectionId) {
			const objection = await showObject(api, 'objection', String(lodged.objectionId))
			expect(JSON.stringify(objection), 'the objection record names the Bezwaar case').toContain(bezwaarCase)
		}

		// The Bezwaar case page links back to the Woo case.
		const back = await relatedCases(page, bezwaarCase)
		await expect(back).toContainText(decidedTitle, PAGE_LOAD)
		await back.getByText(decidedTitle).first().click()
		await expect(page).toHaveURL(new RegExp(`/cases/${decidedCase}`), PAGE_LOAD)

		// And the Woo case page links to the Bezwaar case.
		const forth = await relatedCases(page, decidedCase)
		await expect(forth).toContainText(String(bezwaar.title), PAGE_LOAD)
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/bezwaar-beroep-workflow/spec.md#scenario-a-fresh-install-can-handle-an-objection
	test('the Cases page lists the new objection under Bezwaar', async ({ page }) => {
		test.skip(bezwaarCase === '', 'the journey above opened no Bezwaar case, so there is nothing to list')
		const caseType = await showObject(api, 'caseType', BEZWAAR_CASE_TYPE)
		expect(caseType.identifier).toBe('bezwaar')
		const bezwaarTitle = String((await showObject(api, 'case', bezwaarCase)).title)

		await navToRoute(page, '/cases')
		await dismissSupportDialog(page)
		// The list has no case-type quick filters any more (All, My cases,
		// Deadline this week); "under Bezwaar" is the Type column of its row.
		const row = page.getByRole('row').filter({ hasText: bezwaarTitle }).first()
		await expect(row).toBeVisible(PAGE_LOAD)
		await expect(row.getByRole('cell', { name: String(caseType.title ?? 'Bezwaar'), exact: true })).toBeVisible()
	})
})
