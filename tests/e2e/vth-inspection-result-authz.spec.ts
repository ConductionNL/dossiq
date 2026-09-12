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
 * WHAT "ACCEPTED" IS ASSERTED AS, AND WHY THAT CHANGED. This used to assert
 * INSPECTOR's submission was NOT a 403, and not that it was a 201, to keep an
 * authz spec off `inspectionResult` validation. That reasoning is sound about
 * the REFUSALS and was wrong about the acceptance: `the-cases-assignee-submits-
 * a-result` says the system "SHALL accept the submission and store an
 * `inspectionResult`", and a 500 satisfies `not.toBe(403)` exactly as well as a
 * 201 does. The test reported the same green whether the result was stored or
 * the submission blew up on the way in, which is anti-coverage on a
 * safety-relevant citation.
 *
 * So the acceptance test now asserts the scenario: 201, then the stored result
 * read back through `GET /inspection-results` carrying this case and this
 * `completedBy`. The coupling is real and is the price of the citation. The
 * three REFUSAL tests below still assert a bare status and are untouched, so
 * the boundary itself keeps its uncoupled guard. The status is printed in
 * every message either way.
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
	provisioningContext,
	STORAGE_STATE,
	storageStatePath,
} from './helpers/auth.ts'
import {
	createObject,
	deleteObject,
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
/** The checklist the accepted submission names. Seeded, because the schema wants a uuid. */
let checklistId = ''
/** The admin context, kept open past `beforeAll` so the teardown can remove what it seeded. */
let adminCleanup: APIRequestContext | null = null
let adminCleanupToken = ''
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

/**
 * POST a body as one account and return the status AND the parsed body.
 *
 * `post()` above answers the refusal tests, which are about a status and
 * nothing else. The acceptance scenario is about what was STORED, so it needs
 * the record the endpoint answered with.
 *
 * @param session The account making the call.
 * @param url     The endpoint.
 * @param body    The JSON payload.
 * @return The status and the decoded body, or null when the body was not JSON.
 */
async function postJson(
	session: Session,
	url: string,
	body: Record<string, unknown>,
): Promise<{ status: number; body: any }> {
	const res = await session.api.post(url, {
		headers: {
			requesttoken: session.token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: body,
	})
	return { status: res.status(), body: await res.json().catch(() => null) }
}

/**
 * GET the inspection results recorded against a case, as one account.
 *
 * @param session The account making the call.
 * @param id      The case uuid.
 * @return The status and the decoded list, or an empty list when not JSON.
 */
async function readResults(
	session: Session,
	id: string,
): Promise<{ status: number; results: any[] }> {
	const res = await session.api.get(
		`/index.php/apps/dossiq/api/vth/cases/${id}/inspection-results`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	const body = await res.json().catch(() => null)
	return {
		status: res.status(),
		results: Array.isArray(body) ? body : (body?.results ?? []),
	}
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

		// PROVISIONING GETS ITS OWN, SESSION-FREE CONTEXT. The captured admin
		// session is fine for seeding but cannot create accounts once it is
		// half an hour old: Nextcloud answers `OCS 403 Password confirmation
		// is required`, and this file sorts late enough in the run to hit that
		// every time. See `provisioningContext` for the measurement.
		const provisioning = await provisioningContext(playwright, String(baseURL))

		// Prove the basic-auth context IS the admin before anything asks it to
		// provision. Without this, a wrong or rejected credential surfaces as
		// `ensureUser`'s "could not provision" error, which reads as a broken
		// provisioning API rather than as a failed authentication.
		const provWhoami = await provisioning.get(
			'/ocs/v2.php/cloud/user?format=json',
		)
		expect(
			String((await provWhoami.json())?.ocs?.data?.id ?? ''),
			'the basic-auth provisioning context must resolve to the admin; got '
				+ `HTTP ${provWhoami.status()}`,
		).toBe(process.env.ADMIN_USER ?? 'admin')

		await ensureUser(provisioning, '', INSPECTOR, INSPECTOR_PASSWORD)
		await ensureUser(provisioning, '', OUTSIDER, OUTSIDER_PASSWORD)
		await provisioning.dispose()

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

		// 🔴 A REAL CHECKLIST, BECAUSE THE SCHEMA WANTS A UUID. The acceptance
		// test used to post `checklistId: 'e2e-checklist'`, and
		// `inspectionResult.checklist` is declared `format: uuid` with a $ref
		// to `inspectionChecklist`. OpenRegister refused every one of those
		// submissions with "Property 'checklist' should match format 'uuid'",
		// `submitResult()` mapped the Throwable to a 500, and the old
		// `not.toBe(403)` assertion reported green over it for as long as this
		// test has existed. Nothing was ever stored, on any run.
		const checklist = await createObject(
			adminApi,
			adminToken,
			'inspectionChecklist',
			{
				name: `${RUN_PREFIX} Toezichtchecklist`,
				version: 1,
				caseTypeRef: caseType.id,
				active: true,
				items: [],
			},
		)
		checklistId = objectId(checklist)
		expect(
			checklistId,
			'the fixture checklist must have an id, or the acceptance test cannot name one',
		).not.toBe('')

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

		// NOT disposed here any more: the checklist seeded above is this
		// block's to remove, and the teardown needs a context that can write.
		adminCleanup = adminApi
		adminCleanupToken = adminToken
	})

	test.afterAll(async () => {
		// 🔴 THE RESULTS THIS FILE NOW CREATES ARE ITS OWN TO REMOVE. The
		// acceptance test stores a real `inspectionResult`, and unlike every
		// other fixture here its body carries no `RUN_PREFIX` string — only a
		// case uuid and a uid — so the global-setup residue sweep, which
		// matches on the prefix, cannot find it. Left alone these accumulate
		// one row per run, for ever.
		if (adminCleanup !== null && caseId !== '') {
			const stored = await adminCleanup
				.get(
					`/index.php/apps/dossiq/api/vth/cases/${caseId}/inspection-results`,
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				.then((res) => res.json())
				.catch(() => null)
			const rows = Array.isArray(stored) ? stored : (stored?.results ?? [])
			for (const row of rows) {
				const rowId = String(row?.id ?? row?.uuid ?? '')
				if (rowId !== '') {
					await deleteObject(
						adminCleanup,
						adminCleanupToken,
						'inspectionResult',
						rowId,
					).catch(() => {})
				}
			}
		}
		if (adminCleanup !== null && checklistId !== '') {
			await deleteObject(
				adminCleanup,
				adminCleanupToken,
				'inspectionChecklist',
				checklistId,
			).catch(() => {})
		}
		await adminCleanup?.dispose()
		await inspector?.api.dispose()
		await outsider?.api.dispose()
	})

	// @e2e openspec/specs/inspection-checklists/spec.md#the-cases-assignee-submits-a-result
	// @e2e openspec/specs/inspection-checklists/spec.md#a-submitted-result-is-readable-back
	//
	// 🔴 `not.toBe(403)` WAS NOT THE SCENARIO, AND HAS BEEN REPLACED. The file
	// header argued for it, on the grounds that pinning a 201 would couple an
	// authz spec to `inspectionResult` validation. The argument is reasonable
	// and it is about the wrong thing: the scenario this test CITES says the
	// system "SHALL accept the submission and store an `inspectionResult` whose
	// `case` is <the case> and whose `completedBy` is <the assignee>". A 500
	// satisfies `not.toBe(403)` exactly as well as a 201 does, so the test as
	// written reported the same green whether the result was stored or the
	// submission blew up on the way in. Either the assertion states the
	// scenario or the citation comes down; this states the scenario.
	//
	// The coupling the header feared is real and is priced. A payload change
	// that makes `inspectionResult` invalid will redden this test, and that is
	// the correct outcome for a citation claiming the result is stored and
	// readable back. The three refusal tests below still assert a bare status
	// and are untouched, so the authz boundary keeps its uncoupled guard.
	//
	// ✅ AND IT CAUGHT SOMETHING ON ITS FIRST RUN. Asserting 201 turned the
	// first run of this test red with
	//
	//   500 {"message":"Submission failed: Property 'checklist' should match
	//        format 'uuid' but 'e2e-checklist' does not."}
	//
	// so no submission this test ever made had been stored, on any run, and
	// `not.toBe(403)` had reported green over that the whole time. `beforeAll`
	// seeds a real `inspectionChecklist` now and the submission names its uuid.
	// Worth noting separately, and not fixed here: `submitResult()` maps an
	// OpenRegister validation failure onto a 500 through its `Throwable` arm,
	// so a bad payload is reported as a server fault.
	//
	// ⚠️ MUTATION CHECK NOT RUN. Every branch here is decided in PHP on the
	// shared instance, and permission to mutate it is still pending. The two
	// mutation points, and the assertion each must redden:
	//
	//   lib/Controller/InspectionChecklistController.php, submitResult():
	//     return STATUS_OK instead of STATUS_CREATED
	//     -> "the stored assignee's submission must be created, not merely
	//        not-refused"
	//   lib/Service/InspectionChecklistService.php, submitResult():
	//     drop `completedBy` from `$payload`
	//     -> "the stored result must record <uid> as the person who completed it"
	//
	// Neither is reachable from the browser: this is an API test with no bundle
	// in the path, so `tests/e2e/helpers/mutate-bundle.ts` cannot help here the
	// way it can on the client-side citations in this suite.
	test('the stored assignee may submit', async () => {
		const { status, body } = await postJson(inspector, submitUrl(caseId), {
			checklistId,
			answers: [],
		})

		// The authorization boundary first, named as such, so a refusal still
		// reads as a refusal rather than as "expected 201".
		expect(
			status,
			`"${INSPECTOR}" is the stored assignee of case ${caseId} and must not `
				+ `be refused; the endpoint answered ${status}`,
		).not.toBe(403)
		// THEN the system SHALL ACCEPT the submission.
		expect(
			status,
			"the stored assignee's submission must be created, not merely "
				+ `not-refused; the endpoint answered ${status} `
				+ `${JSON.stringify(body ?? {}).slice(0, 300)}`,
		).toBe(201)

		// AND store an `inspectionResult` whose `case` is this case and whose
		// `completedBy` is this account. Read back through the endpoint the
		// sibling scenario names, not out of the create response, because a
		// create that echoes its input proves nothing about what was stored.
		const readBack = await readResults(inspector, caseId)
		expect(
			readBack.status,
			`the assignee must be able to read the results of case ${caseId} back; `
				+ `the endpoint answered ${readBack.status}`,
		).toBe(200)
		const mine = readBack.results.filter(
			(row: any) => String(row?.completedBy ?? '') === INSPECTOR,
		)
		expect(
			mine.length,
			`the stored result must record "${INSPECTOR}" as the person who `
				+ `completed it; the case holds ${readBack.results.length} result(s) `
				+ `completed by ${JSON.stringify(readBack.results.map((row: any) => row?.completedBy))}`,
		).toBeGreaterThan(0)
		expect(
			mine.map((row: any) => String(row?.case?.id ?? row?.case ?? '')),
			`every stored result read back for case ${caseId} must name that case`,
		).toEqual(mine.map(() => caseId))
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
