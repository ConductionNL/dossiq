/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A figure about every case is not for everyone.
 *
 * 🔴 WHAT THIS IS ABOUT, MEASURED RATHER THAN SUSPECTED. Deriving the
 * reporting endpoints from `appinfo/routes.php` rather than from anyone's list
 * found five controllers behind reporting-shaped URLs with no group check at
 * all. The worst of them is `GET /api/termijn/reports/jaarrekening`, the ANNUAL
 * DWANGSOM STATEMENT: what the organisation paid out for missing its own
 * deadlines, answered to every authenticated account on the instance.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED, AND
 * THE CONTROL IS A DIFFERENT ACCOUNT. Reading these as an administrator proves
 * nothing: `ReportingAudience` lets an administrator through on purpose, so an
 * admin read is green whether the gate landed or not. Two accounts, two
 * memberships, the same four URLs.
 *
 * WHY THE CONTROL COMES FIRST. A 403 on its own is also what an endpoint that
 * is simply broken answers, and what a route that no longer exists answers. The
 * controller read proves the endpoint works before the handler read proves it
 * refuses.
 *
 * NOT RUN IN THIS LANE. No Playwright runs on the build host. The suite is
 * written and tagged so the nightly owns it.
 */

import type { APIRequestContext, Browser } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	STORAGE_STATE,
	storageStatePath,
} from './helpers/auth.ts'
import { getRequestToken } from './helpers/fixtures.ts'

/**
 * The endpoints the sweep found and this change gated.
 *
 * Written out rather than derived here on purpose: the derivation is
 * `ReportingEndpointIsGuardedTest`, which reads the route table, and a second
 * derivation in a second language is a second answer that can disagree. What
 * this file adds is that the gate is real on a running instance.
 */
const GATED = [
	'/index.php/apps/dossiq/api/termijn/reports/jaarrekening?jaar=2026',
	'/index.php/apps/dossiq/api/termijn/reports/kwartaal?periode=2026-Q1',
	'/index.php/apps/dossiq/api/termijn/dashboard/kpi',
	'/index.php/apps/dossiq/api/doorlooptijd/metrics',
]

/** The group that may read a figure about every case. */
const READING_GROUP = 'controllers'

/** The group the shipped flow assigns work to, which may not. */
const HANDLING_GROUP = 'behandelaars'

/** The account that should read them. */
const CONTROLLER = 'e2e-reporting-controller'

/** The account that should not. */
const HANDLER = 'e2e-reporting-handler'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Reporting-2026!'

let controllerApi: APIRequestContext | null = null
let handlerApi: APIRequestContext | null = null

/**
 * Create a group and put one account in it, over the OCS provisioning API.
 *
 * @param api   A request context authenticated as an admin.
 * @param group The group id.
 * @param uid   The account to add.
 */
async function ensureMembership(
	api: APIRequestContext,
	group: string,
	uid: string,
): Promise<void> {
	await api.post('/ocs/v2.php/cloud/groups?format=json', {
		headers: { 'OCS-APIRequest': 'true' },
		form: { groupid: group },
	})

	const added = await api.post(
		`/ocs/v2.php/cloud/users/${uid}/groups?format=json`,
		{
			headers: { 'OCS-APIRequest': 'true' },
			form: { groupid: group },
		},
	)
	const status = Number(
		(await added.json().catch(() => ({})))?.ocs?.meta?.statuscode ?? -1,
	)
	expect(
		[100, 200].includes(status),
		`"${uid}" must be a member of "${group}", or the probe below proves nothing; OCS answered ${status}`,
	).toBeTruthy()
}

/**
 * A request context signed in as one account.
 *
 * @param browser    A launched browser.
 * @param baseURL    The instance.
 * @param uid        The account.
 * @param playwright The Playwright fixture.
 */
async function signIn(
	browser: Browser,
	baseURL: string,
	uid: string,
	playwright: any,
): Promise<APIRequestContext> {
	const statePath = storageStatePath(uid)
	await captureStorageState(browser, {
		baseURL,
		user: uid,
		password: PASSWORD,
		statePath,
	})

	return playwright.request.newContext({ baseURL, storageState: statePath })
}

test.describe('a figure about every case is not for everyone', () => {
	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		test.setTimeout(240_000)

		const adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		await getRequestToken(adminApi)

		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', CONTROLLER, PASSWORD)
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await ensureMembership(provisioning, READING_GROUP, CONTROLLER)
		await ensureMembership(provisioning, HANDLING_GROUP, HANDLER)
		await provisioning.dispose()
		await adminApi.dispose()

		controllerApi = await signIn(
			browser,
			String(baseURL),
			CONTROLLER,
			playwright,
		)
		handlerApi = await signIn(browser, String(baseURL), HANDLER, playwright)
	})

	test.afterAll(async () => {
		await controllerApi?.dispose()
		await handlerApi?.dispose()
	})

	// BREAKS IF: the gate is removed from a controller, or `ReportingAudience`
	// starts letting `behandelaars` through. Both look identical from the
	// dashboard, which renders the same either way for a controller.
	test('a controller reads every reporting endpoint', async () => {
		const api = controllerApi as APIRequestContext

		for (const url of GATED) {
			const response = await api.get(url)
			expect(
				response.status(),
				`${url} must answer a member of ${READING_GROUP}; got ${response.status()}. `
					+ 'Without this the refusals below could be a broken endpoint rather than a gate.',
			).toBeLessThan(400)
		}
	})

	test('a case handler is refused every one of them', async () => {
		const api = handlerApi as APIRequestContext

		for (const url of GATED) {
			const response = await api.get(url)
			expect(response.status(), `${url} must refuse a case handler`).toBe(403)

			// And the refusal says why, so an operator adds the account to the
			// group rather than filing a bug about a broken dashboard.
			expect(await response.text()).toContain('controller')
		}
	})

	test('the annual dwangsom statement carries no figures for a case handler', async () => {
		// The one that matters most, asserted on the BODY as well as the status:
		// a 403 whose body still carried the payload would be the same leak with
		// a different number on it.
		const response = await (handlerApi as APIRequestContext).get(GATED[0])

		expect(response.status()).toBe(403)
		const body = await response.text()
		expect(body).not.toContain('dwangsom')
		expect(body).not.toContain('totaal')
	})
})
