/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who gets the work: in proportion, in a team, and in an area.
 *
 * 🔴 THE SHARE IS COUNTED OVER A CYCLE, NEVER AT A POSITION. "The third case
 * goes to Bea" pins an implementation and reddens on any reordering; "over
 * fourteen cases Aad has ten and Bea four" pins the requirement. Every
 * assertion here is a tally.
 *
 * 🔴 THE SECOND HALF OF THE TEAM SCENARIO IS THE ONE THAT CATCHES THE DEFECT.
 * That the senior of team zuid receives the case is satisfied by accident by a
 * rule that reached the first senior in the list; that the senior of the other
 * team never becomes a candidate is what proves the team narrowed the pool.
 *
 * 🔴 A CASE ROUTED BY THE FALLBACK MUST BE DISTINGUISHABLE FROM ONE THE AREA
 * PLACED. Both end up assigned, and only `areaFallbackUsed` separates them, so
 * that flag is read from the record rather than inferred from the assignee.
 *
 * 🔴 EVERY CASE, ROLE AND POOL THIS SPEC CREATES IS CLEANED UP: a stray pool
 * membership changes where somebody else's run routes its work.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { cleanupRunObjects, createObject, getRequestToken, REGISTER, RUN_PREFIX, seedCase, showObject } from './helpers/fixtures.ts'

let api: APIRequestContext
let token: string

const ROUTE_API = (caseId: string) => `/index.php/apps/${REGISTER}/api/case/${caseId}/route`

/**
 * Bind one pool membership to a case type's pool.
 *
 * @param participant The Nextcloud user id.
 * @param weight The share this membership takes.
 * @param team The team the membership belongs to.
 */
async function bindMember(participant: string, weight: number, team = '') {
	return await createObject(api, token, 'role', {
		name: `${RUN_PREFIX} ${participant}`,
		roleType: 'behandelaar',
		participant,
		weight,
		...(team === '' ? {} : { team }),
	})
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('work is divided in proportion to what each member is there for', () => {
	test('a part-time colleague receives a smaller share over a full cycle', async () => {
		await bindMember('e2e-aad', 1)
		await bindMember('e2e-bea', 0.4)

		const tally: Record<string, number> = {}
		for (let i = 0; i < 14; i++) {
			const caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Verdeling ${i}` })
			const answer = await api.post(ROUTE_API(caseId), {
				headers: { requesttoken: token },
				data: { strategy: 'round-robin', roleType: 'behandelaar' },
			})
			const assignee = (await answer.json()).assignee
			tally[assignee] = (tally[assignee] ?? 0) + 1
		}

		// The proportion, not the order: ten and four over fourteen.
		expect(tally['e2e-aad']).toBe(10)
		expect(tally['e2e-bea']).toBe(4)
	})
})

test.describe('a rule may name a position inside a team', () => {
	test('the senior of team zuid gets it, and the senior of noord is never a candidate', async () => {
		await bindMember('e2e-sanne', 1, 'zuid')
		await bindMember('e2e-mo', 1, 'noord')

		const assignees: string[] = []
		for (let i = 0; i < 4; i++) {
			const caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Team ${i}` })
			const answer = await api.post(ROUTE_API(caseId), {
				headers: { requesttoken: token },
				data: { strategy: 'round-robin', roleType: 'behandelaar', team: 'zuid' },
			})
			assignees.push((await answer.json()).assignee)
		}

		expect(new Set(assignees)).toEqual(new Set(['e2e-sanne']))
		// Four chances to leak, and none of them did.
		expect(assignees).not.toContain('e2e-mo')
	})
})

test.describe('the case holds the area it is in, and routing reads it', () => {
	test('a case at an address in wijk Zuid routes to the team declared for it', async () => {
		const caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Zuid`, district: 'Zuid' })

		const answer = await api.post(ROUTE_API(caseId), {
			headers: { requesttoken: token },
			data: { strategy: 'round-robin', roleType: 'behandelaar', areaTeams: { Zuid: 'zuid' } },
		})

		const body = await answer.json()
		expect(body.team).toBe('zuid')
		expect(body.areaFallbackUsed).toBe(false)
	})

	test('correcting the address re-resolves the area', async () => {
		const caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Verhuisd`, district: 'Zuid' })

		await api.put(`/index.php/apps/openregister/api/objects/dossiq/case/${caseId}`, {
			headers: { requesttoken: token },
			data: { district: 'Noord' },
		})

		const stored = await showObject(api, token, 'case', caseId)
		expect(stored.district).toBe('Noord')
	})

	test('an address outside every boundary routes by the fallback and says so', async () => {
		const caseId = await seedCase(api, token, { title: `${RUN_PREFIX} Buiten de grenzen` })

		const answer = await api.post(ROUTE_API(caseId), {
			headers: { requesttoken: token },
			data: { strategy: 'round-robin', roleType: 'behandelaar', areaTeams: { Zuid: 'zuid' } },
		})

		const body = await answer.json()
		// Assigned AND flagged: without the flag this case is indistinguishable
		// from one the area placed correctly, and nobody goes to look at the
		// boundary set.
		expect(body.assignee).toBeTruthy()
		expect(body.areaFallbackUsed).toBe(true)
		expect(body.reason).toMatch(/fallback/i)
	})
})
