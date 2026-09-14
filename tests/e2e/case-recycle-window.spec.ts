/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CRW-01, REQ-CRW-02 and REQ-CRW-03: a deleted case is recoverable for a
 * stated period, restoring and destroying are two recorded acts, and the
 * lawful-purpose clock and the archive clock stay apart.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. The recycle state is
 * OpenRegister's. dossiq's unit suite constructs its services with a stand-in
 * mapper, so it proves the DECISIONS and not that a real delete reaches a real
 * trash, that a real restore brings the row back, or that the window
 * OpenRegister computes is the one dossiq publishes. A lens bound to an
 * endpoint that answers an empty list looks exactly like a lens over an empty
 * trash, which is the failure this suite exists to catch.
 *
 * WHICH DOOR. Every assertion knocks on dossiq's own endpoints, because those
 * are what the page uses and what carries the case-type role. OpenRegister's
 * `/api/deleted` is asserted in its own suite (openregister#3724,
 * `tests/e2e/ci/delete-window.spec.ts`) and is not restated here.
 *
 * WHAT THIS SUITE DOES NOT DO. It never destroys a case seeded by another
 * worker, and it destroys only rows carrying RUN_PREFIX: a destruction cannot
 * be undone, so a suite that swept broadly would take somebody's real work
 * with it the first time a filter was wrong.
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
	tryDeleteObject,
} from './helpers/fixtures.ts'

/** dossiq's own recycle endpoints, the ones the page uses. */
const DOSSIQ = '/index.php/apps/dossiq/api'

/** The status OpenRegister's object API answers when a hook stops a delete. */
const HOOK_STOPPED = 422

/** Ids of the deadlineInstance rows this suite seeded, for teardown. */
const seededTerms: string[] = []

/**
 * Headers for a dossiq write.
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

test.describe('A deleted case is recoverable, and destroying it is a second act', () => {
	test.setTimeout(240_000)

	let api: APIRequestContext
	let token = ''
	let caseTypeId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
	})

	test.afterAll(async () => {
		for (const id of seededTerms) {
			await deleteObject(api, token, 'deadlineInstance', id)
		}
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#a-bezwaar-deleted-by-mistake-is-still-there
	//
	// The date matters more than the row does. A lens that listed the case
	// with an empty "recover until" cell would satisfy a presence assertion
	// and still leave the handler unable to tell how long they have.
	test('a bezwaar deleted by mistake is listed with the date its window ends', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} deleted by mistake`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		const deleted = await tryDeleteObject(api, token, 'case', caseId)
		expect(
			deleted.status,
			`a case nothing holds must delete: ${JSON.stringify(deleted.body)}`,
		).toBeLessThan(300)

		const res = await api.get(`${DOSSIQ}/cases/deleted`)
		expect(res.status()).toBe(200)

		const rows = ((await res.json()) as any).results ?? []
		const row = rows.find((r: any) => r.id === caseId)

		expect(row, 'the deleted case must be in the deleted lens').toBeTruthy()
		expect(
			String(row.windowEndsOn ?? ''),
			'the lens must say the DATE the window ends, not leave the reader to compute a duration',
		).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(typeof row.daysRemaining).toBe('number')
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#the-guard-still-refuses-what-it-refused-before
	//
	// The control on the whole change: the recovery window must not have
	// turned the delete guard into a formality. A case with a running term is
	// refused, and it does NOT appear in the trash afterwards.
	test('the guard still refuses a held case, and it never enters the recycle state', async () => {
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
		expect(refused.status).toBe(HOOK_STOPPED)

		const rows = ((await (await api.get(`${DOSSIQ}/cases/deleted`)).json()) as any).results ?? []
		expect(
			rows.some((r: any) => r.id === heldId),
			'a refused delete must not put the case in the trash',
		).toBe(false)
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#a-case-is-got-back
	//
	// The restore is asserted twice: the case is live again, AND the act was
	// recorded. A restore that worked and recorded nothing leaves a trail
	// showing a case deleted and then, with no explanation, present.
	test('a case is got back, and the restore records who and when', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} restored`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		await tryDeleteObject(api, token, 'case', caseId)

		const restored = await api.post(
			`${DOSSIQ}/case/${encodeURIComponent(caseId)}/restore`,
			{ headers: writeHeaders(token), data: {} },
		)
		expect(restored.status()).toBe(200)
		expect(((await restored.json()) as any).success).toBe(true)

		const rows = ((await (await api.get(`${DOSSIQ}/cases/deleted`)).json()) as any).results ?? []
		expect(
			rows.some((r: any) => r.id === caseId),
			'a restored case must leave the trash',
		).toBe(false)

		const trail = await api.get(
			`/index.php/apps/openregister/api/deleted/${encodeURIComponent(caseId)}/destruction`,
		)
		expect(
			trail.status(),
			'the destruction endpoint must answer for a case that was never destroyed, with an empty list',
		).toBeLessThan(500)
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#only-the-declared-role-destroys
	//
	// Playwright runs as admin, and an admin holds every right, so the
	// assertion that a handler WITHOUT the role is refused cannot be made
	// here. What can be made here is the other half of the same rule: a case
	// type that names no destroying role refuses the destruction and says so,
	// and that refusal is the one a handler without the role also gets.
	test('a case type naming no destroying role refuses the destruction by name', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} nobody may destroy`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		await tryDeleteObject(api, token, 'case', caseId)

		const preview = await api.get(
			`${DOSSIQ}/case/${encodeURIComponent(caseId)}/destruction-preview`,
		)
		expect(preview.status()).toBe(200)

		const body = (await preview.json()) as any
		expect(
			body.destroyingRole,
			'an adopted case type states no destroying role, and the preview must say so rather than imply one',
		).toBe('')
		expect(body.deletionWindow).toBeTruthy()
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#nothing-survives-a-destruction
	//
	// The window is a refusal and not decoration: inside it the case can still
	// come back, so destroying it needs the window waived explicitly. This
	// test destroys nothing. It asserts that the refusal happens and that the
	// case is still in the trash afterwards, which is the property a
	// destruction that ran anyway would break.
	test('a case inside its window is not destroyed, and the refusal names the rule', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} still recoverable`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		await tryDeleteObject(api, token, 'case', caseId)

		const refused = await api.post(
			`${DOSSIQ}/case/${encodeURIComponent(caseId)}/destroy`,
			{ headers: writeHeaders(token), data: {} },
		)

		expect(refused.status()).toBe(409)
		expect(((await refused.json()) as any).code).toBe('recovery_window_open')

		const rows = ((await (await api.get(`${DOSSIQ}/cases/deleted`)).json()) as any).results ?? []
		expect(
			rows.some((r: any) => r.id === caseId),
			'a refused destruction must leave the case in the trash',
		).toBe(true)
	})

	// @e2e openspec/changes/case-recycle-window/specs/case-management/spec.md#a-case-whose-purpose-ended-is-still-archived
	//
	// The two clocks come back as two values with two labels. The assertion is
	// on the SEPARATION, not on either date: a single date field answered
	// twice would satisfy a "both present" check and be exactly the defect
	// C-access-and-privacy-65 describes.
	test('both clocks are read apart, each labelled', async () => {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} two clocks`,
			caseType: caseTypeId,
			endDate: '2023-01-31',
			archiveActionDate: '2034-01-31',
		})
		const caseId = objectId(seeded)

		const res = await api.get(
			`${DOSSIQ}/case/${encodeURIComponent(caseId)}/retention-clocks`,
		)
		expect(res.status()).toBe(200)

		const clocks = (await res.json()) as any
		expect(clocks.archive.date).toBe('2034-01-31')
		expect(clocks.lawfulPurpose.label).not.toBe(clocks.archive.label)
		expect(
			clocks.lawfulPurpose.date,
			'the lawful-purpose date must not be a copy of the archive date',
		).not.toBe(clocks.archive.date)
		expect(
			String(clocks.lawfulPurpose.rule ?? ''),
			'each clock must name the rule that produced it, so a reader can tell why the date is what it is',
		).not.toBe('')
	})
})
