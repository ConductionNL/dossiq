/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SEC-SF-1: every BSN and every special-category field on dossiq's schemas
 * is readable by the group `dossiq-sensitive` only, and OpenRegister is the one
 * that withholds it.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. The unit test asserts the SHAPE
 * dossiq declares. It cannot assert that the shape is the shape OpenRegister
 * reads, and that is the whole failure mode: a rule under a key OpenRegister
 * does not know is dropped in silence, the BSN keeps arriving, and the schema
 * keeps showing a rule nobody enforces.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED, AND
 * THE CONTROL IS A DIFFERENT ACCOUNT. Reading as an administrator proves
 * nothing: OpenRegister's permission handler returns true for the admin group
 * before it looks at a field, so an admin read is green whether the rule landed
 * or not. Two accounts, two memberships, one object, two sessions.
 *
 * 🔴 THE THIRD TEST IS THE ONE THIS CHANGE IS ACTUALLY UNSURE ABOUT. The BSN is
 * a REQUIRED property on `brpPerson` and on the three sociaal-domein schemas.
 * A reader outside the group does not receive it; if that reader then saves the
 * object, either OpenRegister merges the withheld value back or the write is
 * refused for a missing required field, or, worst, the BSN is wiped. The unit
 * test cannot tell those three apart and neither can reading the code. This
 * asks the instance instead of guessing, and it asserts the only outcome that
 * is acceptable: whatever happens to the write, the BSN is still there
 * afterwards when the member reads it.
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
	objectId,
	REGISTER,
	RUN_PREFIX,
} from './helpers/fixtures.ts'

/** The schema the probe reads: a person, whose BSN is the field in question. */
const SCHEMA = 'brpPerson'

/** The field the rule hides. */
const SENSITIVE_FIELD = 'citizenServiceNumber'

/** A field on the same object that no rule touches, so an empty read shows up. */
const OPEN_FIELD = 'displayName'

/** The group the rule names. */
const HOLDING_GROUP = 'dossiq-sensitive'

/** The group the probe account is in, which the rule does not name. */
const RESTRICTED_GROUP = 'behandelaars'

/** The account that should NOT see the BSN. */
const HANDLER = 'e2e-sensitive-handler'

/** The account that should. */
const OFFICER = 'e2e-sensitive-officer'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Sensitive-2026!'

/**
 * The BSN the fixture carries.
 *
 * Absent from every seed file and from every other spec, and valid under the
 * 11-proef. A number the shipped register already holds would be resolvable by
 * a lookup the probe never made, which is how an earlier spec in this repo
 * linked a card to the wrong person and passed.
 */
const FIXTURE_BSN = '999990032'

let handlerApi: APIRequestContext | null = null
let officerApi: APIRequestContext | null = null
let adminApi: APIRequestContext | null = null
let adminToken = ''
let personId = ''

/**
 * Create a group and put one account in it, over the OCS provisioning API.
 *
 * Idempotent in both halves: OCS answers 102 for a group that exists, and
 * adding an account that is already a member is not an error.
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
 * @param playwright The Playwright fixture, for the request context.
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
 * Read the fixture person as whoever the given context is.
 *
 * @param api The request context.
 */
async function readPerson(api: APIRequestContext): Promise<any> {
	const response = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${personId}`,
	)
	expect(
		response.ok(),
		`the person must be readable at all, or an absent BSN means nothing; got ${response.status()}`,
	).toBeTruthy()

	return response.json()
}

test.describe('sensitive fields behind an extra permission', () => {
	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// Two accounts, two groups, two sessions and a fixture is more than the
		// default hook budget on a loaded rig, and a bare hook timeout names
		// none of it.
		test.setTimeout(240_000)

		adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		adminToken = await getRequestToken(adminApi)

		// Provisioning gets its own session-free context: a captured admin
		// session answers "Password confirmation is required" once it is half an
		// hour old, and this file can sort late enough in a run to meet that.
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await ensureUser(provisioning, '', OFFICER, PASSWORD)
		await ensureMembership(provisioning, RESTRICTED_GROUP, HANDLER)
		await ensureMembership(provisioning, HOLDING_GROUP, OFFICER)
		await provisioning.dispose()

		const person = await createObject(adminApi, adminToken, SCHEMA, {
			[SENSITIVE_FIELD]: FIXTURE_BSN,
			[OPEN_FIELD]: `${RUN_PREFIX} Sanne Bergsma`,
			name: { givenNames: 'Sanne', namePrefix: '', surname: 'Bergsma' },
		})
		personId = objectId(person)
		expect(personId, 'the fixture person must have an id').not.toBe('')

		handlerApi = await signIn(browser, String(baseURL), HANDLER, playwright)
		officerApi = await signIn(browser, String(baseURL), OFFICER, playwright)
	})

	test.afterAll(async () => {
		await handlerApi?.dispose()
		await officerApi?.dispose()
		if (adminApi) {
			await cleanupRunObjects(adminApi, adminToken, [SCHEMA])
			await adminApi.dispose()
		}
	})

	// @e2e openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#a-handler-outside-the-group-does-not-see-the-bsn
	// @e2e security-hardening::a-handler-outside-the-group-does-not-see-the-bsn
	//
	// BREAKS IF: the `authorization.read` block is dropped, renamed, or written
	// on a schema whose version did not move, in which case OpenRegister never
	// imported it. All three look identical in the file and identical on screen.
	test('the member receives the BSN and the handler does not', async () => {
		// The control first. Without it an absence proves only that the fixture
		// never carried a BSN, which is the same empty answer a working rule
		// gives, and this would pass on a rule that never landed.
		const asOfficer = await readPerson(officerApi as APIRequestContext)
		expect(
			String(asOfficer?.[SENSITIVE_FIELD] ?? ''),
			'the holding group must receive the BSN, or the probe below is measuring an empty fixture',
		).toBe(FIXTURE_BSN)

		const asHandler = await readPerson(handlerApi as APIRequestContext)
		// The object is readable, and it is the same object: an unreadable
		// object would hide the BSN for a reason that has nothing to do with
		// this rule.
		expect(String(asHandler?.[OPEN_FIELD] ?? '')).toContain(RUN_PREFIX)
		expect(Object.keys(asHandler)).not.toContain(SENSITIVE_FIELD)
	})

	// @e2e openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#a-reveal-is-audited
	// @e2e security-hardening::a-reveal-is-audited
	//
	// BREAKS IF: the reveal is served without an audit row. The audit is
	// OpenRegister's, not dossiq's: this change deliberately writes no
	// `sociaalDomeinAuditLog` row, so an instance whose OpenRegister has no
	// field-access audit records nothing at all and the reveal is unaccountable.
	// An OpenRegister that does not answer the route SKIPS rather than fails: a
	// red there would say this app is broken when it is not.
	test('a reveal by a member leaves a field-access audit row', async () => {
		await readPerson(officerApi as APIRequestContext)

		const audit = await (adminApi as APIRequestContext).get(
			'/index.php/apps/openregister/api/audit-trail/field-access',
			{ params: { object: personId, field: SENSITIVE_FIELD, _limit: '20' } },
		)
		test.skip(
			audit.status() === 404,
			'this OpenRegister carries no field-access audit yet',
		)
		expect(audit.ok()).toBe(true)

		const body: any = await audit.json()
		const rows = Array.isArray(body?.results) ? body.results : body
		expect(
			JSON.stringify(rows),
			'the reveal must be accountable, and this app writes no audit row of its own',
		).toContain(SENSITIVE_FIELD)
	})

	// @e2e openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md#a-handler-outside-the-group-does-not-see-the-bsn
	// @e2e security-hardening::a-handler-outside-the-group-does-not-see-the-bsn
	//
	// THE REQUIRED-FIELD HAZARD, ASKED RATHER THAN ASSUMED. `citizenServiceNumber`
	// is REQUIRED on this schema. The handler cannot read it, so whatever they
	// save cannot carry it. Three outcomes are possible and only one is
	// acceptable. The write may be merged (fine), refused for a missing required
	// field (survivable, and this records which), or accepted with the BSN wiped
	// (a silent data loss this change would have caused). The assertion is on
	// what the MEMBER reads afterwards, because that is the only reading that
	// can tell the third outcome from the first two.
	test('a save by the handler never costs the person their BSN', async () => {
		const api = handlerApi as APIRequestContext
		const token = await getRequestToken(api)

		const written = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${personId}`,
			{
				headers: { requesttoken: token },
				data: { [OPEN_FIELD]: `${RUN_PREFIX} Sanne Bergsma (bijgewerkt)` },
			},
		)
		// Either answer is acceptable. Which one it is belongs in the run log,
		// because it is the thing this change could not settle without an
		// instance.
		console.log(
			`[sensitive-fields] a handler's save of a row with a hidden required field answered ${written.status()}`,
		)

		const asOfficer = await readPerson(officerApi as APIRequestContext)
		expect(
			String(asOfficer?.[SENSITIVE_FIELD] ?? ''),
			'the BSN must survive a save made by somebody who could not read it',
		).toBe(FIXTURE_BSN)
	})
})
