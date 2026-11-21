/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * social-domain-consent-and-safeguards task 5.1: the scenarios of
 * REQ-SDC-CONSENT-01 (consent recorded and revoked on the case, and sharing
 * refused without it), REQ-SDC-SAFE-01 (a special-category classification at
 * intake, and cases kept to the handling team) and REQ-SDC-ANON-01 (an
 * anonymised export of a case list).
 *
 * WHERE EACH SCENARIO IS DECIDED.
 *
 *   Consent   `CaseTransferConsentGate::assessSharing`, asked by
 *             `POST /api/federation/shares` (`caseFederation#createFederatedShare`)
 *             before anything leaves. A case type with
 *             `requiresConsentForSharing` needs a `toestemming` that names the
 *             party in `recipientParties`, covers today and is not
 *             `withdrawn`; otherwise the share answers 409 with
 *             "There is no valid consent for this party." and the rule. The
 *             party is compared trimmed and case-blind, so "Veilig Thuis" is
 *             the party exactly as the scenario names it. The revoke is the
 *             patch the case page's Revoke row action makes: `withdrawn: true`.
 *   Intake    `SpecialCategoryClassification` in `IntakeRequirementsListener`
 *             refuses a case of a type with `requiresSpecialCategory` and no
 *             `gdprClassification` with 422, naming the field. That listener
 *             holds every create path alike, so the create form's refusal is
 *             this refusal; it is asserted on OpenRegister's object API, the
 *             write the form makes.
 *   Team only `handlingTeamOnly` is declared but not enforced (task 3.2), so
 *             that scenario is fixme.
 *   Export    `POST /api/cases/anonymised-export` (`caseAnonymisedExport#export`),
 *             the endpoint the Cases list's bulk action "Export anonymised"
 *             posts the selection to and saves as CSV. It removes every field
 *             the case type lists in `identifyingFields`, an address to its
 *             postcode area and a date to its year. The case schema carries no
 *             birth date, so the seeded case type marks `startDate`
 *             identifying to stand in for one; the generalisation rule is the
 *             same for every date.
 *
 * SEEDED AND REMOVED. Throwaway case types carrying the run prefix (so no
 * shipped package is changed), their cases and the consent; teardown sweeps
 * them by id. The BSN is the documented test BSN, no real person's.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, provisioningContext } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { expectRefused, NOT_FOUND } from './helpers/refusals.ts'

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/** The share path the consent gate guards. */
const FEDERATED_SHARE = '/index.php/apps/dossiq/api/federation/shares'

/** The anonymised export. */
const ANONYMISED_EXPORT = '/index.php/apps/dossiq/api/cases/anonymised-export'

/** The refusal of a share without a covering consent, in either locale. */
const NO_VALID_CONSENT = /(There is no valid consent for this party|Er is geen geldige toestemming voor deze partij)/

/** A documented test BSN (passes the eleven-test, belongs to nobody). */
const TEST_BSN = '999993653'

/** A name that must not survive the anonymised export. */
const TEST_NAME = `${RUN_PREFIX} Jansen`

/** The date that stands in for a birth date, and the year it must become. */
const TEST_DATE = '1984-05-17'

/** How many cases the export scenario lists. */
const EXPORT_COUNT = 20

/** A handler outside the handling team, for the team-only scenario. */
const OUTSIDER = 'e2e-sdc-outsider'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Sdc-Outsider-2026!'

let api: APIRequestContext
let token = ''

/**
 * Seed a published case type carrying the run prefix.
 *
 * @param label  What it stands for, in its title.
 * @param fields The safeguard flags it switches on.
 *
 * @return Its id.
 */
async function seedCaseType(label: string, fields: Record<string, unknown>): Promise<string> {
	const created = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} ${label}`,
		identifier: `${RUN_PREFIX.toLowerCase()}-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`,
		description: 'Throwaway case type seeded by the social domain safeguards e2e.',
		isDraft: false,
		...fields,
	})

	return objectId(created)
}

/**
 * Ask to share a case with a party outside the organisation.
 *
 * @param caseId The case.
 * @param party  The party, as the consent names it.
 *
 * @return The status and body.
 */
async function share(caseId: string, party: string): Promise<{ status: number; body: any }> {
	const res = await api.post(FEDERATED_SHARE, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		data: { caseId, remoteCloudId: party, sharedFields: ['title'], permissionLevel: 'bekijken' },
	})

	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * Today as YYYY-MM-DD.
 *
 * @return The day.
 */
function today(): string {
	return new Date().toISOString().slice(0, 10)
}

test.describe('Social domain consent and safeguards', () => {
	test.setTimeout(240_000)

	let consentCase = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const jeugdwet = await seedCaseType('Jeugdwet', { requiresConsentForSharing: true })
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Jeugdwet consent`,
			caseType: jeugdwet,
		})
		consentCase = objectId(created)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['toestemming', 'case', 'caseType'])
		await api.dispose()
	})

	// @e2e openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#scenario-sharing-without-consent-is-refused
	test('sharing a case with a party no consent names is refused, saying why', async () => {
		const refused = await share(consentCase, 'Veilig Thuis')

		expect(refused.status, `the share must be refused as a decision, not an upstream failure: ${JSON.stringify(refused.body)}`).toBe(409)
		expect(String(refused.body?.error ?? '')).toMatch(NO_VALID_CONSENT)
		expect(refused.body?.rule).toBe('consent-missing')
	})

	// @e2e openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#scenario-a-revoked-consent-stops-the-next-share
	test('a consent revoked on the case stops the next share with that party', async () => {
		const consent = await createObject(api, token, 'toestemming', {
			caseId: consentCase,
			grantedByBsn: TEST_BSN,
			grantedByName: TEST_NAME,
			recipientParties: ['Wijkteam Noord'],
			tedoel: 'Afstemming van de hulpverlening',
			grantedDate: today(),
			withdrawn: false,
			withdrawalPossible: true,
		})
		const consentId = objectId(consent)

		// The Revoke row action on the case page's consents widget patches exactly this.
		const revoked = await api.patch(`${OBJECTS}/toestemming/${consentId}`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: { withdrawn: true },
		})
		expect(revoked.ok(), `revoking the consent: ${revoked.status()} ${await revoked.text()}`).toBeTruthy()

		const refused = await share(consentCase, 'Wijkteam Noord')
		expect(refused.status, `a withdrawn consent covers no share: ${JSON.stringify(refused.body)}`).toBe(409)
		expect(String(refused.body?.error ?? '')).toMatch(NO_VALID_CONSENT)
		expect(refused.body?.rule).toBe('consent-withdrawn')
	})

	// @e2e openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#scenario-intake-asks-for-the-classification
	test('a case of a type that requires a classification is not created without one, and the refusal names the field', async () => {
		const wmo = await seedCaseType('Wmo', { requiresSpecialCategory: true, specialDataCategories: ['health'] })

		const refused = await api.post(`${OBJECTS}/case`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: {
				title: `${RUN_PREFIX} Wmo without classification`,
				caseType: wmo,
				impact: 'medium',
				urgency: 'medium',
				intakeChannel: 'manual',
			},
		})
		const body = await refused.text()
		if (refused.ok()) {
			// A create that went through would otherwise outlive the run.
			const leaked: any = JSON.parse(body)
			trackCreatedObject('case', objectId(leaked))
		}
		expect(refused.status(), `the intake refuses it before the save: ${body}`).toBe(422)
		expect(body, 'the refusal names the field to fill in').toContain('gdprClassification')

		// The control: the same case with its classification is taken in.
		const classified = await seedCase(api, token, {
			title: `${RUN_PREFIX} Wmo classified`,
			caseType: wmo,
			gdprClassification: ['health'],
		})
		expect(classified.gdprClassification).toEqual(['health'])
	})

	// @e2e openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#scenario-another-team-cannot-open-the-case
	test('a case of a team-only type answers as not found outside the handling team, administrators included', async ({ playwright, baseURL }) => {
		test.fixme(true, 'task 3.2 open: handlingTeamOnly is declared on the case type but no read rule enforces it yet')
		const provisioning = await provisioningContext(playwright, String(baseURL))
		await ensureUser(provisioning, '', OUTSIDER, PASSWORD)
		const wijkteam = `${RUN_PREFIX}-wijkteam-noord`.toLowerCase()
		await provisioning.post('/ocs/v2.php/cloud/groups?format=json', { form: { groupid: wijkteam } })

		const teamOnly = await seedCaseType('Wmo team only', { handlingTeamOnly: true, handling: { teams: [wijkteam] } })
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Wmo team only`,
			caseType: teamOnly,
		})
		const caseId = objectId(created)

		const outsider = await playwright.request.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${Buffer.from(`${OUTSIDER}:${PASSWORD}`).toString('base64')}`,
				'OCS-APIRequest': 'true',
			},
		})
		try {
			await expectRefused(await outsider.get(`${OBJECTS}/case/${caseId}`), NOT_FOUND, 'a handler of another team opening the case')
			// The admin is not in the handling team either.
			await expectRefused(await provisioning.get(`${OBJECTS}/case/${caseId}`), NOT_FOUND, 'an administrator outside the team opening the case')
		} finally {
			await provisioning.delete(`/ocs/v2.php/cloud/groups/${encodeURIComponent(wijkteam)}?format=json`).catch(() => undefined)
			await Promise.all([outsider.dispose(), provisioning.dispose()])
		}
	})

	// @e2e openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#scenario-the-anonymised-export-holds-no-bsn
	test('the anonymised export of a filtered list holds no BSN, no name and years only', async () => {
		const participatiewet = await seedCaseType('Participatiewet', {
			identifyingFields: ['subjectBsn', 'initiatorDisplayName', 'initiatorSourceId', 'requester', 'startDate'],
		})
		for (let index = 0; index < EXPORT_COUNT; index++) {
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Participatiewet ${index}`,
				caseType: participatiewet,
				// `subjectBsn` is writable by the group dossiq-sensitive only, so
				// the BSN rides in `initiatorSourceId`, which the case type also
				// marks identifying and which carries a BSN for a person.
				initiatorSourceId: TEST_BSN,
				initiatorDisplayName: TEST_NAME,
				startDate: TEST_DATE,
			})
		}

		// The filtered list the handler exports: this case type's cases.
		const listed = await api.get(`${OBJECTS}/case?caseType=${participatiewet}&_limit=100`)
		expect(listed.ok(), `listing the cases: ${listed.status()}`).toBeTruthy()
		const listBody: any = await listed.json()
		const caseIds = ((listBody?.results ?? listBody) as any[]).map(objectId)
		expect(caseIds, 'the filtered list holds the seeded cases').toHaveLength(EXPORT_COUNT)

		const exported = await api.post(ANONYMISED_EXPORT, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: { caseIds },
		})
		expect(exported.ok(), `the anonymised export: ${exported.status()} ${await exported.text()}`).toBeTruthy()
		const cases: any[] = (await exported.json()).cases
		expect(cases).toHaveLength(EXPORT_COUNT)

		const file = JSON.stringify(cases)
		expect(file, 'no BSN in the export').not.toContain(TEST_BSN)
		expect(file, 'no name in the export').not.toContain(TEST_NAME)
		for (const row of cases) {
			expect(row).not.toHaveProperty('initiatorDisplayName')
			expect(row).not.toHaveProperty('@self')
			expect(row.startDate, 'a date is reduced to its year').toBe(TEST_DATE.slice(0, 4))
		}
	})
})
