/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * complaints-are-a-case-type task 4.1: both scenarios of REQ-CMP-CT-01 (a
 * complaint is a case of the shipped complaint case type).
 *
 *  - A complaint starts as a case with its term: a case of `klacht-behandeling`
 *    received on Monday 1 March 2021 carries a statutory term ending 12 April
 *    (Awb 9:11 lid 1, six weeks, `P42D`), read through
 *    `GET /api/cases/{caseId}/terms`, and the Complaints view of the Cases list
 *    (`complaints` in lib/Settings/case-type-views.json, board DqKlachten)
 *    shows it. The same journey as
 *    tests/integration/klacht-behandeling.postman_collection.json, plus the list.
 *  - The hearing is a session on the case: the case type declares the session
 *    `hoorgesprek` (`sessions[]`), but the case page does not offer a declared
 *    session as an action yet (task 2.3, waiting on
 *    case-session-with-parties-and-minutes), so that test is `test.fixme`.
 *
 * The scenario's GIVEN says "the package klachtbehandeling"; the case type
 * ships in the register instead (task 1.2), so the suite reads it from there
 * and skips when an instance does not carry it.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, openHeaderActionsMenu, PAGE_LOAD, PAGE_LOAD_MS } from './helpers/nav.ts'
import { CasesSavedView } from './helpers/page-components.ts'

/** The complaint case type's identifier in the register. */
const IDENTIFIER = 'klacht-behandeling'

/** Receipt on Monday 1 March 2021; six weeks later is Monday 12 April. */
const RECEIVED_AT = '2021-03-01T09:00:00+01:00'
const TERM_START = '2021-03-01'
const TERM_END = '2021-04-12'

let api: APIRequestContext
let token = ''
let complaintType: any = null

test.describe('A complaint is a case of the shipped complaint case type', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const rows = await listObjects(api, 'caseType', { identifier: IDENTIFIER, _limit: '5' })
		complaintType = rows.find((row: any) => row.identifier === IDENTIFIER) ?? null
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['case'])
		await api?.dispose()
	})

	test.beforeEach(() => {
		test.skip(complaintType === null, `this instance carries no ${IDENTIFIER} case type; the register import that ships it has not run here`)
	})

	// @e2e openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md#scenario-a-complaint-starts-as-a-case-with-its-term
	test('a complaint received on 1 March carries a term ending 12 April and is on the complaint list', async ({ page }) => {
		expect(complaintType.processingDeadline, 'the default term is six weeks (Awb 9:11 lid 1)').toBe('P42D')
		expect(String(complaintType.statutoryTermDefinition ?? ''), 'it picks no second term (REQ-CMP-CT-03)').toBe('')

		const title = `${RUN_PREFIX} klacht ontvangen 1 maart`
		const caseId = objectId(
			await seedCase(api, token, {
				title,
				caseType: objectId(complaintType),
				receivedAt: RECEIVED_AT,
				intakeChannel: 'email',
			}),
		)

		const res = await api.get(`/index.php/apps/${REGISTER}/api/cases/${caseId}/terms`)
		expect(res.status(), await res.text()).toBe(200)
		const terms = ((await res.json()).terms ?? []) as any[]
		const statutory = terms.find((term) => term.kind === 'statutory')
		expect(statutory, `the case carries a statutory term: ${JSON.stringify(terms)}`).toBeTruthy()
		expect(String(statutory.startDate).slice(0, 10), 'counted from the receipt').toBe(TERM_START)
		expect(String(statutory.endDate).slice(0, 10), 'six weeks later').toBe(TERM_END)

		// Board DqKlachten's list is the Complaints view of the Cases list.
		await page.goto(`/index.php/apps/${REGISTER}${CasesSavedView('complaints')}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByText(title).first(), 'the complaint list shows the new complaint').toBeVisible({
			timeout: PAGE_LOAD_MS,
		})
	})

	// @e2e openspec/changes/complaints-are-a-case-type/specs/complaint-management/spec.md#scenario-the-hearing-is-a-session-on-the-case
	test('the handler plans the hearing and a session with the parties is linked to the case', async ({ page }) => {
		test.fixme(true, 'task 2.3: the case page does not offer a declared session (hoorgesprek) as an action yet; SessionService has no route')

		const hearing = ((complaintType.sessions ?? []) as any[]).find((s) => s.key === 'hoorgesprek')
		expect(hearing, 'the case type declares the hearing as a session').toBeTruthy()
		expect(hearing.inviteeRoles).toEqual(expect.arrayContaining(['Klager', 'Betrokken medewerker']))
		expect(hearing.waivable, 'waivable under Awb 9:10 lid 2').toBe(true)

		const title = `${RUN_PREFIX} klacht hoorgesprek`
		const caseId = objectId(
			await seedCase(api, token, { title, caseType: objectId(complaintType), intakeChannel: 'email' }),
		)
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// No action id exists yet; the entry is found by the label the case type declares.
		await openHeaderActionsMenu(page)
		await page.getByRole('menuitem', { name: new RegExp(hearing.label) }).first().click()
		const dialog = page.getByRole('dialog')
		await dialog.getByText('Klager').first().click()
		await dialog.getByText('Betrokken medewerker').first().click()
		await dialog.getByRole('button', { name: /Plan|Opslaan|Save/ }).click()
		await expect(dialog).toBeHidden()

		await expect(page.getByText(hearing.label).first(), 'the session appears in the case history').toBeVisible()
	})
})
