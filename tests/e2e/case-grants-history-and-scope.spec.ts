/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CGP-05 to REQ-CGP-07: every row of the access panel names the rule
 * behind it, the panel answers who held a right on a past date, and a grant
 * that ends or reaches one area says so.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Everything asserted below arrived
 * in openregister#3744 and #3750, and an instance running an older OpenRegister
 * answers 404 to all of it. The panel then renders, and renders almost nothing,
 * and "this case has no grants" is exactly what an auditor would read as an
 * answer. The unit tests pin what dossiq does with the answer. Only a real
 * request to a real OpenRegister says whether the answer arrives at all.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never recomputes a verdict
 * and compares it to OpenRegister's. That comparison is a second evaluator of
 * the question OpenRegister owns (D-1), written in a test file, and it would
 * eventually disagree with the app and be "fixed" in whichever direction was
 * easier. What it asserts is that the answer arrives with the keys dossiq reads
 * and the spelling OpenRegister gave them.
 *
 * WHY THE EXPIRY CASE ASSERTS A SHAPE AND NOT A REFUSAL. `until` is read at
 * resolution time, so proving a grant stops answering needs a clock this suite
 * does not own. openregister#3750 owns that proof, with a clock fixture. What
 * matters here is that a grant carrying an end comes back carrying it, because
 * a panel that never receives the key can never show it (D-8).
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

/** The object's own access set, as openregister#3744 published it. */
function objectPermissions(id: string): string {
	return `/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${id}/permissions`
}

/** The set as it stood at a moment, from the object's audit trail. */
function accessHistory(id: string, at: string): string {
	return `${objectPermissions(id)}/history?at=${encodeURIComponent(at)}`
}

test.describe('A case answers who holds which right, and who held it then', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''
	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const caseTypeId = (await ensureCaseType(api, token)).id
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} grants history and scope`,
			caseType: caseTypeId,
		})
		caseId = objectId(seeded)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-management::an-auditor-sees-the-rule-not-only-the-holder
	//
	// The endpoint the panel now asks first. Its absence is the silent failure:
	// a 404 leaves the panel on its five-read fallback, which cannot name the
	// level a rule is written at, and nothing says so on screen.
	test('an auditor sees the rule, not only the holder', async () => {
		const answer = await api.get(objectPermissions(caseId))
		expect(
			answer.status(),
			'without this read the panel falls back and can no longer name a rule',
		).toBe(200)

		const set = await answer.json()
		expect(
			set.object,
			'a set that does not name its object cannot be filed as evidence',
		).toBeTruthy()
		expect(Array.isArray(set.holders)).toBe(true)
		expect(Array.isArray(set.denied)).toBe(true)

		// The mode rides with the answer. Below `enforcing` a refusal is
		// recorded and removes nothing, and a panel that showed it as biting
		// would be wrong in the one direction that matters.
		expect(
			['off', 'staging', 'enforcing'],
			'the panel says which mode the instance is in, so the mode has to arrive',
		).toContain(set.denyEnforcement)

		for (const holder of set.holders) {
			expect(
				holder.principal,
				'a holder with no name is not an answer',
			).toBeTruthy()
			expect(Array.isArray(holder.verbs)).toBe(true)
			expect(
				holder.rules?.length,
				`the verbs of "${holder.principal}" arrive with no rule behind them`,
			).toBeGreaterThan(0)

			for (const rule of holder.rules) {
				expect(
					rule.action,
					'a rule that grants no named verb cannot be rendered',
				).toBeTruthy()
				expect(
					['object', 'schema', 'register'],
					`the rule for "${rule.action}" is written at a level the panel cannot label`,
				).toContain(rule.level)
				expect(
					typeof rule.declared,
					'the catalogue standing is what marks a verb as undeclared on the row',
				).toBe('boolean')
			}
		}
	})

	// @e2e case-management::a-reader-without-the-right-to-review-access-is-told-so
	//
	// The refusal is deliberate in OpenRegister: reading the case is one right
	// and enumerating who else can is a second. What this catches is the
	// refusal arriving as something OTHER than 403, because the panel keys its
	// sentence on that status and every other code renders as a failed read.
	test('a reader without the right to review access is told so', async () => {
		const unknown = await api.get(
			objectPermissions('00000000-0000-0000-0000-000000000000'),
		)
		expect(
			[403, 404],
			'an object the caller cannot resolve must not leak its existence',
		).toContain(unknown.status())

		const answer = await api.get(objectPermissions(caseId))
		expect([200, 403]).toContain(answer.status())

		if (answer.status() === 403) {
			const body = await answer.json()
			expect(
				body.message,
				'a refusal with no sentence is the 403 this change exists to retire',
			).toBeTruthy()
		}
	})

	// @e2e case-management::an-auditor-asks-who-could-open-this-dossier-in-march
	//
	// The auditor's actual question. `asOf: null` is a legitimate answer when
	// the trail does not reach back that far, and the panel says so; what it
	// must never do is render that as a date on which nobody held anything.
	test('an auditor asks who could open this dossier in March', async () => {
		const answer = await api.get(accessHistory(caseId, '2026-03-01T23:59:59'))
		expect(
			answer.status(),
			'without the history the panel can only ever answer about today',
		).toBe(200)

		const history = await answer.json()
		expect(history.object).toBeTruthy()
		expect(Array.isArray(history.changes)).toBe(true)
		expect(
			typeof history.entriesRead,
			'a report that cannot say how much trail it read is a report with an unstated limit',
		).toBe('number')

		// `asOf` is either the set that stood then, or null. Anything else and
		// the panel's two states, answered and unanswered, no longer cover the
		// answers it receives.
		if (history.asOf !== null) {
			expect(Array.isArray(history.asOf.holders)).toBe(true)
			expect(
				'setBy' in history.asOf,
				'who set the rights is half of what an auditor came for',
			).toBe(true)
			expect(
				'changedAfterwardsBy' in history.asOf,
				'the rule that took the grant away is the other half',
			).toBe(true)
		}
	})

	// @e2e case-management::a-grant-that-runs-out-names-its-last-day
	//
	// A grant written with an end comes back carrying it, on the row, under the
	// key the panel reads. An OpenRegister that dropped the key would leave the
	// panel showing a permanent grant where a temporary one was written, which
	// is a disclosure nobody would notice.
	test('a grant that runs out names its last day', async () => {
		const ends = '2026-12-31T17:00:00+01:00'
		const written = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${caseId}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: {
					'@self': {
						authorization: {
							read: [{ group: 'waarnemers', until: ends }],
						},
					},
				},
			},
		)

		// An instance that refuses the write has nothing to report, and saying
		// so beats asserting against a case whose block never changed.
		test.skip(
			written.status() >= 400,
			'this caller may not write the case authorization block',
		)

		const answer = await api.get(objectPermissions(caseId))
		expect(answer.status()).toBe(200)

		const set = await answer.json()
		const holder = set.holders?.find(
			(row: any) => row.principal === 'waarnemers',
		)
		expect(
			holder,
			'the grant just written is not in the set that came back',
		).toBeTruthy()

		const rule = holder.rules.find((entry: any) => entry.action === 'read')
		const carried = JSON.stringify(rule?.rule ?? {})
		expect(
			carried,
			'the end was written and did not come back, so no panel can show it',
		).toContain(ends)
	})
})
