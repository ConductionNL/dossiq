/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SEC-CL-1, CL-2 and CL-3: a citizen lookup answers only the fields the
 * caller may read, is limited per account, and is recorded whether it was
 * permitted or refused.
 *
 * 🔴 THREE PRINCIPALS, BECAUSE TWO CANNOT TELL THE FINDINGS APART.
 *
 *   - `outsider`, in no group at all. The guard must refuse it, and the refusal
 *     must appear in the log. This is the account PROC-IDOR-01 was reproduced
 *     with.
 *   - `handler`, in `kcc` but NOT in `dossiq-sensitive`. It must receive the
 *     lookup WITHOUT the four sensitive fields. This is the one the old guard
 *     got wrong, and it is invisible to an admin read: OpenRegister's
 *     permission handler returns true for the admin group before it looks at a
 *     field, and `CitizenLookupGuard::maySeeSensitiveFields()` is deliberately
 *     membership-only for the same reason.
 *   - `officer`, in both. The CONTROL. Without it an absent field proves only
 *     that the fixture never carried one, which is the same JSON.
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
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	RUN_PREFIX,
} from './helpers/fixtures.ts'

/** The four fields the declaration and the redaction both name. */
const SENSITIVE_FIELDS = [
	'callerIdentification',
	'geidentificeerdeBurgerId',
	'summary',
	'transcript',
]

/** The group that may read them. */
const SENSITIVE_GROUP = 'dossiq-sensitive'

/** The group that may make the lookup at all. */
const LOOKUP_GROUP = 'kcc'

const OUTSIDER = 'e2e-lookup-outsider'
const HANDLER = 'e2e-lookup-handler'
const OFFICER = 'e2e-lookup-officer'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Lookup-2026!'

/**
 * The citizen reference the fixture is filed under.
 *
 * Absent from every seed file and from every other spec, so a row this spec
 * did not write can never satisfy an assertion below.
 */
const SUBJECT = `${RUN_PREFIX}-burger`

/** The call summary, which only the officer may read back. */
const SUMMARY = `${RUN_PREFIX} belde over de aanvraag`

let outsiderApi: APIRequestContext | null = null
let handlerApi: APIRequestContext | null = null
let officerApi: APIRequestContext | null = null
let adminApi: APIRequestContext | null = null
let adminToken = ''

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

/**
 * Fetch the citizen's contact moments as whoever the given context is.
 *
 * @param api The request context.
 */
async function lookup(api: APIRequestContext) {
	return api.get('/index.php/apps/dossiq/api/kcc/contactmomenten', {
		params: { burgerId: SUBJECT },
	})
}

/**
 * The audit rows written about this spec's citizen.
 *
 * @param api An admin context.
 */
async function auditRows(api: APIRequestContext): Promise<any[]> {
	return listObjects(api, 'sociaalDomeinAuditLog', {
		subjectId: SUBJECT,
		_limit: '200',
	})
}

test.describe('a citizen lookup is guarded and recorded', () => {
	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// Three accounts, three groups, three sessions and a fixture is more
		// than the default hook budget on a loaded rig, and a bare hook timeout
		// names none of it.
		test.setTimeout(300_000)

		adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		adminToken = await getRequestToken(adminApi)

		const provisioning = await provisioningContext(playwright, String(baseURL))
		for (const uid of [OUTSIDER, HANDLER, OFFICER]) {
			await ensureUser(provisioning, '', uid, PASSWORD)
		}
		await ensureMembership(provisioning, LOOKUP_GROUP, HANDLER)
		await ensureMembership(provisioning, LOOKUP_GROUP, OFFICER)
		await ensureMembership(provisioning, SENSITIVE_GROUP, OFFICER)
		// OUTSIDER is deliberately put in NOTHING. Its refusal is the finding.
		await provisioning.dispose()

		await createObject(adminApi, adminToken, 'contactmoment', {
			notificationChannel: 'telefoon',
			direction: 'inbound',
			startTime: new Date().toISOString(),
			geidentificeerdeBurgerId: SUBJECT,
			callerIdentification: '+31600000000',
			summary: SUMMARY,
			transcript: `${RUN_PREFIX} transcriptie`,
		})

		outsiderApi = await signIn(browser, String(baseURL), OUTSIDER, playwright)
		handlerApi = await signIn(browser, String(baseURL), HANDLER, playwright)
		officerApi = await signIn(browser, String(baseURL), OFFICER, playwright)
	})

	test.afterAll(async () => {
		await outsiderApi?.dispose()
		await handlerApi?.dispose()
		await officerApi?.dispose()
		if (adminApi) {
			await cleanupRunObjects(adminApi, adminToken, [
				'contactmoment',
				'sociaalDomeinAuditLog',
			])
			await adminApi.dispose()
		}
	})

	// @e2e openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#a-member-of-the-group-receives-them
	// @e2e security-hardening::a-member-of-the-group-receives-them
	//
	// THE CONTROL, AND IT RUNS FIRST. An absent field and an empty fixture are
	// the same JSON, so every absence asserted below is only worth something
	// after this has shown the value is there to withhold.
	test('a member of the sensitive group receives the four fields', async () => {
		const response = await lookup(officerApi as APIRequestContext)
		expect(
			response.ok(),
			`the lookup must answer; got ${response.status()}`,
		).toBe(true)

		const rows = (await response.json())?.contactmomenten ?? []
		const row = rows.find((r: any) => String(r?.summary ?? '') === SUMMARY)
		expect(row, 'the fixture contact moment must be in the listing').toBeTruthy()
		for (const field of SENSITIVE_FIELDS) {
			expect(Object.keys(row)).toContain(field)
		}
	})

	// @e2e openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#a-call-handler-outside-the-group-receives-the-lookup-without-the-four-fields
	// @e2e security-hardening::a-call-handler-outside-the-group-receives-the-lookup-without-the-four-fields
	//
	// BREAKS IF: `redactForCaller` stops being called, stops knowing a key, or
	// the declaration lands on a schema whose version did not move. The last
	// one is invisible in the file and invisible on screen.
	test('a call handler outside the group receives the lookup without them', async () => {
		const response = await lookup(handlerApi as APIRequestContext)
		expect(
			response.ok(),
			`the lookup must still answer; got ${response.status()}`,
		).toBe(true)

		const rows = (await response.json())?.contactmomenten ?? []
		// The lookup is still a lookup: a redaction that empties it is the
		// failure that gets the whole rule removed a week later.
		expect(
			rows.length,
			'the handler must still see that a call happened',
		).toBeGreaterThan(0)
		for (const row of rows) {
			for (const field of SENSITIVE_FIELDS) {
				expect(Object.keys(row)).not.toContain(field)
			}
		}
	})

	// @e2e openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#a-permitted-lookup-leaves-a-row-naming-the-account-and-the-citizen
	// @e2e security-hardening::a-permitted-lookup-leaves-a-row-naming-the-account-and-the-citizen
	test('a permitted lookup leaves a row naming the account and the citizen', async () => {
		await lookup(officerApi as APIRequestContext)

		await expect(async () => {
			const rows = await auditRows(adminApi as APIRequestContext)
			const mine = rows.filter(
				(r: any) => String(r?.employeeId ?? '') === OFFICER,
			)
			expect(mine.length, 'the reveal must be recorded').toBeGreaterThan(0)
			expect(String(mine[0].result)).toBe('succes')
			expect(String(mine[0].subjectId)).toBe(SUBJECT)
		}).toPass({ timeout: 30_000 })
	})

	// @e2e openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#a-refused-lookup-leaves-a-row-too
	// @e2e security-hardening::a-refused-lookup-leaves-a-row-too
	//
	// THE HALF THAT CATCHES THE ENUMERATION. An account refused four hundred
	// times in an afternoon is not a handler who mistyped a BSN, and before
	// this change a refusal left no trace anywhere.
	test('a refused lookup leaves a row too', async () => {
		const refused = await lookup(outsiderApi as APIRequestContext)
		expect(
			refused.status(),
			'an account in no group must be refused, or this measures nothing',
		).toBe(403)

		await expect(async () => {
			const rows = await auditRows(adminApi as APIRequestContext)
			const mine = rows.filter(
				(r: any) => String(r?.employeeId ?? '') === OUTSIDER,
			)
			expect(mine.length, 'the refusal must be recorded').toBeGreaterThan(0)
			expect(String(mine[0].result)).toBe('geweigerd-none-toegang')
		}).toPass({ timeout: 30_000 })
	})

	// @e2e openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#an-account-over-the-limit-is-refused
	// @e2e security-hardening::an-account-over-the-limit-is-refused
	//
	// BREAKS IF: the `UserRateLimit` attribute is dropped from a lookup method,
	// which is invisible: a method with no limit behaves exactly like one with
	// a generous limit. It answers.
	//
	// The limit is 60 an hour, so this spends 65 requests on the OUTSIDER,
	// whose lookups are refused anyway: burning an allowance on the handler or
	// the officer would leave them rate limited for the rest of the run and
	// redden the tests above when this file is retried. Nextcloud's middleware
	// runs BEFORE the controller, so a 429 arrives whether the guard would have
	// allowed the call or not, which is what makes the refused account usable
	// as the probe here.
	test('an account over the limit is refused with 429', async () => {
		test.setTimeout(180_000)
		const api = outsiderApi as APIRequestContext

		let sawTooMany = false
		for (let i = 0; i < 65; i++) {
			const response = await lookup(api)
			if (response.status() === 429) {
				sawTooMany = true
				break
			}
			expect(
				response.status(),
				'until the limit bites, the refusal is the 403 the guard gives',
			).toBe(403)
		}

		expect(
			sawTooMany,
			'65 lookups in one hour must not all be served; the limit is 60',
		).toBe(true)
	})
})
