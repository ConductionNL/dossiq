/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CM-35: a case is deleted only when nothing holds it, and when something
 * does, the refusal names every rule that holds.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. The guard hangs off
 * OpenRegister's pre-persist `ObjectDeletingEvent`, which is a seam no PHPUnit
 * in this repo crosses: the unit suite constructs the listener and calls
 * `handle()` itself, so it proves the DECISION and not that the listener is
 * ever reached. A listener that is never registered, or registered on the
 * post-persist event that cannot refuse, passes every unit assertion and
 * deletes every case. Only a real DELETE through a real OpenRegister shows
 * that the store consults it.
 *
 * WHICH STATUS A REFUSAL CARRIES DEPENDS ON WHICH DOOR YOU KNOCK ON, and this
 * suite knocks on the one the app uses. OpenRegister's generic object API
 * wraps a stopped hook in `HookStoppedException` and answers 422, carrying the
 * guard's body through under `errors`. Dossiq's own ZGW door answers
 * `CaseHeldException::STATUS` (409) for the same refusal, and that mapping is
 * pinned in `tests/Unit/Listener/CaseDeleteGuardListenerTest.php` plus
 * `ZrcController::destroyCase()`; it is not asserted here because the ZGW door
 * needs a JWT this suite has no consumer for. So the assertion below is on the
 * exact status THIS door returns, never on "some 4xx", which a 403 from an
 * unrelated permission failure would also satisfy.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	tryDeleteObject,
} from './helpers/fixtures.ts'

/** The status OpenRegister's object API answers when a hook stops a delete. */
const HOOK_STOPPED = 422

/** Ids of the deadlineInstance rows this suite seeded, for teardown. */
const seededTerms: string[] = []

test.describe('A held case is not deleted, and you are told why', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
	})

	test.afterAll(async () => {
		// The terms first: a deadlineInstance points at a case, and it is not
		// in FIXTURE_SCHEMAS, so the shared sweep does not own it.
		for (const id of seededTerms) {
			await deleteObject(api, token, 'deadlineInstance', id)
		}
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-management::an-open-term-blocks-the-delete
	//
	// The term is seeded `lopend`, which is the status TermijnService writes
	// when it starts a beslistermijn, so this is the shape a real running case
	// has rather than one invented for the test.
	test('an open term blocks the delete, and the refusal names it', async () => {
		const held = await seedCase(api, token, {
			title: `${RUN_PREFIX} held by a running term`,
			caseType: caseTypeId,
		})
		const heldId = objectId(held)

		const term = await createObject(api, token, 'deadlineInstance', {
			case: heldId,
			status: 'lopend',
			startDate: '2026-01-05T09:00:00+00:00',
			endDateCurrent: '2046-02-16',
		})
		seededTerms.push(objectId(term))

		const refused = await tryDeleteObject(api, token, 'case', heldId)

		expect(
			refused.status,
			'a case with a running term must be refused by the store, not merely warned about in the UI',
		).toBe(HOOK_STOPPED)

		const body = refused.body as any
		expect(body?.errors?.error, 'the refusal is this guard speaking').toBe(
			'case.held',
		)
		expect(body?.errors?.blockedBy).toContain('open-term')
		expect(
			String(body?.errors?.message ?? ''),
			'the message must say WHICH rule holds the case, in words',
		).toMatch(/statutory term|wettelijke termijn/i)

		// The case is still there. A refusal that answered 422 and deleted the
		// row anyway would satisfy every assertion above.
		const survivor = await showObject(api, 'case', heldId)
		expect(objectId(survivor)).toBe(heldId)
	})

	// @e2e case-management::a-free-case-is-deleted
	//
	// THE CONTROL FOR EVERY REFUSAL ABOVE. A guard that refused every delete
	// would pass the test above and fail nobody until production, so this one
	// seeds a case in the state the requirement describes as free: closed,
	// past its disposal date, no sub-cases, no hold, no term.
	test('a closed case past its retention, held by nothing, is deleted', async () => {
		const free = await seedCase(api, token, {
			title: `${RUN_PREFIX} held by nothing`,
			caseType: caseTypeId,
			endDate: '2006-01-31',
			archiveActionDate: '2016-01-31',
		})
		const freeId = objectId(free)

		const outcome = await tryDeleteObject(api, token, 'case', freeId)

		expect(
			outcome.status,
			`a case nothing holds must delete: ${JSON.stringify(outcome.body)}`,
		).toBeLessThan(300)
	})

	// No @e2e anchor: this is not a scenario of its own, and an anchor that
	// addresses nothing is worse than none. It is kept because the retention rule
	// reads a disposal date, and a guard that read the date WITHOUT the close
	// would refuse every case whose type stamps one in advance. That build
	// passes both tests above.
	test('an open case carrying a future disposal date still deletes', async () => {
		const open = await seedCase(api, token, {
			title: `${RUN_PREFIX} open with a future disposal date`,
			caseType: caseTypeId,
			archiveActionDate: '2046-01-31',
		})
		const openId = objectId(open)

		const outcome = await tryDeleteObject(api, token, 'case', openId)

		expect(
			outcome.status,
			`an open case is never held by retention: ${JSON.stringify(outcome.body)}`,
		).toBeLessThan(300)
	})
})
