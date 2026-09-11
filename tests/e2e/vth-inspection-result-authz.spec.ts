/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who may write an inspection result onto a case (#799).
 *
 * WHY THIS CANNOT RUN AS THE ADMIN, AND WHY THAT IS THE WHOLE POINT.
 * `CaseAccessGuard` admits an admin on every case, so an admin session
 * succeeds against a case it has no relationship to and proves nothing about
 * the guard. Worse, it succeeds identically whether the guard works or not, so
 * an admin-only spec would have reported green over the hole for the three
 * months #799 sat open. Every request below is therefore made by one of two
 * ordinary accounts this file provisions itself, and each assertion names the
 * account it was made by so a failure says WHO was let in rather than only
 * that a status differed.
 *
 * THE TWO ACCOUNTS.
 *   - INSPECTOR is written into the seeded case's `assignee`, so stored state
 *     says this person handles the case.
 *   - OUTSIDER is a plain account in no relevant group, named nowhere on the
 *     case. It is the attacker in the issue.
 *
 * THE THREE REFUSALS. The old guard read `assignedInspector` out of the
 * REQUEST BODY, so it failed open two independent ways and OUTSIDER had two
 * winning moves. Both are exercised here as separate scenarios, plus the
 * honest control where OUTSIDER names the real inspector:
 *
 *   1. omit `assignedInspector` entirely  -> `$assignedUid === ''`, the
 *      `!== ''` conjunct is false, the refusal branch never runs;
 *   2. send OUTSIDER's own uid            -> the comparison passes trivially;
 *   3. send INSPECTOR's uid               -> the only case the old guard
 *      actually refused, and the only one an author testing by hand would try.
 *
 * A spec that asserted only 3 would have passed before the fix.
 *
 * WHAT "ACCEPTED" IS ASSERTED AS. INSPECTOR's submission is asserted NOT to be
 * a 403, rather than asserted to be a 201. The authorization boundary is this
 * file's subject and 403-or-not is exactly that boundary; the submission may
 * still be refused downstream on payload grounds (an unknown checklist id, a
 * required-photo rule), and pinning a 201 here would couple an authz spec to
 * `inspectionResult` validation and make it fail for reasons that are not
 * about who is calling. The status is printed in the message either way.
 *
 * The `@e2e` anchors live immediately above the `test(` declarations they
 * annotate, never up here. Gate-19 reads an anchor as a DIRECTIVE only where
 * it is attached to a declaration; one floating in a file header is prose, and
 * prose is reported rather than counted.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import {
	captureStorageState,
	ensureUser,
	STORAGE_STATE,
	storageStatePath,
} from './helpers/auth.ts'
import {
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** The account the seeded case names as its handler. */
const INSPECTOR = process.env.DOSSIQ_E2E_INSPECTOR ?? 'e2e-inspecteur'
/** Its password. Must satisfy the instance's password policy. */
const INSPECTOR_PASSWORD =
	process.env.DOSSIQ_E2E_INSPECTOR_PASSWORD ?? 'e2eInsp!2026'

/** An authenticated account with no relationship to the case at all. */
const OUTSIDER = process.env.DOSSIQ_E2E_OUTSIDER ?? 'e2e-buitenstaander'
/** Its password. */
const OUTSIDER_PASSWORD = process.env.DOSSIQ_E2E_OUTSIDER_PASSWORD ?? 'e2eOuts!2026'

/**
 * The endpoint under test.
 *
 * @param id The case uuid.
 * @return The route to POST an inspection result to.
 */
function submitUrl(id: string): string {
	return `/index.php/apps/dossiq/api/vth/cases/${id}/inspection-result`
}

/**
 * Its sibling, fixed in the same change.
 *
 * @param id The case uuid.
 * @return The route to POST an advice request to.
 */
function adviceUrl(id: string): string {
	return `/index.php/apps/dossiq/api/vth/cases/${id}/advice-requests`
}

/** One signed-in account's API context plus the CSRF token that goes with it. */
interface Session {
	api: APIRequestContext
	token: string
	uid: string
}

let caseId = ''
let inspector: Session
let outsider: Session

/**
 * Sign an account in, capture its session, and prove the capture really is
 * that account before anything is asserted through it.
 *
 * The proof is not ceremony. `captureStorageState` merges the project's `use`
 * when `storageState` is omitted, and a capture that silently fell back to the
 * admin would make every refusal below fail as an acceptance while naming a
 * status code rather than a session. `dashboard-tiles.spec.ts` lost a whole
 * file to exactly that, on a run where the seeding context resolved to the
 * wrong account.
 *
 * @param browser  The test-scoped browser.
 * @param baseURL  The instance under test.
 * @param uid      The account to sign in.
 * @param password Its password.
 * @return The account's API context, CSRF token and uid.
 */
async function signIn(
	browser: any,
	baseURL: string,
	uid: string,
	password: string,
): Promise<Session> {
	const statePath = storageStatePath(uid)
	await captureStorageState(browser, { baseURL, user: uid, password, statePath })

	const api = await playwrightRequest.newContext({
		baseURL,
		storageState: statePath,
	})
	const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(whoami.ok(), `whoami as "${uid}" -> ${whoami.status()}`).toBeTruthy()
	expect(
		String((await whoami.json())?.ocs?.data?.id ?? ''),
		`the captured session must BE "${uid}" — a session that silently fell `
			+ 'back to the admin would pass every refusal below for the wrong reason',
	).toBe(uid)

	return { api, token: await getRequestToken(api), uid }
}

/**
 * POST a body as one account and return the HTTP status.
 *
 * @param session The account making the call.
 * @param url     The endpoint.
 * @param body    The JSON payload.
 * @return The response status.
 */
async function post(
	session: Session,
	url: string,
	body: Record<string, unknown>,
): Promise<number> {
	const res = await session.api.post(url, {
		headers: {
			requesttoken: session.token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: body,
	})
	return res.status()
}

test.describe('VTH inspection result: only the stored handler may submit', () => {
	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// Provisioning two accounts, capturing two sessions and seeding a case
		// type plus a case is more than the default hook budget on a loaded
		// rig, and a bare `"beforeAll" hook timeout` names none of it.
		test.setTimeout(240_000)

		// 🔴 THE ADMIN SESSION IS NAMED, NOT INHERITED — see the same note in
		// `dashboard-tiles.spec.ts`. `ensureUser` needs an admin, and a context
		// built without an explicit storageState has resolved to a non-admin
		// account on a real run, producing `OCS 403 Logged in account must be
		// at least a sub admin`, which reads as a broken provisioning API.
		const adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		const adminToken = await getRequestToken(adminApi)

		const adminWhoami = await adminApi.get(
			'/ocs/v2.php/cloud/user?format=json',
			{
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		expect(
			adminWhoami.ok(),
			`the seeding session must answer whoami, got ${adminWhoami.status()}`,
		).toBeTruthy()
		expect(
			String((await adminWhoami.json())?.ocs?.data?.id ?? ''),
			'the seeding session must be the admin, or nothing below can provision',
		).toBe(process.env.ADMIN_USER ?? 'admin')

		await ensureUser(adminApi, adminToken, INSPECTOR, INSPECTOR_PASSWORD)
		await ensureUser(adminApi, adminToken, OUTSIDER, OUTSIDER_PASSWORD)

		// The case is seeded by the ADMIN and handed to INSPECTOR through the
		// stored `assignee`. That is the only thing that makes INSPECTOR the
		// inspector: no group, no grant, nothing in any request body.
		const caseType = await ensureCaseType(adminApi, adminToken)
		const seeded = await seedCase(adminApi, adminToken, {
			title: `${RUN_PREFIX} Toezichtzaak 799`,
			caseType: caseType.id,
			assignee: INSPECTOR,
		})
		caseId = objectId(seeded)
		expect(caseId, 'the fixture case must have an id').not.toBe('')

		inspector = await signIn(
			browser,
			String(baseURL),
			INSPECTOR,
			INSPECTOR_PASSWORD,
		)
		outsider = await signIn(
			browser,
			String(baseURL),
			OUTSIDER,
			OUTSIDER_PASSWORD,
		)

		await adminApi.dispose()
	})

	test.afterAll(async () => {
		await inspector?.api.dispose()
		await outsider?.api.dispose()
	})

	// @e2e openspec/specs/inspection-checklists/spec.md#the-cases-assignee-submits-a-result
	// @e2e openspec/specs/inspection-checklists/spec.md#a-submitted-result-is-readable-back
	test('the stored assignee may submit', async () => {
		const status = await post(inspector, submitUrl(caseId), {
			checklistId: 'e2e-checklist',
			answers: [],
		})

		expect(
			status,
			`"${INSPECTOR}" is the stored assignee of case ${caseId} and must not `
				+ `be refused; the endpoint answered ${status}`,
		).not.toBe(403)
	})

	// @e2e openspec/specs/inspection-checklists/spec.md#another-authenticated-account-is-refused
	test('a different authenticated account is refused', async () => {
		const status = await post(outsider, submitUrl(caseId), {
			checklistId: 'e2e-checklist',
			answers: [],
			assignedInspector: INSPECTOR,
		})

		expect(
			status,
			`"${OUTSIDER}" is not the assignee of case ${caseId} and is in no `
				+ `relevant group, so the endpoint must answer 403; it answered ${status}`,
		).toBe(403)
	})

	// @e2e openspec/specs/inspection-checklists/spec.md#omitting-the-inspector-field-does-not-skip-the-check
	test('omitting the inspector field does not skip the check', async () => {
		// BYPASS 1 from #799, verbatim: no inspector field of any kind. Under
		// the old guard `$assignedUid` was `''`, the `!== ''` conjunct was
		// false, and the refusal branch never ran.
		const status = await post(outsider, submitUrl(caseId), {
			checklistId: 'e2e-checklist',
			answers: [],
		})

		expect(
			status,
			`"${OUTSIDER}" sent no inspector field at all. Sending LESS must not `
				+ `buy more: the endpoint must still answer 403, it answered ${status}`,
		).toBe(403)
	})

	test('naming yourself as the inspector does not grant access', async () => {
		// BYPASS 2 from #799: the caller supplies its own uid, so the old
		// guard's comparison passed trivially.
		const status = await post(outsider, submitUrl(caseId), {
			checklistId: 'e2e-checklist',
			answers: [],
			assignedInspector: OUTSIDER,
		})

		expect(
			status,
			`"${OUTSIDER}" named ITSELF as the assigned inspector of case `
				+ `${caseId}. Identity supplied by the caller is not identity: the `
				+ `endpoint must answer 403, it answered ${status}`,
		).toBe(403)
	})

	// @e2e openspec/specs/inspection-checklists/spec.md#creating-an-advice-request-is-authorized-the-same-way
	test('the sibling advice endpoint refuses the same account', async () => {
		const status = await post(outsider, adviceUrl(caseId), {
			advisor: OUTSIDER,
			question: 'Mag dit?',
		})

		expect(
			status,
			`"${OUTSIDER}" does not handle case ${caseId}, so creating an advice `
				+ `request on it — which writes and mails the named adviseur — must `
				+ `answer 403; it answered ${status}`,
		).toBe(403)
	})
})
