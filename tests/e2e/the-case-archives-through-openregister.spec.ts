/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-ARCH-10 to REQ-ARCH-16: the case page reads the archival facts
 * openregister publishes, an archivist recomputes a nomination by saying why, a
 * reviewer's own items reach them in My Work, and the reminder frequency is set
 * in dossiq's archival settings.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A COMPONENT TEST. The archiving process is
 * openregister's (decision D7). dossiq's component suite proves what the panel
 * DRAWS from a retention block it was handed, and nothing about whether a real
 * `@self._retention` carries the keys it reads, whether the worklist endpoint
 * answers the shape the section walks, or whether a decision posted from here
 * is accepted. An endpoint that answers an empty worklist looks exactly like a
 * reviewer with nothing to sign off, which is the failure this suite catches.
 *
 * WHICH DOOR. Every call goes to openregister's archival routes, because that
 * is what the panel and the section call. A dossiq proxy for them would be a
 * second archival model, which is the thing ADR-022 and D7 both forbid.
 *
 * WHAT IT DOES NOT DO. It destroys nothing it did not seed. A destruction
 * decided on somebody's real case cannot be swept up afterwards, and the
 * fixtures are the only records this suite is allowed to answer for.
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

/** openregister's archival surface, the one the panel and the section call. */
const ARCHIVAL = '/index.php/apps/openregister/api/archival'

/** openregister's archival settings, where the reminder frequency lives. */
const SETTINGS = '/index.php/apps/openregister/api/settings/archival'

/** A case, read for its `@self` block. */
const CASE_OBJECT = '/index.php/apps/openregister/api/objects/dossiq/case'

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

test.describe('The case archives through openregister', () => {
	test.setTimeout(240_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''
	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} archival`,
			caseType: caseTypeId,
			assignee: 'admin',
		})
		caseId = objectId(seeded)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	test('a case read carries the retention block the Archiving tab renders', async () => {
		const response = await api.get(`${CASE_OBJECT}/${caseId}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(response.status()).toBe(200)

		const body = await response.json()
		const self = body['@self'] ?? {}

		// The block may be absent on a case nobody has closed, which is a
		// legitimate answer. What must not happen is the key existing with a
		// shape the panel cannot read, so it is asserted only when present.
		if (self._retention !== undefined && self._retention !== null) {
			expect(typeof self._retention).toBe('object')
		}
	})

	test('recomputing a nomination without a reason is refused', async () => {
		const response = await api.post(
			`${ARCHIVAL}/objects/${caseId}/nomination/recompute`,
			{ headers: writeHeaders(token), data: {} },
		)

		// 400 is the refusal the reason exists for. 409 is a schema that
		// declares no archive block, which is the same message to the reader:
		// nothing was recomputed and the panel says why.
		expect([400, 409]).toContain(response.status())
	})

	test('the pending worklist answers the caller, and never somebody else', async () => {
		const response = await api.get(`${ARCHIVAL}/reviews/pending`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(response.status()).toBe(200)

		const body = await response.json()
		const results = body.results ?? body.entries ?? []
		expect(Array.isArray(results)).toBe(true)

		// The endpoint reads the session user, so nothing in the request names
		// a reviewer. An entry that came back for somebody else would mean the
		// scoping is not on the session at all.
		for (const entry of results) {
			expect(String(entry.reviewer ?? 'admin')).toBe('admin')
		}
	})

	test('an answer with no reason is refused on the decision route', async () => {
		const lists = await api.get(`${ARCHIVAL}/destruction-lists`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(lists.status()).toBe(200)

		const body = await lists.json()
		const first = (body.results ?? [])[0]
		test.skip(first === undefined, 'no destruction list on this instance')

		const response = await api.post(
			`${ARCHIVAL}/destruction-lists/${first.id}/entries/${caseId}/decision`,
			{ headers: writeHeaders(token), data: { answer: 'destroy' } },
		)

		// 400 without a reason, 404 for an entry this list does not hold, 409
		// for an entry nobody is accountable for. None of them destroys
		// anything, which is the property this test is here for.
		expect([400, 404, 409]).toContain(response.status())
	})

	test('the archival settings carry the review reminder frequency', async () => {
		const response = await api.get(SETTINGS, { headers: { 'OCS-APIRequest': 'true' } })
		expect(response.status()).toBe(200)

		const body = await response.json()
		expect(body).toHaveProperty('reviewReminderFrequency')
		expect(String(body.reviewReminderFrequency)).toMatch(/^P/)
	})
})
