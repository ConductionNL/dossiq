/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A family plan with goals and interventions, and a lookup that needs a ground.
 *
 * WHY THIS NEEDS A REAL REGISTER. The rules are unit-tested to the sentence:
 * `CasePlanGoalTest` proves a goal naming nothing measurable is refused,
 * `InterventionProviderTest` proves a typed provider name is refused and an
 * overdue intervention is one whose target date passed while it was still open,
 * `CasePlanReviewTest` proves a plan with no review date is not stale,
 * `CasePlanStringMigrationTest` proves six strings become six records with
 * nothing invented, and `CrossDomainExistenceTest` proves the answer carries
 * exactly three keys, with the mutation that adds a case number reddening it.
 * All of those run against a fake.
 *
 * What none of them can show is that the two NEW SCHEMAS exist in OpenRegister
 * at all. `casePlanGoal` and `intervention` are added to
 * `register.d/50-sociaal-domein.json` by this change, and an import that did
 * not pick them up gives a 404 no unit test can see. Nor can they show that
 * `jeugdigeBsn` is a value the store will filter on, or that
 * `sociaalDomeinAuditLog` accepts `subjectBsn` and `returned`: OpenRegister
 * DROPS an undeclared property in silence, so a log row that looks written can
 * come back without the two fields the whole subject-access answer depends on.
 * Every one of those seams sits between two apps.
 *
 * 🔴 THE LOOKUP IS MADE THROUGH THE ENDPOINT, NEVER BY READING THE DOMAIN
 * REGISTERS DIRECTLY. Reading them here would test the fixture, not the
 * projection, and the projection is the thing that must not leak.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded.
 */

import { expect, test } from '@playwright/test'
import {
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
} from './helpers/fixtures.ts'

/** The household this whole spec is about. */
const BSN = '999993653'

/** The family plan under test. */
let planId = ''

/** Its two goals. */
let goalSchool = ''
let goalRust = ''

/** The provider every intervention names, as a contact reference. */
const PROVIDER = `user:${RUN_PREFIX.toLowerCase()}-jeugdzorg`

/** Yesterday and next year, so "overdue" is not a function of the clock. */
const YESTERDAY = new Date(Date.now() - 86400000).toISOString().slice(0, 10)
const NEXT_YEAR = new Date(Date.now() + 365 * 86400000)
	.toISOString()
	.slice(0, 10)

function planUrl (id: string) {
  return `/index.php/apps/dossiq/api/family-plans/${encodeURIComponent(id)}`
}

test.describe('The family plan, and the ground a cross-domain lookup runs on', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		planId = objectId(
			await createObject(api, token, 'gezinsplan', {
				caseId: `${RUN_PREFIX}-case`,
				preparedBy: 'admin',
				// Last month, so the plan is stale and says so.
				reviewDate: YESTERDAY,
			}),
		)

		// An open Jeugdwet case for the same household, under the key that is
		// NOT `bsn`. A lookup filtering on `bsn` finds nothing here and reads
		// as "no other domain knows this household", which is the one wrong
		// answer the projection must never give.
		await createObject(api, token, 'jeugdwetZaak', {
			caseNumber: `${RUN_PREFIX}-JW-1`,
			jeugdigeBsn: BSN,
			status: 'support-loopt',
			handlerId: 'bram',
			supportRequest: 'Vader vraagt om begeleiding',
		})
	})

	/**
	 * 🔴 The two new schemas have to exist in the register for any of this.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	test('a plan carries two goals and three interventions, one goal with two', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const school = await api.post(`${planUrl(planId)}/goals`, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} Sem gaat weer naar school`,
				metWhen: 'Vier weken achtereen minstens vier dagen per week op school',
			},
		})
		expect(school.ok(), await school.text()).toBeTruthy()
		goalSchool = objectId(await school.json())

		const rust = await api.post(`${planUrl(planId)}/goals`, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} Rust in huis`,
				metWhen: 'Geen politiemeldingen in drie maanden',
			},
		})
		expect(rust.ok(), await rust.text()).toBeTruthy()
		goalRust = objectId(await rust.json())

		// TWO under one goal, which is what a real plan looks like, and one
		// under the other.
		for (const [goal, title, targetDate] of [
			[goalSchool, 'Ambulante begeleiding', NEXT_YEAR],
			[goalSchool, 'Weerbaarheidstraining', NEXT_YEAR],
			[goalRust, 'Gezinscoach', NEXT_YEAR],
		] as const) {
			const created = await api.post(`${planUrl(planId)}/interventions`, {
				headers: { requesttoken: token },
				data: {
					goal,
					title: `${RUN_PREFIX} ${title}`,
					provider: PROVIDER,
					providerName: 'Jeugdzorg Midden',
					targetDate,
				},
			})
			expect(created.ok(), await created.text()).toBeTruthy()
		}

		const read = await api.get(planUrl(planId))
		expect(read.ok(), await read.text()).toBeTruthy()

		const body = await read.json()
		expect(body.goals).toHaveLength(2)
		expect(body.interventions).toHaveLength(3)
		expect(
			body.interventions.filter((item: any) => item.goal === goalSchool),
		).toHaveLength(2)
		for (const item of body.interventions) {
			expect(item.provider, 'the provider is the reference, not the name').toBe(
				PROVIDER,
			)
			expect(item.targetDate).toBe(NEXT_YEAR)
		}
	})

	/**
	 * A typed provider name is refused by the endpoint, not only by the form.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	test('a typed provider name is refused on the wire', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const refused = await api.post(`${planUrl(planId)}/interventions`, {
			headers: { requesttoken: token },
			data: {
				title: `${RUN_PREFIX} Getypte aanbieder`,
				provider: 'Jeugdzorg Midden',
			},
		})

		expect(refused.status()).toBe(422)
		expect((await refused.json()).error).toBe('provider-is-not-a-party')
	})

	/**
	 * 🔴 Overdue is computed against today, on a date this test controls.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-case-plan-holds-interventions-with-a-goal-a-provider-and-dates-req-cpn-01
	 */
	test('an intervention past its target date reads as overdue', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const late = await api.post(`${planUrl(planId)}/interventions`, {
			headers: { requesttoken: token },
			data: {
				goal: goalRust,
				title: `${RUN_PREFIX} Te late interventie`,
				provider: PROVIDER,
				targetDate: YESTERDAY,
				state: 'running',
			},
		})
		expect(late.ok(), await late.text()).toBeTruthy()
		const lateId = objectId(await late.json())

		const body = await (await api.get(planUrl(planId))).json()
		const read = body.interventions.find((item: any) => item.id === lateId)

		expect(read.overdue).toBe(true)
		// The control: the ones due next year are not overdue, so `overdue`
		// is not simply true on every row.
		expect(
			body.interventions.filter((item: any) => item.overdue === true),
		).toHaveLength(1)
	})

	/**
	 * A plan whose review date passed says it is due for review.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md#requirement-a-plan-is-reviewed-and-the-review-is-recorded-req-cpn-03
	 */
	test('a stale plan says it is stale, and a review clears it', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		expect((await (await api.get(planUrl(planId))).json()).dueForReview).toBe(
			true,
		)

		const reviewed = await api.post(`${planUrl(planId)}/reviews`, {
			headers: { requesttoken: token },
			data: {
				changes: ['Doel Rust in huis herzien', 'Gezinscoach toegevoegd'],
				nextReviewDate: NEXT_YEAR,
			},
		})
		expect(reviewed.ok(), await reviewed.text()).toBeTruthy()

		const after = await (await api.get(planUrl(planId))).json()
		expect(after.dueForReview).toBe(false)
		expect(after.plan.reviews).toHaveLength(1)
		expect(after.plan.reviews[0].changes).toHaveLength(2)
	})

	/**
	 * 🔴 No ground, no answer.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	test('a lookup with no ground is refused, naming what is missing', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const refused = await api.post(
			'/index.php/apps/dossiq/api/cross-domain/lookup',
			{
				headers: { requesttoken: token },
				data: { bsn: BSN, domain: 'Wmo' },
			},
		)

		expect(refused.status()).toBe(422)
		const body = await refused.json()
		expect(body.error).toBe('lookup-needs-a-ground')
		expect(String(body.message)).toContain('ground')
	})

	/**
	 * 🔴 The answer is three fields, and the Jeugdwet case is found at all.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	test('a consulent learns the household is known to Jeugdwet, and nothing else', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const answered = await api.post(
			'/index.php/apps/dossiq/api/cross-domain/lookup',
			{
				headers: { requesttoken: token },
				data: { bsn: BSN, domain: 'Wmo', ground: 'wettelijke-taak' },
			},
		)
		expect(answered.ok(), await answered.text()).toBeTruthy()

		const found = (await answered.json()).found
		expect(found).toHaveLength(1)
		expect(found[0].domain).toBe('Jeugdwet')
		expect(found[0].contact).toBe('bram')
		expect(Object.keys(found[0]).sort()).toEqual([
			'contact',
			'domain',
			'exists',
		])

		// Said the other way round, because the key assertion above would pass
		// on a projection that renamed a leak into one of those three.
		const raw = JSON.stringify(found)
		expect(raw).not.toContain('Vader vraagt')
		expect(raw).not.toContain('support-loopt')
		expect(raw).not.toContain(`${RUN_PREFIX}-JW-1`)
	})

	/**
	 * 🔴 The person can be told what was looked up about them, with the grounds.
	 *
	 * This is the field that was declared and never written. A log row that
	 * came back without `subjectBsn` or `authorisationGround` would mean
	 * OpenRegister dropped the properties this change added, which is the seam
	 * only a live register can show.
	 *
	 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-the-lookup-requires-a-ground-chosen-first-and-logged-req-xdv-02
	 */
	test('both lookups come back with their grounds and their dates', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await api.post('/index.php/apps/dossiq/api/cross-domain/lookup', {
			headers: { requesttoken: token },
			data: { bsn: BSN, domain: 'Participatiewet', ground: 'toestemming' },
		})

		const about = await api.get(
			`/index.php/apps/dossiq/api/cross-domain/lookups/${BSN}`,
		)
		expect(about.ok(), await about.text()).toBeTruthy()

		const lookups = (await about.json()).lookups
		expect(lookups.length).toBeGreaterThanOrEqual(2)
		for (const lookup of lookups) {
			expect(
				lookup.ground,
				'authorisationGround was declared and unread before this change',
			).not.toBe('')
			expect(lookup.moment).not.toBe('')
			expect(lookup.requester).not.toBe('')
		}
		expect(lookups.map((entry: any) => entry.ground)).toContain('toestemming')
	})
})
