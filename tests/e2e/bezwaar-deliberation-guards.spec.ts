/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The deliberation guards of a committee advice request, on a seeded instance.
 *
 * A contested decision is authored by the admin. An advice request whose panel
 * holds the admin is conflicted (Awb 7:13 lid 3); one whose panel holds only
 * the ordinary e2e user is not. The guards run inside OpenRegister's save
 * events, so only a live instance shows that a refused save stores nothing.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { provisioningContext } from './helpers/auth.ts'
import { openCaseSidebar } from './helpers/case-panels.ts'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'
const TRANSITION = (id: string) => `/index.php/apps/dossiq/api/bezwaar/advice-requests/${id}/transition`

let session: APIRequestContext
let admin: APIRequestContext
let token = ''
let proceeding = ''
let committee = ''
let caseId = ''
const SEEDED = ['bacAdviceRequest', 'objection', 'decision', 'objectionProceeding', 'bezwaaradviescommissie', 'case']

/**
 * Create one object as the signed-in admin; teardown removes it by its ledger.
 *
 * @param schema The dossiq schema slug.
 * @param data   The object body.
 * @return The created object's id.
 */
async function create(schema: string, data: Record<string, unknown>): Promise<string> {
	return objectId(await createObject(session, token, schema, data))
}

/**
 * The advice requests stored for the seeded objection proceeding.
 *
 * @return The stored requests.
 */
async function storedRequests(): Promise<any[]> {
	const res = await admin.get(`${OBJECTS}/bacAdviceRequest?bezwaar=${proceeding}&_limit=50`)
	expect(res.ok(), `listing advice requests: ${await res.text()}`).toBeTruthy()
	const body: any = await res.json()
	return body?.results ?? body?.objects ?? []
}

test.describe('Deliberation on a committee advice request is guarded', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		session = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(session)
		admin = await provisioningContext(playwright, String(baseURL))

		const caseType = await ensureCaseType(session, token)
		const seeded = await seedCase(session, token, { title: `${RUN_PREFIX} bezwaar deliberation`, caseType: caseType.id })
		caseId = objectId(seeded)

		const decision = await create('decision', { title: `${RUN_PREFIX} contested decision`, case: caseId, decidedBy: 'admin' })
		await create('objection', {
			case: caseId,
			contestedDecision: decision,
			grounds: 'The decision is wrong.',
			receivedDate: new Date().toISOString().slice(0, 10),
			receivedChannel: 'brief',
		})
		proceeding = await create('objectionProceeding', {
			case: caseId,
			receiptDate: new Date().toISOString().slice(0, 10),
			status: 'Received',
		})
		committee = await create('bezwaaradviescommissie', {
			name: `${RUN_PREFIX} commissie`,
			type: 'BAC',
			chair: 'admin',
			members: [],
			active: true,
		})
	})

	test.afterAll(async () => {
		// An objection puts a legal hold on its case, and a held case cannot be purged.
		await admin.delete(`/index.php/apps/openregister/api/retention/legal-holds/${caseId}`, {
			data: { reason: 'e2e teardown' },
		})
		await cleanupRunObjects(session, token, SEEDED)
		await admin.dispose()
		await session.dispose()
	})

	test('a create straight into in-deliberation with a conflicted panel is refused and nothing is saved', async () => {
		const before = (await storedRequests()).length

		const res = await admin.post(`${OBJECTS}/bacAdviceRequest`, {
			data: { bezwaar: proceeding, committee, panel: ['admin'], status: 'in-deliberation' },
		})
		const body = await res.text()

		expect(res.status(), `the create was stored: ${body}`).toBeGreaterThanOrEqual(400)
		expect(res.status()).toBeLessThan(500)
		expect(body).toContain('Panel member conflict')
		expect((await storedRequests()).length, 'a refused create left a row behind').toBe(before)
	})

	test('Start deliberation on the page refuses a conflicted panel and History shows the refusal', async ({ page }) => {
		const request = await create('bacAdviceRequest', { bezwaar: proceeding, committee, panel: ['admin'], status: 'assigned' })

		await page.goto(`/apps/dossiq/bezwaar-advice-requests/${request}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const refusal = page.waitForResponse((r) => r.url().includes(`/advice-requests/${request}/transition`))
		await clickHeaderAction(page, 'cn-action-start-deliberation')
		expect((await refusal).status(), 'the conflicted panel started deliberating').toBeGreaterThanOrEqual(400)

		const stored = await admin.get(`${OBJECTS}/bacAdviceRequest/${request}`)
		expect((await stored.json()).status).toBe('assigned')

		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)
		const sidebar = await openCaseSidebar(page)
		await sidebar.getByRole('tab', { name: 'History', exact: true }).click()
		await expect(sidebar.getByText(/independence-check-failed/).first()).toBeVisible({ timeout: 20_000 })
	})

	test('a plain write of advice-issued is refused; only the transition reaches an outcome', async () => {
		const request = await create('bacAdviceRequest', { bezwaar: proceeding, committee, panel: ['e2euser'], status: 'assigned' })

		const started = await admin.post(TRANSITION(request), { data: { status: 'in-deliberation' } })
		expect(started.ok(), `starting deliberation: ${await started.text()}`).toBeTruthy()

		const written = await admin.patch(`${OBJECTS}/bacAdviceRequest/${request}`, {
			data: { status: 'advice-issued', conclusion: 'dismissed', recommendation: 'Uphold the decision' },
		})
		const body = await written.text()
		expect(written.status(), `a plain write issued the advice: ${body}`).toBeGreaterThanOrEqual(400)
		expect(written.status()).toBeLessThan(500)
		expect(body).toContain('Only the advice transition')

		const stored = await admin.get(`${OBJECTS}/bacAdviceRequest/${request}`)
		expect((await stored.json()).status).toBe('in-deliberation')
	})

	test('Declare inadmissible on the page moves an in-deliberation request through the transition', async ({ page }) => {
		const request = await create('bacAdviceRequest', { bezwaar: proceeding, committee, panel: ['e2euser'], status: 'assigned' })
		const started = await admin.post(TRANSITION(request), { data: { status: 'in-deliberation' } })
		expect(started.ok(), `starting deliberation: ${await started.text()}`).toBeTruthy()

		await page.goto(`/apps/dossiq/bezwaar-advice-requests/${request}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const moved = page.waitForResponse((r) => r.url().includes(`/advice-requests/${request}/transition`))
		await clickHeaderAction(page, 'cn-action-declare-inadmissible')
		await page.getByRole('dialog').getByRole('button', { name: /confirm|declare|yes|ok/i }).first().click()
		expect((await moved).ok(), 'the page could not reach an outcome through the transition').toBeTruthy()

		const stored = await admin.get(`${OBJECTS}/bacAdviceRequest/${request}`)
		expect((await stored.json()).status).toBe('inadmissible')
	})
	test('Issue advice on the page reaches advice-issued through the transition', async ({ page }) => {
		const request = await create('bacAdviceRequest', { bezwaar: proceeding, committee, panel: ['e2euser'], status: 'assigned' })
		const started = await admin.post(TRANSITION(request), { data: { status: 'in-deliberation' } })
		expect(started.ok(), `starting deliberation: ${await started.text()}`).toBeTruthy()
		const advice = await admin.patch(`${OBJECTS}/bacAdviceRequest/${request}`, {
			data: { conclusion: 'dismissed', recommendation: 'Uphold the decision' },
		})
		expect(advice.ok(), `recording the advice fields: ${await advice.text()}`).toBeTruthy()

		await page.goto(`/apps/dossiq/bezwaar-advice-requests/${request}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const moved = page.waitForResponse((r) => r.url().includes(`/advice-requests/${request}/transition`))
		await clickHeaderAction(page, 'cn-action-issue-advice')
		await page.getByRole('dialog').getByRole('button', { name: /confirm|issue|yes|ok/i }).first().click()
		const answer = await moved
		expect(answer.ok(), `the page could not issue the advice: ${await answer.text()}`).toBeTruthy()

		const stored = await admin.get(`${OBJECTS}/bacAdviceRequest/${request}`)
		expect((await stored.json()).status).toBe('advice-issued')
	})
})
