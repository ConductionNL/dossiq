/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CGP-01 to REQ-CGP-04: a case says who holds which right and where each
 * grant came from, a refusal names the rule that refused it, case-type rights
 * are declared per department, role and confidentiality, and a group of case
 * types is granted once.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Every assertion below crosses the
 * seam between dossiq and OpenRegister, and that seam is where this feature can
 * fail silently in the one way that matters: dossiq renders an access panel
 * built from four OpenRegister reads, and an OpenRegister that predates
 * openregister#3726 answers 404 to three of them. The panel then renders, and
 * renders almost nothing, and "this case has no grants" is exactly what an
 * auditor would read as an answer. Only a real request to a real OpenRegister
 * shows which endpoints actually answer.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never asserts that a
 * particular principal was granted or refused a particular verb by comparing
 * the panel's arithmetic to its own. That comparison would be a second
 * evaluator of the question OpenRegister owns (D-1), written in a test file,
 * and it would eventually disagree with the app and be "fixed" in whichever
 * direction was easier. What it asserts is that OpenRegister's answer is the
 * one that arrives: the verdict, the source and the rule come back with the
 * spelling OpenRegister gave them.
 *
 * WHAT IT NEEDS FROM THE INSTANCE. The deny half ships staged by default
 * (decision D15, `openregister.deny_enforcement` = `staging`). The suite reads
 * the mode from OpenRegister rather than assuming it, because a run against an
 * instance set to `enforcing` sees an absence where a staging run sees a grant
 * with a `stagedDeny` beside it, and an assertion that hard-coded one of the
 * two would fail on a correctly configured instance.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** The register cases live in. */
const REGISTER = 'dossiq'

/** The schema a case is an object of. */
const SCHEMA = 'case'

/** OpenRegister's permission endpoints, as openregister#3726 published them. */
const PERMISSIONS = '/index.php/apps/openregister/api/permissions'
const SCOPES = '/index.php/apps/openregister/api/scopes'

test.describe('A case names the source of every grant on it', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''
	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} grants name their source`,
			caseType: caseTypeId,
		})
		caseId = objectId(seeded)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-management::an-auditor-asks-who-could-open-this-dossier
	//
	// The panel is built from four reads and this asserts the two that carry
	// the holder and the source. A read that 404s is the failure this catches:
	// the panel would render, and render an empty table, which an auditor reads
	// as "nobody holds a right on this dossier".
	// @e2e case-access-control::an-auditor-reads-who-could-open-this-dossier
	test('an auditor asks who could open this dossier, and each holder is listed with its source', async () => {
		const catalogue = await api.get(PERMISSIONS)
		expect(
			catalogue.status(),
			'the grantable set must be published, or a right renders as the bare word "update"',
		).toBe(200)

		const published = await catalogue.json()
		expect(Array.isArray(published.permissions)).toBe(true)
		expect(
			published.permissions.length,
			'an empty catalogue and a missing catalogue render identically',
		).toBeGreaterThan(0)
		for (const permission of published.permissions) {
			expect(
				permission.action,
				'a verb with no name cannot be shown to a person',
			).toBeTruthy()
			expect(
				permission.description,
				'a verb with no sentence is not an answer to an auditor',
			).toBeTruthy()
		}

		const scopes = await api.get(
			`${SCOPES}?register=${REGISTER}&schema=${SCHEMA}`,
		)
		expect(scopes.status()).toBe(200)

		const body = await scopes.json()
		const entry = body.scopes?.find((row: any) => row.schema === SCHEMA)
		expect(
			entry,
			"the case schema must appear in the caller's own scopes",
		).toBeTruthy()

		// `actions` keeps its old shape and `provenance` sits BESIDE it. A
		// build that moved provenance inside `actions` would break every
		// feature gate in the fleet, and this is where that shows.
		expect(Array.isArray(entry.actions)).toBe(true)
		expect(
			entry.provenance,
			'a grant with no provenance is a grant nobody can trace',
		).toBeTruthy()

		for (const [action, record] of Object.entries<any>(entry.provenance)) {
			expect(
				record.source,
				`the answer for "${action}" names no source, so the grant cannot be traced to a rule`,
			).toBeTruthy()
			expect(typeof record.granted).toBe('boolean')
		}
	})

	// @e2e case-management::a-handler-is-told-why-not-just-no
	//
	// The rule behind a refusal, read from OpenRegister rather than inferred
	// from a status code. A 403 with no body is the thing this requirement
	// exists to retire, so the assertion is on the NAMED rule and never on the
	// status alone.
	test('a handler is told why, not just no', async () => {
		const scopes = await api.get(
			`${SCOPES}?register=${REGISTER}&schema=${SCHEMA}`,
		)
		const body = await scopes.json()
		const entry = body.scopes?.find((row: any) => row.schema === SCHEMA)

		const refused = Object.entries<any>(entry.provenance ?? {}).filter(
			([, record]) => record.granted === false,
		)

		// A run as an account that holds everything refuses nothing, and that
		// is a legitimate state of the instance rather than a failure. The
		// assertion is conditional on there being a refusal to read, and the
		// unconditional half is above: every record names a source.
		for (const [action, record] of refused) {
			expect(
				record.source,
				`the refusal of "${action}" names no rule, which is the bare 403 this requirement retires`,
			).toBeTruthy()
			if (record.source === 'deny') {
				expect(
					record.deny,
					'a refusal whose source is a deny must carry the deny that caused it',
				).toBeTruthy()
			}
		}
	})

	// @e2e case-management::the-action-list-still-answers-up-front
	//
	// `CaseActionProvider` keeps answering which moves the caller may take,
	// which is what stops the UI guessing and the user discovering a refusal by
	// clicking. The regression this guards is the one this change could
	// plausibly cause: reading OpenRegister's grants beside dossiq's guards
	// must not make the provider throw, and a 502 here renders every stage
	// disabled with no message.
	test('the action list still answers up front', async () => {
		const actions = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${caseId}/available-actions`,
		)

		expect(
			actions.status(),
			'a provider that throws answers 502 and the timeline renders every stage disabled',
		).toBeLessThan(500)

		if (actions.status() === 200) {
			const published = await actions.json()
			const list = published.actions ?? published.results ?? published
			expect(Array.isArray(list)).toBe(true)
		}
	})

	// @e2e case-management::a-department-may-see-the-ordinary-cases-and-not-the-confidential-ones
	//
	// The declaration, asserted where it is declared. The ENFORCEMENT of it is
	// OpenRegister's (D22: the condition is compiled into the query), so this
	// asserts that a case type can carry the three-axis row at all and that the
	// axis survives a save. A row that the schema silently drops would leave
	// the editor showing a matrix the store never kept.
	// @e2e case-access-control::a-case-type-grants-per-department-role-and-confidentiality
	test('a department may see the ordinary cases and not the confidential ones', async () => {
		const updated = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${caseTypeId}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					rightsMatrix: [
						{
							department: `${RUN_PREFIX} Vergunningen`,
							role: 'behandelaar',
							confidentiality: 'openbaar',
							actions: ['read', 'list'],
						},
					],
				},
			},
		)

		expect(updated.status(), await updated.text()).toBeLessThan(300)

		const stored = await updated.json()
		const row = stored.rightsMatrix?.[0]
		expect(
			row,
			'a rights matrix the store dropped leaves an editor showing nothing real',
		).toBeTruthy()
		expect(row.confidentiality).toBe('openbaar')
		expect(
			row.role,
			'a role name carrying a level is the combinatorial explosion the axis exists to prevent',
		).not.toMatch(/openbaar|vertrouwelijk|geheim|intern/i)
	})

	// @e2e case-management::a-samenwerkingsverband-grants-once
	// @e2e case-management::adding-a-case-type-to-the-group-needs-no-second-grant
	//
	// Both scenarios ride one test because they are one mechanism: the group is
	// the unit of the grant, so a type that joins it later is covered by the
	// same grant and no second act exists to assert. The inheritance itself is
	// OpenRegister's `rbac-inherits-to-children`; what dossiq owns, and what is
	// asserted here, is that the membership is declarable and stored.
	test('a samenwerkingsverband grants once, and a later member needs no second grant', async () => {
		const group = await api.post(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseTypeGroup`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					groupName: `${RUN_PREFIX} Samenwerkingsverband Noord`,
					partner: `${RUN_PREFIX} Noord`,
				},
			},
		)

		expect(
			group.status(),
			`the caseTypeGroup schema must exist on the instance: ${await group.text()}`,
		).toBeLessThan(300)
		const groupId = objectId(await group.json())

		const joined = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${caseTypeId}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { caseTypeGroup: groupId },
			},
		)

		expect(joined.status(), await joined.text()).toBeLessThan(300)
		const stored = await joined.json()
		expect(
			stored.caseTypeGroup,
			'a membership the store dropped means every type needs its own grant again',
		).toBeTruthy()

		// No second grant is asserted because none is made. The control is that
		// nothing on the TYPE carries a copy of the group's rights: a copied
		// grant is the drift this requirement exists to end.
		expect(
			JSON.stringify(stored.rightsMatrix ?? []),
			'a group grant copied onto the type is a copy that nobody updates',
		).not.toContain(groupId)
	})
})
