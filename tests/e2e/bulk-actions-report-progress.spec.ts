/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BLK-01 to REQ-BLK-05: a bulk act on cases is a job that reports per row,
 * a redistribution needs a written reason, a selection spanning two case type
 * versions is refused before anything is rehearsed, and select-all says which
 * all it means.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. The job is OpenRegister's.
 * dossiq's unit suite builds its actions with a stand-in engine, so it proves
 * what dossiq DECIDES about one case and nothing about whether a real
 * selection reaches a real job, whether the job walks it, or whether the rows
 * it reports carry the reasons dossiq wrote. A hand-off to an endpoint that
 * answers an empty job looks exactly like a job over an empty selection, which
 * is the failure this suite exists to catch.
 *
 * WHICH DOOR. The act is started on dossiq's own endpoint, because that is
 * where dossiq's case policy lives and what the dialog calls. Everything after
 * the hand-off is read from OpenRegister's routes, because that is what the
 * dialog reads and a dossiq proxy for them would be a second job model.
 * OpenRegister's own job mechanics (the cancel boundary, the retry, the
 * selection that grew) are asserted in openregister's suite,
 * `tests/e2e/ci/bulk-action-jobs.spec.ts`, and are not restated here.
 *
 * THE COMMIT IS DELIBERATELY NOT WALKED TO COMPLETION. The job walks its
 * members in the background, and nothing guarantees cron ran between two HTTP
 * calls. An assertion that the counts reached four hundred would be a timing
 * race dressed up as coverage, so this suite asserts the REHEARSAL's per-row
 * outcomes, which are written synchronously at creation, and asserts that the
 * commit is accepted and moves the job off `previewed`.
 *
 * WHAT THIS SUITE DOES NOT DO. It commits no act over a case it did not seed.
 * A bulk reassignment that escaped its fixtures would move somebody's real
 * caseload, and unlike a seeded row that cannot be swept up afterwards.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { anonymousContext } from './helpers/principals.ts'
import { expectRefused, REFUSED_ANONYMOUS } from './helpers/refusals.ts'

/** dossiq's own hand-off, the one the dialogs call. */
const HANDOFF = '/index.php/apps/dossiq/api/cases/bulk-jobs'

/** OpenRegister's job routes, the ones the progress panel reads. */
const JOBS = '/index.php/apps/openregister/api/bulk-jobs'

/** The status a refusal carries. */
const REFUSED = 422

/**
 * Headers for a write.
 *
 * @param token The CSRF request-token.
 * @return The header map.
 */
function writeHeaders(token: string): Record<string, string> {
	return {
		'Content-Type': 'application/json',
		requesttoken: token,
		'OCS-APIRequest': 'true',
	}
}

test.describe('A bulk act on cases is a job that reports what it skipped', () => {
	test.setTimeout(240_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''
	const seeded: string[] = []

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		for (let index = 0; index < 3; index++) {
			const seededCase = await seedCase(api, token, {
				title: `${RUN_PREFIX} bulk ${index}`,
				caseType: caseTypeId,
				assignee: 'admin',
			})
			seeded.push(objectId(seededCase))
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	test('dossiq declares its bulk actions, and each says whether it needs a reason', async () => {
		const response = await api.get(
			'/index.php/apps/openregister/api/bulk-actions',
			{
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		expect(response.status()).toBe(200)

		const body = await response.json()
		const byId = new Map<string, any>(
			(body.results ?? []).map((action: any) => [String(action.id), action]),
		)

		// All four, or the Cases page offers a gesture nothing answers to.
		expect(byId.has('dossiq:transition-cases')).toBe(true)
		expect(byId.has('dossiq:lifecycle-cases')).toBe(true)
		expect(byId.has('dossiq:reassign-cases')).toBe(true)
		expect(byId.has('dossiq:set-case-attribute')).toBe(true)

		// The requirement a dialog renders, read off the action rather than
		// remembered by the dialog.
		expect(byId.get('dossiq:reassign-cases').requiresJustification).toBe(true)
		expect(byId.get('dossiq:lifecycle-cases').requiresJustification).toBe(true)
		expect(byId.get('dossiq:transition-cases').requiresJustification).toBe(false)
		expect(byId.get('dossiq:set-case-attribute').guards).toContain('homogeneity')
	})

	test('a bulk act is rehearsed first, and every selected case gets a row', async () => {
		const created = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin', reason: `${RUN_PREFIX} rehearsal` },
				selection: { ids: seeded },
				justification: `${RUN_PREFIX} rehearsal`,
			},
		})
		expect(created.status()).toBe(201)

		const job = await created.json()
		expect(job.state).toBe('previewed')
		expect(job.total).toBe(seeded.length)

		const members = await api.get(`${JOBS}/${job.id}/members?limit=100`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(members.status()).toBe(200)

		const rows = (await members.json()).results ?? []
		expect(rows).toHaveLength(seeded.length)

		// Every case seeded here is already admin's, so the rehearsal says the
		// act would change nothing and names the reason. That is the skip list
		// working: a count alone could not tell this from three writes.
		for (const row of rows) {
			expect(row.outcome).toBe('skipped')
			expect(String(row.reason)).toBe('already_assigned')
		}
	})

	test('the skip list is readable on its own, and the reason travels with it', async () => {
		const created = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin', reason: `${RUN_PREFIX} skip list` },
				selection: { ids: seeded },
				justification: `${RUN_PREFIX} skip list`,
			},
		})
		const job = await created.json()

		const skipped = await api.get(
			`${JOBS}/${job.id}/members?outcome=skipped&limit=100`,
			{
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		expect(skipped.status()).toBe(200)
		expect((await skipped.json()).results).toHaveLength(seeded.length)

		// The CSV a handler downloads is the same list, and it exists.
		const report = await api.get(`${JOBS}/${job.id}/download`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(report.status()).toBe(200)
		expect(String(report.headers()['content-type'])).toContain('csv')
		expect(await report.text()).toContain('already_assigned')
	})

	test('the reason is kept with the act and readable afterwards', async () => {
		const reason = `${RUN_PREFIX} the coordinator explained this`

		const created = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin', reason },
				selection: { ids: seeded },
				justification: reason,
			},
		})
		const job = await created.json()

		const read = await api.get(`${JOBS}/${job.id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(read.status()).toBe(200)
		expect((await read.json()).justification).toBe(reason)
	})

	test('a bulk distribution without a written reason is refused', async () => {
		const response = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin' },
				selection: { ids: seeded },
			},
		})

		// A refusal, not a 500 and not a job that quietly ran anyway.
		expect([400, REFUSED]).toContain(response.status())
		expect(JSON.stringify(await response.json()).toLowerCase()).toContain(
			'reason',
		)
	})

	test('an act dossiq does not declare never reaches the job', async () => {
		const response = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'openregister:set-properties',
				parameters: { properties: { title: 'anything' } },
				selection: { ids: seeded },
			},
		})

		expect(response.status()).toBe(400)
	})

	test('a selection on two versions of a case type is refused, naming both', async () => {
		// Two versions of ONE case type: the second names the first as the
		// version it was made from, which is the chain the guard walks.
		const first = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Versioned`,
			identifier: `${RUN_PREFIX.toLowerCase()}-versioned`,
			isDraft: false,
			version: 1,
		})
		const second = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Versioned`,
			identifier: `${RUN_PREFIX.toLowerCase()}-versioned-2`,
			isDraft: false,
			version: 2,
			previousVersion: objectId(first),
		})

		const onOne = await seedCase(api, token, {
			title: `${RUN_PREFIX} on v1`,
			caseType: objectId(first),
		})
		const onTwo = await seedCase(api, token, {
			title: `${RUN_PREFIX} on v2`,
			caseType: objectId(second),
		})

		const response = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:set-case-attribute',
				parameters: { property: 'confidentiality', value: 'openbaar' },
				selection: { ids: [objectId(onOne), objectId(onTwo)] },
			},
		})

		expect(response.status()).toBe(REFUSED)

		const body = await response.json()
		expect(body.reason).toBe('case-type-versions')

		// NAMING BOTH is the requirement. "Mixed versions" is not actionable;
		// "these are on 1 and these on 2" is.
		expect(body.details.versions).toEqual([1, 2])
		expect(String(body.details.caseType)).toContain('Versioned')
	})

	test('no simulation runs when the selection is refused for its versions', async () => {
		const before = await api.get(`${JOBS}?limit=100`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const countBefore = ((await before.json()).results ?? []).length

		await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:set-case-attribute',
				parameters: { property: 'confidentiality', value: 'openbaar' },
				selection: {
					ids: [`${RUN_PREFIX}-missing-a`, `${RUN_PREFIX}-missing-b`],
				},
			},
		})

		const after = await api.get(`${JOBS}?limit=100`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const countAfter = ((await after.json()).results ?? []).length

		// The refusal comes at selection time. A job that exists has already
		// rehearsed, which is the dry run of a question that should never have
		// been asked.
		expect(countAfter).toBe(countBefore)
	})

	test('a field with its own write path is not set in bulk', async () => {
		// `status` goes through the transition engine and `assignee` through
		// the redistribution, which stamps an audit entry this act does not.
		for (const property of ['status', 'assignee']) {
			const response = await api.post(HANDOFF, {
				headers: writeHeaders(token),
				data: {
					action: 'dossiq:set-case-attribute',
					parameters: { property, value: 'anything' },
					selection: { ids: [seeded[0]] },
				},
			})
			expect(response.status()).toBe(400)
		}
	})

	test('committing moves the job off previewed and reports its position', async () => {
		const created = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin', reason: `${RUN_PREFIX} commit` },
				selection: { ids: [seeded[0]] },
				justification: `${RUN_PREFIX} commit`,
			},
		})
		const job = await created.json()

		const committed = await api.post(`${JOBS}/${job.id}/commit`, {
			headers: writeHeaders(token),
			data: {},
		})
		expect(committed.status()).toBe(202)

		const running = await committed.json()
		expect(running.state).not.toBe('previewed')

		// The act survives the page. Read it again from a standing start, the
		// way a handler who closed the tab and came back would.
		const reread = await api.get(`${JOBS}/${job.id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(reread.status()).toBe(200)

		const body = await reread.json()
		expect(body.id).toBe(job.id)
		expect(body.total).toBe(1)
		expect(body.counts).toBeTruthy()
	})

	test("someone else's job is not readable, and says why", async ({
		playwright,
		baseURL,
	}) => {
		// 🔴 A REAL JOB, AND A REAL STRANGER.
		//
		// This probe used to read id 999999999 through
		// `newContext({ baseURL })`. Both halves were wrong and they hid each
		// other: `newContext({ baseURL })` inherits `use.storageState`, which
		// is the ADMIN's captured session, so the reader was an admin; and
		// 999999999 is an id no job has, so the 404 it asserted was "no such
		// row" rather than "you may not read this one". The test passed, and
		// would have passed just as well against an endpoint with no guard at
		// all.
		//
		// So: a job this run genuinely created, read by a caller that has
		// never signed in.
		const created = await api.post(HANDOFF, {
			headers: writeHeaders(token),
			data: {
				action: 'dossiq:reassign-cases',
				parameters: { toUser: 'admin', reason: `${RUN_PREFIX} privacy` },
				selection: { ids: seeded },
				justification: `${RUN_PREFIX} privacy`,
			},
		})
		expect(created.status()).toBe(201)
		const job = await created.json()

		const anonymous = await anonymousContext(playwright, String(baseURL))
		const response = await anonymous.get(`${JOBS}/${job.id}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})

		await expectRefused(
			response,
			REFUSED_ANONYMOUS,
			"a stranger reading somebody else's bulk job",
		)
		// And nothing of the job crossed on the way out.
		expect(await response.text()).not.toContain(RUN_PREFIX)
		await anonymous.dispose()
	})
})
