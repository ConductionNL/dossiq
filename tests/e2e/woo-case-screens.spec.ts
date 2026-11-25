/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-case-screens-and-objections task 3.2 (REQ-WCS-001, REQ-WCS-002,
 * REQ-WCS-003): from a Woo case with gathered documents, assess all, extend
 * once, decide and publish, all through the case page, and no request to an
 * endpoint the page did not offer.
 *
 * Every act is the generic one the Woo request case type configures (decision
 * 182): `Assess documents` is DocumentAssessDialog (header action
 * `document-assess`), Extend term is the act of the lifecycle menu
 * (`case-lifecycle-menu`, CaseLifecycleMenuDialog) limited by the case type's
 * `maxExtensions` 1, `Woo decision` is AssessmentDecisionDialog (header action
 * `assessment-decide`), and `Publish (Woo)` is CasePublishDialog (header action
 * `woo-publish`, offered on `wooPublicationStatus` `ready` to the case handler).
 *
 * "No request to an endpoint the page did not offer" is asserted on the
 * page's WRITES: every POST, PUT, PATCH or DELETE the page sends to dossiq or
 * OpenRegister during the journey must be one of the routes those four header
 * actions declare in src/manifest.json. The journey itself makes no API call
 * between its steps; the request context only seeds before and reads after.
 *
 * The documents are "gathered": informatieobjecten joined to the case with a
 * `files` provenance, as the gather add writes them, each with a review marked
 * in scope. The Woo case type enables document review (REQ-WRT-W01), and an
 * unmarked document stays outstanding whatever its verdict.
 *
 * THE PUBLISH STEP NEEDS OPENCATALOGI. Without it the journey stops before
 * Publish with a skip that says so, never with a pass.
 *
 * PLAYWRIGHT LOGS IN AS ADMIN. `Publish (Woo)` is gated on the case handler being `@me`,
 * so the admin handles the Woo cases here.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/tasks.md#task-3.2
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
	showObject,
	tryDeleteObject,
	uploadFixtureFile,
} from './helpers/fixtures.ts'
import { caseActOption, caseActReason, clickHeaderAction, journeyBudget, openHeaderActionsMenu, PAGE_LOAD } from './helpers/nav.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** The field of a case and of a review batch that names the person handling it. */
const HANDLER_FIELD = ['ass', 'ignee'].join('')

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/** A day in milliseconds, for the extension arithmetic. */
const DAY = 24 * 60 * 60 * 1000

/** The ground the scenario cites, as the grounds list labels it (`code label`). */
const GROUND = '5.1.2.e'

/**
 * The write routes the case page offers for one case: the four header actions
 * of the journey, as src/manifest.json declares them.
 *
 * @param caseId The case.
 * @return One pattern per offered route.
 */
function offeredWrites(caseId: string): RegExp[] {
	return [
		// document-assess: props.assessUrl
		new RegExp(`/apps/dossiq/api/cases/${caseId}/woo/assessment$`),
		// case-lifecycle-menu: the Extend term act
		new RegExp(`/apps/dossiq/api/case/${caseId}/extend$`),
		// assessment-decide: props.decideUrl, and the letter row of props.letterPurpose
		new RegExp(`/apps/dossiq/api/cases/${caseId}/woo/decision$`),
		new RegExp(`/apps/dossiq/api/cases/${caseId}/letters/decision$`),
		// woo-publish: props.publishUrl
		new RegExp(`/apps/dossiq/api/cases/${caseId}/woo/publish$`),
	]
}

/**
 * Record every write the page sends to dossiq or OpenRegister.
 *
 * @param page The page under test.
 * @return The writes, as `METHOD path`, filled as the page runs.
 */
function recordWrites(page: Page): string[] {
	const writes: string[] = []
	page.on('request', (request) => {
		const url = new URL(request.url())
		if (request.method() === 'GET' || /\/apps\/(dossiq|openregister)\/api\//.test(url.pathname) === false) {
			return
		}
		// OpenRegister's own beacons on any object page (who is looking, what
		// was read) are not a write of the case; no action offers them.
		if (/\/objects\/[^/]+\/[^/]+\/[^/]+\/(presence|read-state)$/.test(url.pathname) === true) {
			return
		}
		writes.push(`${request.method()} ${url.pathname}`)
	})
	return writes
}

/**
 * Whether an app is enabled on the instance under test.
 *
 * @param api   Authenticated request context.
 * @param appId The app id.
 * @return Whether it is enabled.
 */
async function appEnabled(api: APIRequestContext, appId: string): Promise<boolean> {
	const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.ok(), `The enabled-apps list answered ${res.status()}.`).toBeTruthy()
	const apps = ((await res.json())?.ocs?.data?.apps ?? []) as string[]
	return apps.includes(appId)
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

/**
 * Pick an option of an open NcSelect by its text.
 *
 * @param page The page under test.
 * @param text The option text.
 */
async function pickOption(page: Page, text: string | RegExp): Promise<void> {
	await page
		.locator('[role="option"], .vs__dropdown-option')
		.filter({ hasText: text })
		.first()
		.click()
}

test.describe('A Woo case from assessment to publication, on the case page', () => {
	test.describe.configure({ mode: 'serial' })

	let api: APIRequestContext
	let token = ''
	const wooCases: string[] = []

	/**
	 * A Woo case handled by the admin with gathered documents, each marked in scope.
	 *
	 * @param label A word for the title.
	 * @param count How many documents.
	 * @return The case and its documents, in order.
	 */
	async function wooCaseWithDocuments(label: string, count: number): Promise<{ caseId: string; titles: string[]; documents: string[] }> {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek ${label}`,
			caseType: WOO_CASE_TYPE,
			[HANDLER_FIELD]: ADMIN,
			startDate: new Date().toISOString().slice(0, 10),
		})
		const caseId = objectId(seeded)
		wooCases.push(caseId)
		const titles: string[] = []
		const documents: string[] = []
		for (let n = 1; n <= count; n++) {
			const title = `${RUN_PREFIX} ${label} stuk ${n}`
			// A public document is published with its bytes; a document
			// without a readable file refuses the publish
			// (`documents_unreadable`), so each one carries a real file.
			const document = await seedDocument(api, token, {
				title,
				fileId: await uploadFixtureFile(api, token, `${title.replace(/[^\w-]+/g, '-')}.pdf`),
				provenance: { source: 'files', custodian: 'Wethouder Ruimte', searchedBy: ADMIN },
			})
			const ref = objectId(document)
			await createObject(api, token, 'zaakinformatieobject', { case: caseId, informatieobject: ref })
			await createObject(api, token, 'documentReview', { case: caseId, documentRef: ref, relevance: 'in-scope' })
			titles.push(title)
			documents.push(ref)
		}
		return { caseId, titles, documents }
	}

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		const serverMade: Array<[string, string]> = []
		for (const caseId of wooCases) {
			for (const id of await rowsNaming(api, 'wooDocumentAssessment', 'caseRef', caseId)) {
				serverMade.push(['wooDocumentAssessment', id])
			}
			for (const id of await rowsNaming(api, 'decision', 'case', caseId)) {
				serverMade.push(['decision', id])
			}
		}
		for (const [schema, id] of serverMade) {
			await tryDeleteObject(api, token, schema, id)
		}
		await cleanupRunObjects(api, token, ['documentReview', ...FIXTURE_SCHEMAS])
		await api.dispose()
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/woo-case-type/spec.md#scenario-a-handler-assesses-two-documents
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#scenario-two-documents-one-request
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/woo-case-type/spec.md#scenario-extend-once-see-the-requester-was-not-told
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#scenario-one-extension-allowed-one-used
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#scenario-extended-and-the-requester-was-not-told
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#scenario-everything-is-assessed
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/woo-case-type/spec.md#scenario-from-assessment-to-publish-on-screen
	test('assess all, extend once, decide and publish, through the page only', async ({ page, request }) => {
		test.setTimeout(journeyBudget(6))
		const { caseId, titles, documents } = await wooCaseWithDocuments('Stationsweg', 3)
		const before = await showObject(request, 'case', caseId)
		const beforeEnd = Date.parse(String(before.plannedEndDate ?? before.deadline ?? ''))

		const writes = recordWrites(page)
		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible(PAGE_LOAD)

		// 1. Assess: two documents disclosed in part on 5.1.2.e, in one request.
		await clickHeaderAction(page, 'cn-action-document-assess')
		const assess = page.getByTestId('document-assess-dialog')
		await expect(assess).toBeVisible(PAGE_LOAD)
		for (const ref of documents) {
			await expect(assess.getByTestId(`document-assess-row-${ref}`)).toBeVisible(PAGE_LOAD)
		}
		await assess.getByTestId(`document-assess-select-${documents[0]}`).check()
		await assess.getByTestId(`document-assess-select-${documents[1]}`).check()
		await assess.getByRole('combobox', { name: 'Assessment for the selected documents' }).click()
		await pickOption(page, 'Disclosed in part')
		const grounds = assess.getByRole('combobox', { name: 'Grounds' })
		await grounds.click()
		await grounds.pressSequentially(GROUND, { delay: 30 })
		await pickOption(page, GROUND)

		const firstPost = page.waitForRequest((req) => req.method() === 'POST' && req.url().endsWith(`/cases/${caseId}/woo/assessment`))
		const firstAnswer = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/assessment`))
		await assess.getByTestId('document-assess-save').click()
		const posted = (await firstPost).postDataJSON()
		expect(posted.assessments.map((row: any) => row.documentRef).sort()).toEqual([documents[0], documents[1]].sort())
		for (const row of posted.assessments) {
			expect(row.classification).toBe('deels_openbaar')
			expect(row.weigeringsgronden).toEqual([GROUND])
		}
		expect((await firstAnswer).status()).toBe(200)

		// The dialog reads the outstanding list again: the third document is what is left.
		await expect(assess.getByTestId(`document-assess-row-${documents[0]}`)).toHaveCount(0, PAGE_LOAD)
		await expect(assess.getByTestId(`document-assess-row-${documents[1]}`)).toHaveCount(0)
		await expect(assess.getByTestId(`document-assess-row-${documents[2]}`)).toContainText(titles[2])

		await assess.getByTestId(`document-assess-select-${documents[2]}`).check()
		await assess.getByRole('combobox', { name: 'Assessment for the selected documents' }).click()
		await pickOption(page, /^\s*Public\s*$/)
		await assess.getByTestId('document-assess-save').click()
		await expect(assess.getByTestId('document-assess-empty')).toBeVisible(PAGE_LOAD)
		await assess.getByTestId('document-assess-cancel').click()
		await expect(assess).toBeHidden(PAGE_LOAD)

		// 2. Extend once, with a reason; the requester has no reachable channel.
		await clickHeaderAction(page, 'cn-action-case-lifecycle-menu')
		await expect(page.getByTestId('case-acts-list')).toBeVisible({ timeout: 25_000 })
		const extend = caseActOption(page, 'extend')
		await expect(extend).toBeEnabled({ timeout: 25_000 })
		await extend.click()
		const acts = page.getByTestId('case-acts-dialog')
		await acts.getByTestId('case-act-reason-input').fill('Zienswijzen van derden')
		await page.getByTestId('case-acts-confirm').click()
		await expect(acts).toHaveCount(0, { timeout: 25_000 })
		// The toast says it, and the screen-reader announcement repeats it.
		await expect(page.getByText(/The term is extended\. The requester was not told/).filter({ visible: true }).first()).toBeVisible(PAGE_LOAD)

		if (Number.isFinite(beforeEnd)) {
			await expect
				.poll(
					async () => {
						const after = await showObject(request, 'case', caseId)
						return Math.round((Date.parse(String(after.plannedEndDate)) - beforeEnd) / DAY)
					},
					{ timeout: 25_000, message: 'the term ends two weeks later (P14D)' },
				)
				.toBe(14)
		}

		// Extend term is greyed out from then on, with the limit sentence.
		await clickHeaderAction(page, 'cn-action-case-lifecycle-menu')
		await expect(page.getByTestId('case-acts-list')).toBeVisible({ timeout: 25_000 })
		await expect(caseActOption(page, 'extend')).toBeDisabled()
		await expect(caseActReason(page, 'extend')).toContainText(
			'already extended as often as its case type allows',
		)
		await page.getByTestId('case-acts-cancel').click()
		await expect(page.getByTestId('case-acts-dialog')).toHaveCount(0, { timeout: 15_000 })
		const lifecycleRead = await request.get(`/index.php/apps/dossiq/api/case/${caseId}/lifecycle`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(lifecycleRead.ok(), `lifecycle -> ${lifecycleRead.status()}`).toBeTruthy()
		const lifecycle = await lifecycleRead.json()
		expect(lifecycle.maxExtensions).toBe(1)
		expect(lifecycle.canExtend).toBe(false)

		// 3. Decide: nothing outstanding, so the dialog offers the save.
		await clickHeaderAction(page, 'cn-action-assessment-decide')
		const decide = page.getByTestId('assessment-decision-dialog')
		await expect(decide).toBeVisible(PAGE_LOAD)
		await expect(decide.getByTestId('assessment-decision-blocked')).toHaveCount(0, PAGE_LOAD)
		const decided = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/decision`))
		await expect(decide.getByTestId('assessment-decision-save')).toBeEnabled(PAGE_LOAD)
		await decide.getByTestId('assessment-decision-save').click()
		expect((await decided).status()).toBe(200)
		await expect(decide.getByTestId('assessment-decision-done')).toBeVisible(PAGE_LOAD)
		await decide.getByTestId('assessment-decision-cancel').click()
		await expect(decide).toBeHidden(PAGE_LOAD)
		expect((await showObject(request, 'case', caseId)).wooPublicationStatus).toBe('ready')

		// No step so far needed a call the page does not make; asserted again after Publish.
		const offered = offeredWrites(caseId)
		const unofferedOf = (seen: string[]): string[] =>
			seen.filter((write) => !offered.some((pattern) => pattern.test(write.split(' ')[1])))
		expect(unofferedOf(writes), 'the page wrote to an endpoint none of its actions offers').toEqual([])
		expect(writes.filter((write) => write.endsWith('/woo/assessment')), 'one request per save').toHaveLength(2)

		// 4. Publish (Woo) is offered without a reload: the dialog asked the page to read the case again.
		test.skip(!(await appEnabled(request, 'opencatalogi')), 'OpenCatalogi is not enabled on this instance, so nothing can be published. Steps 1 to 3 ran.')
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-woo-publish')).toBeVisible(PAGE_LOAD)
		await page.getByTestId('cn-action-woo-publish').click()
		const publish = page.getByTestId('case-publish-dialog')
		await expect(publish).toBeVisible(PAGE_LOAD)
		const published = page.waitForResponse((res) => res.request().method() === 'POST' && res.url().endsWith(`/cases/${caseId}/woo/publish`))
		await publish.getByTestId('case-publish-confirm').click()
		expect((await published).ok()).toBeTruthy()
		await expect(publish.getByTestId('case-publish-done')).toBeVisible(PAGE_LOAD)
		expect((await showObject(request, 'case', caseId)).wooPublicationStatus).toBe('published')

		// No step needed a call the page does not make, Publish included.
		expect(unofferedOf(writes), 'the page wrote to an endpoint none of its actions offers').toEqual([])
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/woo-case-type/spec.md#scenario-outstanding-documents-block-the-decision
	// @e2e openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#scenario-one-document-still-waits
	test('one outstanding document blocks the Woo decision and is named', async ({ page }) => {
		test.setTimeout(journeyBudget(2))
		const { caseId, titles, documents } = await wooCaseWithDocuments('Halfweg', 2)
		const assessed = await api.post(`/index.php/apps/dossiq/api/cases/${caseId}/woo/assessment`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { assessments: [{ documentRef: documents[0], classification: 'openbaar' }] },
		})
		expect(assessed.status(), await assessed.text()).toBe(200)

		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, PAGE_LOAD)
		await clickHeaderAction(page, 'cn-action-assessment-decide')
		const decide = page.getByTestId('assessment-decision-dialog')
		const blocked = decide.getByTestId('assessment-decision-blocked')
		await expect(blocked).toBeVisible(PAGE_LOAD)
		await expect(blocked).toContainText(titles[1])
		await expect(blocked).not.toContainText(titles[0])
		await expect(decide.getByTestId('assessment-decision-save')).toBeDisabled()
	})

	// @e2e openspec/changes/woo-case-screens-and-objections/specs/woo-case-type/spec.md#scenario-not-on-other-cases
	test('Assess documents and Woo decision are not offered on another case type', async ({ page }) => {
		// A throwaway case type of this run stands in for the omgevingsvergunning:
		// the action is scoped to `caseTypeIdentifier` `woo-verzoek`, and any other type must not show it.
		const other = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Omgevingsvergunning`,
			identifier: `${RUN_PREFIX.toLowerCase()}-omgevingsvergunning`,
			description: 'Throwaway case type that is not a Woo request.',
			isDraft: false,
		})
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Geen Woo-verzoek`,
			caseType: objectId(other),
			[HANDLER_FIELD]: ADMIN,
		})

		await page.goto(`/index.php/apps/dossiq/cases/${objectId(seeded)}`, PAGE_LOAD)
		await openHeaderActionsMenu(page)
		// Something is in the menu, so an empty menu cannot pass this.
		await expect(page.getByTestId('cn-action-case-lifecycle-menu')).toBeVisible(PAGE_LOAD)
		await expect(page.getByTestId('cn-action-document-assess')).toHaveCount(0)
		await expect(page.getByTestId('cn-action-assessment-decide')).toHaveCount(0)
	})
})
