/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tenant-quotas-enforced-and-billing-shown task 3.1: the refusal and the
 * billing run (REQ-TQ-ENF-01, REQ-TQ-ENF-02, REQ-TQ-BILL-01).
 *
 * THE TENANT IS OPENREGISTER'S ACTIVE ORGANISATION. `QuotaGate` counts a write
 * only when `TenantContext` is bound: the logged-in user's active
 * OpenRegister organisation, and a `tenantUser` row naming that user in it.
 * So the handler joins a fresh organisation, sets it active through
 * OpenRegister's own `set-active`, and gets a `tenantUser` row; the quota is a
 * `tenantQuota` row `cases_per_month`, enforced as `block`. A case create past
 * the limit is refused by `QuotaConsumptionListener` before the save, which
 * OpenRegister answers 422, with "Your organisation reached its limit of
 * {500 cases per month} on the {tier} plan." (nl "500 zaken per maand").
 *
 * The handler is in `dossiq-medewerkers`, the group the case schema lets
 * create, so the first create proves the account may create at all and the
 * second can only be refused by the quota.
 *
 * THE BILLING RUN. `POST /api/saas/tenants/{tenantId}/billing/previous/run`
 * (`tenantBilling#run`, the page's More menu entry "Invoice last month",
 * `tenant-billing-run`) hands last month's unbilled `tenantBillingEvent` rows
 * to Shillinq as one invoice and stamps them; a second run finds nothing
 * unbilled and answers 409. Without Shillinq no invoice can be handed over,
 * so that test skips where Shillinq is absent.
 *
 * SEEDED AND REMOVED. The cases go through the run's ledger; the tenant rows
 * are deleted by id; the handler leaves the organisation. OpenRegister has no
 * route that deletes an organisation, so the organisation itself (named with
 * the run marker) stays, as in tenant-follows-the-active-organisation.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	storageStatePath,
} from './helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, openHeaderActionsMenu, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** OpenRegister's API. */
const OR = '/index.php/apps/openregister/api'

/** The dossiq register's objects. */
const OBJECTS = `${OR}/objects/${REGISTER}`

/** The handler whose cases count against the tenant's quota. */
const HANDLER = 'e2e-quota-handler'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Quota-Handler-2026!'

/** The group the case schema lets create cases. */
const CREATORS = 'dossiq-medewerkers'

/** A run marker, so an organisation from an earlier run is not reused. */
const RUN = `e2e-quota-${Date.now().toString(36)}`

/** The refusal's limit, in either locale. */
const FIVE_HUNDRED = /500 (cases per month|zaken per maand)/

/** The admin, by basic auth and without a session. */
let admin: APIRequestContext
/** The admin's session, for the writes that carry a request token. */
let adminSession: APIRequestContext
let adminToken = ''
/** The handler's session. */
let handler: APIRequestContext
let organisation = ''
let quotaRow = ''
let caseType = ''
let shillinq = false

/** Tenant rows this run created, deleted by id in teardown. */
const tenantRows: Array<{ schema: string; id: string }> = []

/**
 * Create a tenant row in the dossiq register as the admin.
 *
 * @param schema The schema slug.
 * @param data   The row.
 *
 * @return Its id.
 */
async function createRow(schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await admin.post(`${OBJECTS}/${schema}`, { data })
	expect(res.status(), `creating a ${schema} row: ${await res.text()}`).toBeLessThan(300)
	const id = objectId(await res.json())
	tenantRows.push({ schema, id })

	return id
}

/**
 * Set the tenant's monthly case quota.
 *
 * @param limit        The hard limit.
 * @param currentUsage The cases already counted this month.
 */
async function setCaseQuota(limit: number, currentUsage: number): Promise<void> {
	const res = await admin.patch(`${OBJECTS}/tenantQuota/${quotaRow}`, { data: { limit, currentUsage } })
	expect(res.ok(), `setting the case quota: ${res.status()} ${await res.text()}`).toBeTruthy()
}

/**
 * Create a case as the handler.
 *
 * @param title The case title.
 *
 * @return The status and the body as text.
 */
async function createCaseAsHandler(title: string): Promise<{ status: number; body: string }> {
	const token = await getRequestToken(handler)
	const res = await handler.post(`${OBJECTS}/case`, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		data: {
			title,
			caseType,
			identifier: `${RUN_PREFIX}-${title.length}-${Date.now().toString(36)}`,
			impact: 'medium',
			urgency: 'medium',
			intakeChannel: 'manual',
		},
	})
	const body = await res.text()
	if (res.ok()) {
		trackCreatedObject('case', objectId(JSON.parse(body)))
	}

	return { status: res.status(), body }
}

/**
 * The first day of the next month, as an ISO moment: the quota's next reset.
 *
 * @return The moment.
 */
function nextReset(): string {
	const now = new Date()
	return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 1)).toISOString()
}

/**
 * A moment in the middle of last month.
 *
 * @return The moment.
 */
function lastMonth(): string {
	const now = new Date()
	return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 15, 10)).toISOString()
}

test.describe('A tenant quota is enforced and its billing is run', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', HANDLER, PASSWORD)
		await admin.post('/ocs/v2.php/cloud/groups?format=json', { form: { groupid: CREATORS } })
		await admin.post(`/ocs/v2.php/cloud/users/${HANDLER}/groups?format=json`, { form: { groupid: CREATORS } })

		const apps = await admin.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
		shillinq = (((await apps.json())?.ocs?.data?.apps ?? []) as string[]).includes('shillinq')

		const created = await admin.post(`${OR}/organisations`, { data: { name: `${RUN} Waterland` } })
		expect(created.status(), `creating the organisation: ${await created.text()}`).toBeLessThan(300)
		const body: any = await created.json()
		organisation = String(body?.organisation?.uuid ?? body?.uuid ?? '')
		expect(organisation, 'the organisation comes back with a uuid').not.toBe('')

		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: HANDLER,
			password: PASSWORD,
			statePath: storageStatePath(HANDLER),
		})
		handler = await playwright.request.newContext({
			baseURL: String(baseURL),
			storageState: storageStatePath(HANDLER),
		})
		const joinToken = await getRequestToken(handler)
		const joined = await handler.post(`${OR}/organisations/${organisation}/join`, { headers: { requesttoken: joinToken } })
		expect(joined.ok(), `the handler joins the organisation: ${await joined.text()}`).toBeTruthy()
		const active = await handler.post(`${OR}/organisations/${organisation}/set-active`, { headers: { requesttoken: joinToken } })
		expect(active.ok(), `the handler sets it active: ${await active.text()}`).toBeTruthy()

		await createRow('tenantUser', { tenantRef: organisation, userRef: HANDLER, role: 'case_handler' })
		quotaRow = await createRow('tenantQuota', {
			tenantRef: organisation,
			quotaType: 'cases_per_month',
			limit: 100,
			currentUsage: 40,
			enforcement: 'block',
			softLimitWarningPercent: 80,
			resetAt: nextReset(),
		})

		adminSession = await playwright.request.newContext({ baseURL })
		adminToken = await getRequestToken(adminSession)
		caseType = (await ensureCaseType(adminSession, adminToken)).id
	})

	test.afterAll(async () => {
		await cleanupRunObjects(adminSession, adminToken, ['case', 'caseType'])
		for (const row of tenantRows.reverse()) {
			if (row.id !== '') {
				await admin.delete(`${OBJECTS}/${row.schema}/${row.id}`).catch(() => undefined)
			}
		}
		if (organisation !== '') {
			const token = await getRequestToken(handler).catch(() => '')
			await handler.post(`${OR}/organisations/${organisation}/leave`, { headers: { requesttoken: token } }).catch(() => undefined)
		}
		await Promise.all([handler?.dispose(), adminSession?.dispose()])
		await admin?.dispose()
	})

	// @e2e openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#scenario-the-quota-card-shows-this-months-cases-on-its-own-row
	test('the quota card shows this month\'s cases on their own row', async ({ page }) => {
		trackDossiqErrors(page)
		await page.goto(`/apps/${REGISTER}/settings/organisations/${organisation}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const row = page.getByTestId('cn-fact-cases-this-month')
		await expect(row, 'the quota card has a Cases this month row').toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText(/(Cases this month|Zaken deze maand)/)
		const meter = page.getByTestId('cn-fact-cases-this-month-bar')
		await expect(meter).toHaveAttribute('aria-valuenow', '40')
		await expect(meter).toHaveAttribute('aria-valuemax', '100')
	})

	// @e2e openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#scenario-the-501st-case-is-refused
	test('the 501st case of the month is refused, naming the limit', async () => {
		await setCaseQuota(500, 499)

		const fiveHundredth = await createCaseAsHandler(`${RUN_PREFIX} quota 500`)
		expect(fiveHundredth.status, `the 500th case is still within the limit: ${fiveHundredth.body}`).toBeLessThan(300)

		const refused = await createCaseAsHandler(`${RUN_PREFIX} quota 501`)
		expect(refused.status, `the 501st case is refused before the save: ${refused.body}`).toBe(422)
		expect(refused.body, 'the refusal names the limit').toMatch(FIVE_HUNDRED)

		const quota = await admin.get(`${OBJECTS}/tenantQuota/${quotaRow}`)
		expect(Number((await quota.json())?.currentUsage), 'the refused case is not counted').toBe(500)
	})

	// @e2e openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#scenario-running-billing-twice-makes-one-invoice
	test('the tenant page offers Invoice last month under More', async ({ page }) => {
		trackDossiqErrors(page)
		await page.goto(`/apps/${REGISTER}/settings/organisations/${organisation}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-tenant-billing-run')).toContainText(/(Invoice last month|Vorige maand factureren)/)
	})

	// @e2e openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#scenario-running-billing-twice-makes-one-invoice
	test('running last month\'s billing twice hands one invoice to the finance app', async () => {
		test.skip(!shillinq, 'Shillinq is not enabled on this instance; there is no finance app to hand an invoice to.')

		for (const unitPrice of [12.5, 7.5]) {
			await createRow('tenantBillingEvent', {
				tenantRef: organisation,
				eventType: 'case_created',
				quantity: 1,
				unitPrice,
				currency: 'EUR',
				occurredAt: lastMonth(),
			})
		}
		const run = `/index.php/apps/${REGISTER}/api/saas/tenants/${organisation}/billing/previous/run`

		const first = await adminSession.post(run, { headers: { requesttoken: adminToken } })
		const firstBody: any = await first.json().catch(() => ({}))
		expect(first.status(), `the first run invoices last month: ${JSON.stringify(firstBody)}`).toBe(200)
		expect(firstBody.invoice?.exported).toBe(true)
		expect(firstBody.invoice?.eventCount).toBe(2)
		const invoiceRef = String(firstBody.invoice?.invoiceRef ?? '')
		expect(invoiceRef, 'the finance app answered an invoice reference').not.toBe('')

		const second = await adminSession.post(run, { headers: { requesttoken: adminToken } })
		const secondBody: any = await second.json().catch(() => ({}))
		expect(second.status(), `the second run makes no second invoice: ${JSON.stringify(secondBody)}`).toBe(409)
		expect(secondBody.invoice?.exported).toBe(false)
		expect(String(secondBody.error ?? '')).toContain('no unbilled events')

		for (const row of tenantRows.filter((entry) => entry.schema === 'tenantBillingEvent')) {
			const event = await admin.get(`${OBJECTS}/tenantBillingEvent/${row.id}`)
			expect((await event.json())?.invoiceRef, 'each event carries the one invoice').toBe(invoiceRef)
		}
	})
})
