/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A running case is rebound to another case type, with a mapping and a reason.
 *
 * WHY THIS NEEDS A REAL STORE. `CaseRebindServiceTest` proves each rule to the
 * sentence: the missing required property is named, the engine's refusal writes
 * nothing, a handler is refused 403, and the happy path keeps the case number.
 * `TermijnRearmTest` proves the fixture pair, that a 56-day term extended by 14
 * and rebound to an 84-day definition ends on its own start plus 98 days. What
 * neither can show is that the pieces MEET: that `requiredAtStatus` survives
 * the register as a value OpenRegister stores and this app can read back, that
 * the route is reachable at all, and that a case rebound through the endpoint
 * comes back on the other case type carrying the SAME number. Each of those
 * seams sits between two apps.
 *
 * 🔴 THE REBIND IS PERFORMED THROUGH THE ENDPOINT, NEVER BY PATCHING
 * `caseType`. Patching the field would leave the status pointing at the old
 * type's statusType, which is the exact broken state the act exists to prevent,
 * and every assertion below would still be green.
 *
 * 🔴 THE REFUSAL IS PROBED AS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE
 * REFUSED, which is an authenticated account that is not in
 * `dossiq-coordinators`. Probing as the admin proves almost nothing: a
 * superuser success is compatible with there being no check at all.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded.
 */

import { expect, test } from '@playwright/test'
import { ensureUser, provisioningContext } from './helpers/auth.ts'
import {
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'

/** The type the case was filed under by mistake. */
let kapvergunning = ''

/** The type it should have been filed under. */
let omgevingsvergunning = ''

/** The status the case sits in, on the wrong type. */
let kapInBehandeling = ''

/**
 * The target's status the case is mapped onto. Deliberately NOT the one that
 * shares a name with the source's, so a rebind that quietly matched by name
 * would land somewhere this test can see.
 */
let omgToetsing = ''

/** The target's own "In behandeling", which exists only to share that name. */
let omgInBehandeling = ''

/** The case being rebound, and the number that must survive it. */
let runningCase = ''
let caseNumber = ''

/** An authenticated account that is not a case coordinator. */
const PLAIN_USER = process.env.E2E_USER_NAME || 'e2euser'
const PLAIN_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

test.describe('A coordinator rebinds a running case to the right case type', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		kapvergunning = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} kapvergunning`,
				identifier: `${RUN_PREFIX.toLowerCase()}-kap`,
				description: 'Throwaway caseType for the rebind e2e layer.',
				processingDeadline: 'P56D',
				isDraft: false,
				version: 1,
			}),
		)

		omgevingsvergunning = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} omgevingsvergunning`,
				identifier: `${RUN_PREFIX.toLowerCase()}-omg`,
				description: 'Throwaway caseType for the rebind e2e layer.',
				processingDeadline: 'P84D',
				isDraft: false,
				version: 1,
			}),
		)

		kapInBehandeling = objectId(
			await createObject(api, token, 'statusType', {
				name: 'In behandeling',
				caseType: kapvergunning,
				order: 1,
			}),
		)

		omgInBehandeling = objectId(
			await createObject(api, token, 'statusType', {
				name: 'In behandeling',
				caseType: omgevingsvergunning,
				order: 1,
			}),
		)

		omgToetsing = objectId(
			await createObject(api, token, 'statusType', {
				name: 'Toetsing',
				caseType: omgevingsvergunning,
				order: 2,
			}),
		)

		// The property that makes the mapping cost something: the target asks
		// for it in Toetsing, and the case filed as a kapvergunning has never
		// been asked for it.
		await createObject(api, token, 'propertyDefinition', {
			name: 'bouwjaar',
			caseType: omgevingsvergunning,
			requiredAtStatus: omgToetsing,
		})

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} verkeerd ingeboekte zaak`,
			caseType: kapvergunning,
			status: kapInBehandeling,
		})
		runningCase = objectId(seeded)
		caseNumber = String(seeded.caseNumber ?? seeded.identificatie ?? '')
	})

	/**
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	test('the other case type is offered, and its statuses are asked for', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const response = await api.get(
			`/index.php/apps/dossiq/api/case/${runningCase}/rebind?target=${omgevingsvergunning}`,
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const body = await response.json()
		expect(body.current.caseType).toBe(kapvergunning)
		expect(body.targets.map((entry: any) => entry.id)).toContain(
			omgevingsvergunning,
		)

		// Both of the target's statuses are offered and NONE is chosen: across
		// two case types a shared name is a coincidence, not a mapping.
		const offered = body.preview.statuses.map((entry: any) => entry.id)
		expect(offered).toContain(omgInBehandeling)
		expect(offered).toContain(omgToetsing)
		expect(body.preview.canRebind, 'with no status picked, nothing may run').toBe(
			false,
		)
		expect(body.preview.run.moved, 'the engine run does not move yet').toBe(
			false,
		)
	})

	/**
	 * 🔴 `requiredAtStatus` has to survive the register for this to be real.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	test('the missing property the target requires is named before anything moves', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const preview = await api.get(
			`/index.php/apps/dossiq/api/case/${runningCase}/rebind`
				+ `?target=${omgevingsvergunning}&status=${omgToetsing}`,
		)
		expect(preview.ok(), await preview.text()).toBeTruthy()

		const body = await preview.json()
		expect(body.preview.missingProperties).toContain('bouwjaar')
		expect(body.preview.canRebind).toBe(false)

		// And the write agrees with the preview, rather than the dialog being
		// the only thing standing in the way.
		const refused = await api.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/rebind`,
			{
				headers: { requesttoken: token },
				data: {
					target: omgevingsvergunning,
					status: omgToetsing,
					reason: 'Verkeerd ingeboekt bij intake',
				},
			},
		)
		expect(refused.status()).toBe(422)
		expect((await refused.json()).message).toContain('bouwjaar')

		// Nothing moved: the case is still a kapvergunning.
		const unchanged = await showObject(api, 'case', runningCase)
		expect(unchanged.caseType).toBe(kapvergunning)
		expect(unchanged.status).toBe(kapInBehandeling)
	})

	/**
	 * 🔴 A handler outside `dossiq-coordinators` is refused, naming the rule.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	test('a handler outside the coordinators is refused by the endpoint', async ({
		playwright,
		baseURL,
	}) => {
		const admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', PLAIN_USER, PLAIN_PASS)

		const basic = Buffer.from(`${PLAIN_USER}:${PLAIN_PASS}`).toString('base64')
		const asHandler = await playwright.request.newContext({
			baseURL,
			// An explicit empty jar, or the admin's captured session rides along
			// and the request is answered as the admin. That would turn this
			// test into one that can only pass.
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${basic}`,
				'OCS-APIRequest': 'true',
			},
		})

		const permission = await asHandler.get(
			'/index.php/apps/dossiq/api/rebind/permission',
		)
		expect(permission.ok(), await permission.text()).toBeTruthy()
		expect((await permission.json()).mayRebind).toBe(false)

		const refused = await asHandler.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/rebind`,
			{
				data: {
					target: omgevingsvergunning,
					status: omgInBehandeling,
					reason: 'Ik vind van wel',
				},
			},
		)
		expect(refused.status()).toBe(403)
		expect(String((await refused.json()).error)).toMatch(
			/rebind-is-for-coordinators|case-access-denied/,
		)

		// And the case is untouched by the attempt.
		const api = await playwright.request.newContext({ baseURL })
		expect((await showObject(api, 'case', runningCase)).caseType).toBe(
			kapvergunning,
		)
	})

	/**
	 * 🔴 The point of the whole change: the type moves and the number does not.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md#requirement-a-coordinator-may-rebind-a-running-case-with-a-mapping-and-a-reason-req-zv-07
	 */
	test('the case is rebound with its answers, and keeps its number', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const response = await api.post(
			`/index.php/apps/dossiq/api/case/${runningCase}/rebind`,
			{
				headers: { requesttoken: token },
				data: {
					target: omgevingsvergunning,
					status: omgToetsing,
					reason: 'Verkeerd ingeboekt bij intake',
					properties: { bouwjaar: '1974' },
				},
			},
		)
		expect(response.ok(), await response.text()).toBeTruthy()

		const body = await response.json()
		expect(body.rebound).toBe(true)
		expect(body.from).toBe(kapvergunning)
		expect(body.to).toBe(omgevingsvergunning)

		const rebound = await showObject(api, 'case', runningCase)
		expect(rebound.caseType).toBe(omgevingsvergunning)
		expect(rebound.status).toBe(omgToetsing)
		if (caseNumber !== '') {
			expect(
				String(rebound.caseNumber ?? rebound.identificatie ?? ''),
				'a rebind is not a refile, so the number stays',
			).toBe(caseNumber)
		}

		// The journal carries both bindings and the reason, so the next person
		// reading this case does not have to dig through the store to find out
		// what it used to be.
		const activity = JSON.parse(String(rebound.activity ?? '[]'))
		const entry = activity.find(
			(row: any) => row.type === 'case-type-rebind',
		)
		expect(entry, 'the rebind is on the journal').toBeTruthy()
		expect(entry.fromCaseType).toBe(kapvergunning)
		expect(entry.toCaseType).toBe(omgevingsvergunning)
		expect(entry.reason).toBe('Verkeerd ingeboekt bij intake')
	})
})
