/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Teardown deletes what this run created, and leaves what it did not.
 *
 * This is the whole safety claim behind pointing the suite at the shared
 * development container, so it is asserted rather than described. Teardown used
 * to find its objects with `JSON.stringify(row).includes(RUN_PREFIX)`. A content
 * match cannot tell a row this run created from a row that merely mentions the
 * same string, and on a shared instance the second kind belongs to somebody
 * else.
 *
 * The test seeds two caseTypes that are indistinguishable by content. One goes
 * through `createObject`, so the run ledger holds its id. The other is posted
 * straight to OpenRegister, the way a colleague's row or a crashed run's
 * residue arrives: same prefix in the title, no ledger entry. Then teardown
 * runs. The first must be gone and the second must still be there.
 *
 * Under the old content match this test fails on its last assertion, which is
 * what makes it worth keeping.
 *
 * @spec exclude Guards the fixture teardown itself, not a product requirement.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'
import { STORAGE_STATE } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	purgeObject,
	REGISTER,
	RUN_PREFIX,
} from './helpers/fixtures.ts'

const CASE_TYPES = `/index.php/apps/openregister/api/objects/${REGISTER}/caseType`

test.describe('fixture teardown scope', () => {
	let api: APIRequestContext
	let token: string

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			storageState: STORAGE_STATE,
		})
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	test('deletes the object it created and leaves the one it did not', async () => {
		test.setTimeout(180_000)

		// Created through the helper every fixture uses, so the ledger has its id.
		const mine = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} tracked`,
			identifier: `${RUN_PREFIX.toLowerCase()}-tracked`,
			description: 'Seeded through createObject, so teardown owns it.',
		})
		const mineId = objectId(mine)
		expect(mineId, 'the tracked caseType should have an id').not.toBe('')

		// Posted behind the helper's back. Same prefix in the title, no ledger
		// entry. This stands in for a colleague's row on the shared instance.
		const posted = await api.post(CASE_TYPES, {
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: {
				title: `${RUN_PREFIX} untracked`,
				identifier: `${RUN_PREFIX.toLowerCase()}-untracked`,
				description:
					'Stands in for somebody else\'s row: same prefix, different owner.',
			},
		})
		expect(posted.ok(), `seeding the untracked row -> ${posted.status()}`).toBeTruthy()
		const theirsId = objectId(await posted.json())
		expect(theirsId, 'the untracked caseType should have an id').not.toBe('')

		try {
			await cleanupRunObjects(api, token, ['caseType'])

			const mineAfter = await api.get(`${CASE_TYPES}/${mineId}`)
			expect(
				mineAfter.status(),
				'teardown should have removed the object this run created',
			).toBe(404)

			const theirsAfter = await api.get(`${CASE_TYPES}/${theirsId}`)
			expect(
				theirsAfter.status(),
				'teardown must NOT touch an object this run did not create, '
					+ 'however much its body looks like one',
			).not.toBe(404)
		} finally {
			// The stand-in is this test's mess, so this test clears it, by id.
			await purgeObject(api, token, 'caseType', theirsId).catch(() => false)
		}
	})
})
