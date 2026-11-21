/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * grant-cases-from-application-to-settlement task 3.1: both scenarios of
 * REQ-SUB-CASE-01 (a handler runs a grant from its case page).
 *
 * Each scenario is proven in two halves:
 *
 *  - THE ROUTES THE ACTIONS CALL. The requirement says every action "SHALL use
 *    the existing grant routes", and those routes are live:
 *    `POST /api/subsidies/uitvoeringen/{uitvoeringId}/tussenrapportages`,
 *    `POST /api/subsidies/tussenrapportages/{reportId}/beoordelen` and
 *    `POST /api/subsidies/vaststellingen/{determinationId}/vast`. They run
 *    here against a grant chain seeded on a case (case <- subsidieAanvraag <-
 *    subsidieUitvoering), and the settlement is read back through
 *    `GET /api/cases/{caseId}/settlements`, the endpoint the case page's
 *    Settlement card reads.
 *  - THE CASE PAGE. The header actions `interim-report-record`,
 *    `interim-report-assess`, `grant-settle` and `grant-recover` (task 1.2),
 *    and the interim report, evidence and recovery widgets (task 1.1b), are
 *    not built, so the page halves are `test.fixme` naming that task.
 *
 * Settling writes nothing to the case's costs (decision 258, ADR-081): the
 * settled amount stays on the vaststelling, which is what is asserted.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** dossiq's grant routes. */
const SUBSIDIES = `/index.php/apps/${REGISTER}/api/subsidies`

/** The figures of the settlement scenario, in euro. */
const GRANTED = 50_000
const ADVANCES = 40_000
const SETTLED = 35_000

let api: APIRequestContext
let token = ''

/**
 * Headers for a CSRF-protected dossiq write.
 *
 * @return The headers.
 */
function writeHeaders(): Record<string, string> {
	return { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
}

/**
 * A granted subsidy on a fresh case: application, decision and execution.
 *
 * @param label A label after the run prefix.
 * @return The ids along the chain.
 */
async function grantedSubsidy(label: string): Promise<{ caseId: string; applicationId: string; executionId: string }> {
	const caseType = await ensureCaseType(api, token)
	const caseId = objectId(
		await seedCase(api, token, { title: `${RUN_PREFIX} grant ${label}`, caseType: caseType.id }),
	)
	const applicationId = objectId(
		await createObject(api, token, 'subsidieAanvraag', {
			case: caseId,
			aanvraagnummer: `${RUN_PREFIX}-${label}`,
			subsidyScheme: caseType.id,
			requestedAmount: GRANTED,
			status: 'granted',
		}),
	)
	const decisionId = objectId(
		await createObject(api, token, 'subsidieBeschikking', {
			subsidieaanvraag: applicationId,
			beschikkingnummer: `${RUN_PREFIX}-${label}-B`,
			beschikkingtype: 'verleningsbeschikking',
			grantedAmount: GRANTED,
			status: 'granted',
		}),
	)
	const executionId = objectId(
		await createObject(api, token, 'subsidieUitvoering', {
			subsidieaanvraag: applicationId,
			subsidyDecision: decisionId,
			status: 'in_execution',
			betaaldeVoorschotten: String(ADVANCES),
		}),
	)

	return { caseId, applicationId, executionId }
}

test.describe('A handler runs a grant from its case page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, [
			'terugvordering',
			'subsidieVaststelling',
			'interimReport',
			'subsidieUitvoering',
			'subsidieBeschikking',
			'subsidieAanvraag',
			'case',
		])
		await api?.dispose()
	})

	// @e2e openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#scenario-recording-and-assessing-an-interim-report
	test('an interim report is recorded and assessed as sufficient through the grant routes', async () => {
		const { caseId, applicationId, executionId } = await grantedSubsidy('interim')

		const recorded = await api.post(`${SUBSIDIES}/uitvoeringen/${executionId}/tussenrapportages`, {
			headers: writeHeaders(),
			data: {
				rapportagenummer: `${RUN_PREFIX}-TR-1`,
				rapportagePeriodStart: '2026-01-01',
				rapportagePeriodEnd: '2026-06-30',
				substantiveProgress: 'Activiteiten volgens plan uitgevoerd.',
			},
		})
		const recordedBody = await recorded.json().catch(() => ({}))
		expect(recorded.status(), `record -> ${JSON.stringify(recordedBody)}`).toBe(201)
		const reportId = trackCreatedObject('interimReport', objectId(recordedBody))
		expect(reportId, 'the recorded report has an id').not.toBe('')

		const assessed = await api.post(`${SUBSIDIES}/tussenrapportages/${reportId}/beoordelen`, {
			headers: writeHeaders(),
			data: { beoordelingsoordeel: 'voldoende' },
		})
		const assessedBody: any = await assessed.json().catch(() => ({}))
		expect(assessed.status(), `assess -> ${JSON.stringify(assessedBody)}`).toBe(200)
		expect(assessedBody.status).toBe('approved')
		expect(assessedBody.beoordelingsoordeel).toBe('voldoende')

		// Read back from the store: the assessment reached it, and the report
		// hangs off this case through its execution and application.
		const report = await showObject(api, 'interimReport', reportId)
		expect(report.beoordelingsoordeel, 'the assessment is stored').toBe('voldoende')
		expect(String(report.assessor ?? ''), 'the assessor comes from the session').not.toBe('')
		expect(report.subsidieuitvoering).toBe(executionId)
		const execution = await showObject(api, 'subsidieUitvoering', executionId)
		expect(execution.subsidieaanvraag).toBe(applicationId)
		const application = await showObject(api, 'subsidieAanvraag', applicationId)
		expect(application.case, 'the report is linked to the case').toBe(caseId)
	})

	// @e2e openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#scenario-recording-and-assessing-an-interim-report
	test('the handler records and assesses the report from the case page and the history shows both', async ({ page }) => {
		test.fixme(true, 'tasks 1.1b and 1.2: the interim-report-record and interim-report-assess header actions and the interim report widget are not built')

		const { caseId } = await grantedSubsidy('interim-page')
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await clickHeaderAction(page, 'cn-action-interim-report-record')
		const record = page.getByRole('dialog')
		await record.getByRole('button', { name: /Record|Save|Opslaan/ }).click()
		await expect(record).toBeHidden()

		await clickHeaderAction(page, 'cn-action-interim-report-assess')
		const assess = page.getByRole('dialog')
		await assess.getByText('voldoende', { exact: false }).first().click()
		await assess.getByRole('button', { name: /Assess|Save|Beoordelen/ }).click()
		await expect(assess).toBeHidden()

		await expect(page.getByText(/voldoende/i).first()).toBeVisible()
	})

	// @e2e openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#scenario-settling-lower-than-granted-starts-a-recovery
	test('settling 35.000 of a 50.000 grant with 40.000 advanced proposes a 5.000 recovery', async () => {
		const { caseId, executionId } = await grantedSubsidy('settle')
		const determinationId = objectId(
			await createObject(api, token, 'subsidieVaststelling', {
				subsidieuitvoering: executionId,
				actualCostTotal: SETTLED,
				status: 'draft',
			}),
		)

		const res = await api.post(`${SUBSIDIES}/vaststellingen/${determinationId}/vast`, {
			headers: writeHeaders(),
			data: { grantedAmount: GRANTED, werkelijkeKosten: SETTLED, totaalVoorschotten: ADVANCES },
		})
		const body: any = await res.json().catch(() => ({}))
		expect(res.status(), `settle -> ${JSON.stringify(body)}`).toBe(200)

		const recovery = body.terugvordering
		expect(recovery, 'a recovery is proposed').toBeTruthy()
		trackCreatedObject('terugvordering', objectId(recovery))
		expect(Number(recovery.amount), 'for the advances above the settled amount').toBe(ADVANCES - SETTLED)
		expect(recovery.status, 'proposed, awaiting the manager, not imposed').toBe('draft')
		expect(recovery.subsidieuitvoering, 'on this grant').toBe(executionId)

		expect(Number(body.determination?.determinedAmount)).toBe(SETTLED)
		expect(body.determination?.recoveryTrigger).toBe(true)

		// The case page's Settlement card reads this endpoint.
		const overview = await api.get(`/index.php/apps/${REGISTER}/api/cases/${caseId}/settlements`)
		expect(overview.status(), await overview.text()).toBe(200)
		const settlements = ((await overview.json()).settlements ?? []) as any[]
		const row = settlements.find((s) => s.id === determinationId)
		expect(row, `the settlement shows on the case: ${JSON.stringify(settlements)}`).toBeTruthy()
		expect(row.determinedAmount).toBe(SETTLED)
		expect(row.recovery, 'the case reads that a recovery follows').toBe(true)
		expect(row.status).toBe('determined')
	})

	// @e2e openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#scenario-settling-lower-than-granted-starts-a-recovery
	test('the handler settles from the case page and the proposed recovery shows on the case', async ({ page }) => {
		test.fixme(true, 'tasks 1.1b and 1.2: the grant-settle and grant-recover header actions and the recovery widget are not built')

		const { caseId } = await grantedSubsidy('settle-page')
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await clickHeaderAction(page, 'cn-action-grant-settle')
		const settle = page.getByRole('dialog')
		await settle.getByRole('button', { name: /Settle|Vaststellen/ }).click()
		await expect(settle).toBeHidden()

		await expect(page.getByText(/5[.,]000/).first()).toBeVisible()
	})
})
