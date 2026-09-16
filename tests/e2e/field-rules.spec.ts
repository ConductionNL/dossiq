/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SEC-FR-1 and REQ-SEC-FR-2: a case type declares which roles keep a field
 * and which lose it, dossiq projects the declaration onto the case schema at
 * publish, and OpenRegister is the one that withholds the field.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. Every unit test in this change
 * asserts the SHAPE dossiq publishes. Not one of them can assert that the shape
 * is the shape OpenRegister reads, and that is the whole failure mode here: a
 * block keyed `readonly` instead of `readOnly`, or a deny list written where an
 * allow list is read, is refused by nothing and reported by nobody. The case
 * keeps saving, the field keeps arriving, and the editor keeps showing a rule.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED, AND
 * THE CONTROL IS A DIFFERENT ACCOUNT. Reading the case as an administrator
 * proves nothing at all: `PermissionHandler::hasPermission()` returns true
 * immediately for the admin group and `filterReadableProperties()` returns the
 * object unmodified, so an admin read is green whether the rule landed or not.
 * Reading it twice as the SAME account proves nothing either, which is the
 * shape this file was nearly written in. So two accounts are provisioned into
 * two groups, and the two reads of one object are made from two sessions: one
 * absence and one presence, same object, same state, same moment.
 *
 * WHAT IT DELIBERATELY DOES NOT ASSERT. It never recomputes who should hold
 * what. The panel's sentence is compared to the declaration, never to an
 * arithmetic of groups performed in this file: that would be a second evaluator
 * of the question OpenRegister owns, and it would eventually disagree with the
 * app and be "fixed" in whichever direction was easier.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
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
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'

/** The register cases live in. */
const REGISTER = 'dossiq'

/** The schema a case is an object of. */
const SCHEMA = 'case'

/** The group the rules take the fields away from. */
const RESTRICTED_GROUP = 'behandelaars'

/** The group that keeps them. */
const HOLDING_GROUP = 'dossiq-quality'

/** The account in the restricted group. */
const HANDLER = 'e2e-fieldrules-handler'

/** The account in the holding group. */
const OFFICER = 'e2e-fieldrules-officer'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-FieldRules-2026!'

/** The field the worked example hides. */
const HIDDEN_FIELD = 'qualityScore'

/** The field the worked example freezes. */
const READ_ONLY_FIELD = 'confidentiality'

/** The rules the worked example declares, in the case type's own shape. */
const FIELD_ROLE_RULES = [
	{
		field: HIDDEN_FIELD,
		rule: 'hidden',
		groups: [RESTRICTED_GROUP],
		heldBy: [HOLDING_GROUP],
		reason:
			'The quality officer scores the handling, so the handler does not read their own score.',
	},
	{
		field: READ_ONLY_FIELD,
		rule: 'readOnly',
		groups: [RESTRICTED_GROUP],
		heldBy: [HOLDING_GROUP],
		reason: 'The coordinator decides how confidential a case is.',
	},
]

let handlerApi: APIRequestContext | null = null
let officerApi: APIRequestContext | null = null
let caseId = ''

/**
 * Create a group and put one account in it, over the OCS provisioning API.
 *
 * Idempotent in both halves: OCS answers 102 for a group that exists, and
 * adding an account that is already a member is not an error.
 *
 * @param api      A request context authenticated as an admin.
 * @param group    The group id.
 * @param uid      The account to add.
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
 * @param browser A launched browser.
 * @param baseURL The instance.
 * @param uid     The account.
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
 * Read one case as whoever the given context is.
 *
 * @param api The request context.
 * @param id  The case uuid.
 */
async function readCase(api: APIRequestContext, id: string): Promise<any> {
	const response = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${id}`,
	)
	expect(
		response.ok(),
		`the case must be readable at all, or an absent field means nothing; got ${response.status()}`,
	).toBeTruthy()

	return response.json()
}

test.describe('field rules per role', () => {
	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// Two accounts, two groups, two sessions, a case type and a case is more
		// than the default hook budget on a loaded rig, and a bare hook timeout
		// names none of it.
		test.setTimeout(240_000)

		const adminApi = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		const adminToken = await getRequestToken(adminApi)

		// Provisioning gets its own session-free context: a captured admin
		// session answers "Password confirmation is required" once it is half an
		// hour old, and this file sorts late enough in a run to meet that.
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', HANDLER, PASSWORD)
		await ensureUser(provisioning, '', OFFICER, PASSWORD)
		await ensureMembership(provisioning, RESTRICTED_GROUP, HANDLER)
		await ensureMembership(provisioning, HOLDING_GROUP, OFFICER)
		await provisioning.dispose()

		// 🔴 A CASE TYPE OF ITS OWN, AND IT HAS TO BE PUBLISHED. `ensureCaseType`
		// REUSES whatever publishable type the instance already has, so the
		// rules would land on nothing. And nothing is projected onto the schema
		// until the type is published: that is the one moment the projector
		// runs. A suite that skipped the publish would read an unrestricted
		// case, see the field, and report a rule that was never written.
		const machine = await seedStateMachine(adminApi, adminToken)
		await updateObject(adminApi, adminToken, 'caseType', machine.caseTypeId, {
			initialStatus: machine.statusReceived,
			fieldRoleRules: FIELD_ROLE_RULES,
		})

		const published = await adminApi.post(
			`/index.php/apps/dossiq/api/case-types/${machine.caseTypeId}/publish`,
			{
				headers: { requesttoken: adminToken },
				data: { changeNote: 'e2e: field rules per role' },
			},
		)
		expect(
			published.ok(),
			`the case type must publish, or nothing is projected; got ${published.status()} ${await published.text()}`,
		).toBeTruthy()
		expect(
			(await published.json())?.published,
			'the publish must report published: true, or the findings below say why',
		).toBe(true)

		const seeded = await seedCase(adminApi, adminToken, {
			title: `${RUN_PREFIX} Zaak met veldregels`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
			[HIDDEN_FIELD]: 7,
			[READ_ONLY_FIELD]: 'intern',
		})
		caseId = objectId(seeded)
		expect(caseId, 'the fixture case must have an id').not.toBe('')

		handlerApi = await signIn(browser, String(baseURL), HANDLER, playwright)
		officerApi = await signIn(browser, String(baseURL), OFFICER, playwright)

		await adminApi.dispose()
	})

	test.afterAll(async ({ request }) => {
		await handlerApi?.dispose()
		await officerApi?.dispose()
		await cleanupRunObjects(request)
	})

	test('the officer keeps the field and the handler does not receive it', async () => {
		// The control first. Without it an absence proves only that the seed
		// never wrote a value, which is the same empty answer a working rule
		// gives, and the test would pass on a rule that never landed.
		const asOfficer = await readCase(officerApi as APIRequestContext, caseId)
		expect(
			Object.keys(asOfficer),
			'the holding group must receive the field, or the probe below is measuring an empty seed',
		).toContain(HIDDEN_FIELD)

		const asHandler = await readCase(handlerApi as APIRequestContext, caseId)
		expect(Object.keys(asHandler)).not.toContain(HIDDEN_FIELD)
	})

	test('the handler is refused the change the rule freezes', async () => {
		const api = handlerApi as APIRequestContext
		const token = await getRequestToken(api)

		const refused = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${caseId}`,
			{
				headers: { requesttoken: token },
				data: { [READ_ONLY_FIELD]: 'geheim' },
			},
		)

		expect(refused.status()).toBe(422)
		expect(await refused.text()).toContain(READ_ONLY_FIELD)
	})

	test('the officer may still make that change', async () => {
		const api = officerApi as APIRequestContext
		const token = await getRequestToken(api)

		const allowed = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${caseId}`,
			{
				headers: { requesttoken: token },
				data: { [READ_ONLY_FIELD]: 'vertrouwelijk' },
			},
		)

		expect(
			allowed.ok(),
			`the holding group must keep the write, or the rule restricts everybody; got ${allowed.status()}`,
		).toBeTruthy()
	})

	test('the access tab names the rule behind the field that is not there', async ({
		browser,
		baseURL,
	}) => {
		const context = await browser.newContext({
			baseURL,
			storageState: storageStatePath(HANDLER),
		})
		const page = await context.newPage()

		await page.goto(`/index.php/apps/dossiq/cases/${caseId}`)
		await page.getByRole('tab', { name: /access|toegang/i }).click()

		const fields = page.locator('.case-access-tab__fields')
		await expect(fields).toContainText(HIDDEN_FIELD)
		await expect(fields).toContainText(RESTRICTED_GROUP)
		await expect(fields).toContainText(HOLDING_GROUP)

		await context.close()
	})
})
