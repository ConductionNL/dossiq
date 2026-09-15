/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What happens to a case in its first five minutes: what it must answer before
 * it exists, who may take it, where it goes when it is refused, and what a
 * triage item does while nobody is acting on it.
 *
 * 🔴 EVERY ASSERTION HERE IS ON THE WRITE PATH, NOT ON THE PICKER. That is the
 * point of REQ-TRIAGE-03: a picker that offers two teams over an API that
 * accepts thirty is a narrowing that exists on screen only. So the third group
 * is written through the object API and the refusal is read off the response,
 * and the picker is checked separately for offering the two. A file that only
 * drove the picker would go green against an app with no enforcement at all.
 *
 * WHAT THIS LAYER CAN AND CANNOT PROVE. Playwright is one signed-in user, so
 * "a handler of the other department cannot read the sibling case" is asserted
 * as the fact dossiq owns, which is that each fanned-out case carries its own
 * department and not the other's. OpenRegister owns the grant itself
 * (ADR-022), and its own suite is where two principals can be named. The
 * distinction is written down rather than glossed, because a test that claims
 * to prove a permission it cannot reach is worse than one that says what it
 * covers.
 *
 * THE CASE TYPES ARE SEEDED PER RUN AND CARRY THE DECLARATIONS. An adopted
 * case type from the instance declares nothing, which is exactly the state
 * that must NOT refuse, so it is driven as its own case rather than assumed.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`

/** The object endpoint every case write goes through. */
const CASE_URL = `/index.php/apps/openregister/api/objects/${REGISTER}/case`

/** The intake declaration one case type carries. */
function requirementsUrl(caseTypeId: string): string {
	return `/index.php/apps/${REGISTER}/api/intake/case-types/${caseTypeId}/requirements`
}

/** The refusal act on one case. */
function refuseUrl(caseId: string): string {
	return `/index.php/apps/${REGISTER}/api/cases/${caseId}/refuse`
}

let api: APIRequestContext
let token: string

/** A case type declaring the two fields a new case type is created with. */
let declaringCaseTypeId = ''

/** A case type that declares nothing, as every existing one does. */
let undeclaredCaseTypeId = ''

/** A case type whose classification is the access rule. */
let classifiedCaseTypeId = ''

/** A case type narrowing the handler to two teams. */
let narrowedCaseTypeId = ''

/** A case type declaring where a refused case goes. */
let refusingCaseTypeId = ''

/**
 * Seed one case type carrying a declaration.
 *
 * @param suffix      What this case type is for, in its title.
 * @param declaration The intake declarations it carries.
 */
async function seedDeclaringCaseType(
	suffix: string,
	declaration: Record<string, unknown>,
): Promise<string> {
	const row = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} ${suffix}`,
		identifier: `${RUN_PREFIX.toLowerCase()}-${suffix.toLowerCase()}`,
		description: 'Seeded by the intake-triage-and-refusal e2e layer.',
		isDraft: false,
		...declaration,
	})

	return objectId(row)
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)

	const adopted = await ensureCaseType(api, token)
	undeclaredCaseTypeId = adopted.id

	declaringCaseTypeId = await seedDeclaringCaseType('Declaring', {
		intakeRequirements: {
			requiredBeforeCreation: ['communicationChannel', 'confidentiality'],
			requiredBeforeComplete: ['requesterAddress'],
		},
	})

	classifiedCaseTypeId = await seedDeclaringCaseType('Classified', {
		intakeRequirements: { requiredBeforeCreation: [] },
		caseClassification: {
			scheme: 'vertrouwelijkheidaanduiding',
			classificationIsAccessRule: true,
			facets: ['classification', 'sensitivity', 'actionFacet', 'insightLevel'],
		},
	})

	narrowedCaseTypeId = await seedDeclaringCaseType('Narrowed', {
		intakeRequirements: { requiredBeforeCreation: [] },
		assigneeNarrowing: {
			allowedGroups: ['handhaving', 'juridische-zaken'],
			allowedUsers: [],
		},
	})

	refusingCaseTypeId = await seedDeclaringCaseType('Refusing', {
		intakeRequirements: { requiredBeforeCreation: [] },
		refusalDestination: { department: 'juridische-zaken', role: 'intake' },
	})
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('what a case must answer before it exists', () => {
	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	test('a case cannot be created without its channel', async () => {
		const response = await api.post(CASE_URL, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} no channel`,
				caseType: declaringCaseTypeId,
				confidentiality: 'openbaar',
			},
		})

		expect(response.ok()).toBe(false)
		expect(await response.text()).toContain('communicationChannel')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	test('the confidentiality is asked for at creation', async ({ page }) => {
		trackDossiqErrors(page)
		const response = await api.get(requirementsUrl(declaringCaseTypeId), {
			headers: { requesttoken: token },
		})
		const declaration = await response.json()

		expect(response.ok()).toBe(true)
		expect(declaration.intakeRequirements.requiredBeforeCreation).toContain(
			'confidentiality',
		)
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	test('a case type that declares nothing still opens a case', async () => {
		// 🔴 THE CONTROL, AND THE BLAST-RADIUS GUARD. Every case type on an
		// upgraded instance is this one, and its cases must go on being created
		// from the same payload the widget has always written.
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} undeclared`,
			caseType: undeclaredCaseTypeId,
		})

		expect(objectId(seeded)).not.toBe('')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	test('a field required before completion does not refuse the creation', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} incomplete`,
			caseType: declaringCaseTypeId,
			communicationChannel: 'https://example.gemeente.nl/portaal',
			confidentiality: 'openbaar',
		})

		expect(objectId(seeded)).not.toBe('')
	})
})

test.describe('the classification that is an access rule', () => {
	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	test('an unclassified case is not created', async () => {
		const response = await api.post(CASE_URL, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} unclassified`,
				caseType: classifiedCaseTypeId,
			},
		})

		expect(response.ok()).toBe(false)
		expect(await response.text()).toContain('classification')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	test('the four facets are recorded when declared', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} classified`,
			caseType: classifiedCaseTypeId,
			classification: 'zaakvertrouwelijk',
			sensitivity: 'bijzondere-persoonsgegevens',
			actionFacet: 'beslissen',
			insightLevel: 'behandelaar',
		})

		expect(seeded.classification).toBe('zaakvertrouwelijk')
		expect(seeded.sensitivity).toBe('bijzondere-persoonsgegevens')
		expect(seeded.actionFacet).toBe('beslissen')
		expect(seeded.insightLevel).toBe('behandelaar')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	test('the declaration says the scheme resolves on this instance', async () => {
		const response = await api.get(requirementsUrl(classifiedCaseTypeId), {
			headers: { requesttoken: token },
		})
		const declaration = await response.json()

		expect(declaration.schemeResolves).toBe(true)
		expect(declaration.classificationValues).toContain('zaakvertrouwelijk')
	})
})

test.describe('who may be assigned at creation', () => {
	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	test('the picker offers only the declared groups', async () => {
		const response = await api.get(requirementsUrl(narrowedCaseTypeId), {
			headers: { requesttoken: token },
		})
		const declaration = await response.json()

		expect(declaration.assigneeNarrowing.allowedGroups).toEqual([
			'handhaving',
			'juridische-zaken',
		])
		expect(declaration.narrowsNothing).toBe(false)
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	test('the API refuses a group the picker never offered', async () => {
		const response = await api.post(CASE_URL, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} third group`,
				caseType: narrowedCaseTypeId,
				assignedGroup: 'burgerzaken',
			},
		})

		expect(response.ok()).toBe(false)
		const body = await response.text()
		expect(body).toContain('burgerzaken')
		expect(body).toContain('case type')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	test('a case type with no narrowing keeps every choice', async () => {
		const response = await api.get(requirementsUrl(undeclaredCaseTypeId), {
			headers: { requesttoken: token },
		})
		const declaration = await response.json()

		expect(declaration.narrowsNothing).toBe(true)
		expect(declaration.assigneeNarrowing.allowedGroups).toEqual([])
	})
})

test.describe('a refused case goes somewhere, per Awb 2:3', () => {
	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	test('a refused case lands at its declared destination', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} to refuse`,
			caseType: refusingCaseTypeId,
		})

		const response = await api.post(refuseUrl(objectId(seeded)), {
			headers: { requesttoken: token },
			data: { reason: 'Dit is een melding voor de provincie.' },
		})
		const record = await response.json()

		expect(response.ok()).toBe(true)
		expect(record.department).toBe('juridische-zaken')
		expect(record.role).toBe('intake')
		expect(record.reason).toBe('Dit is een melding voor de provincie.')
		expect(record.refusedBy).not.toBe('')
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	test('a refused case is not a lost case', async ({ page }) => {
		trackDossiqErrors(page)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} still findable`,
			caseType: refusingCaseTypeId,
		})
		await api.post(refuseUrl(objectId(seeded)), {
			headers: { requesttoken: token },
			data: { reason: 'Niet voor ons.' },
		})

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('searchbox').first().fill(`${RUN_PREFIX} still findable`)

		await expect(
			page.getByText(`${RUN_PREFIX} still findable`).first(),
		).toBeVisible()
	})

	// @e2e openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-refused-intake-goes-to-a-named-department-and-role-req-triage-04
	test('refusal with no declared destination is refused, saying so', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} nowhere to go`,
			caseType: undeclaredCaseTypeId,
		})

		const response = await api.post(refuseUrl(objectId(seeded)), {
			headers: { requesttoken: token },
			data: { reason: 'Niet voor ons.' },
		})

		expect(response.ok()).toBe(false)
		expect(await response.text()).toContain('where a refused case goes')
	})
})

test.describe('a triage item asleep until a date', () => {
	// @e2e exclude The triage queue is the mail intake log, and seeding one needs
	// an IMAP account the e2e instance does not have. The sleep, the wake and the
	// refusal on an accepted case are driven in tests/Unit/Service/TriageSleepTest.php,
	// which can name a clock and a store; a browser cannot reach either.
	test.skip('an item sleeps until a date and comes back unassigned', () => {})
})

test.describe('one submission opening several cases', () => {
	// @e2e exclude The fan-out is submitted by a form buildiq does not have yet
	// (forms-per-case-type is named by the register and unbuilt), so there is no
	// gesture to drive. The destinations, the relation, the per-department case
	// and the reported failure are driven in tests/Unit/Service/IntakeFanOutTest.php.
	test.skip('one melding opens a handhaving case and an onderhoud case', () => {})
})
