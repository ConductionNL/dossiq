/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two handlers, one case, one lock.
 *
 * WHY THIS NEEDS TWO SESSIONS AND A REAL STORE. The unit tests pin each half
 * to the sentence: `useObjectLock.endpoints.spec.js` proves the acquire goes to
 * `POST /lock` on the schema slug and the release to `POST /unlock`, with the
 * mutation that puts `DELETE /lock` back reddening the release assertion;
 * `CnDetailPageEditLock.spec.js` proves opening acquires, closing releases and
 * Edit is withdrawn while another user holds it. Both run against doubles. What
 * neither can show is that OpenRegister WRITES a lock a second session can see,
 * that `@self.locked` carries the holder back on an ordinary object read, and
 * that the release actually frees it for the next person. All three sit between
 * two apps.
 *
 * 🔴 THE SECOND SESSION IS A DIFFERENT ACCOUNT, NOT A SECOND TAB. A lock is
 * about two PEOPLE. Two tabs of one account share a uid, so every assertion
 * below would pass on a lock that does not distinguish holders at all, which is
 * precisely the failure this feature exists to prevent.
 *
 * 🔴 THE LOCK IS TAKEN THROUGH THE ENDPOINT, NOT BY WRITING `locked`. Patching
 * the field would produce a marker no lock path ever wrote, and the refusal
 * being tested is the platform's, not the field's.
 *
 * ⚠️ THE WRITE GUARD IS NOT ASSERTED HERE, AND THAT IS DELIBERATE.
 * OpenRegister's `run-scoped-object-locking` is not shipped (its tasks.md is
 * unchecked on `development`, read 2026-09-18), so a held lock does not yet
 * refuse a save. The scenarios below are the two the spec names, and both are
 * about what the SECOND handler is shown. A third scenario asserting a refused
 * write would fail today for a reason that is not dossiq's, and one written to
 * pass would have to assert the write SUCCEEDS, which is the opposite of what
 * this feature is for.
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

/** The case both handlers reach for. */
let contestedCase = ''

/**
 * The throwaway case type `beforeAll` seeds, shared by every case this suite
 * makes. It used to be a local in `beforeAll`, so the control case below was
 * seeded with no case type at all and OpenRegister refused it with
 * "The required property (caseType) is missing".
 */
let caseTypeId = ''

/** The second handler, who is not the admin. */
const OTHER_USER = process.env.E2E_USER_NAME || 'e2euser'
const OTHER_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

/** OpenRegister's lock routes, both POST. There is no DELETE on either. */
function lockUrl(id: string) {
	return `/index.php/apps/openregister/api/objects/dossiq/case/${id}/lock`
}
function unlockUrl(id: string) {
	return `/index.php/apps/openregister/api/objects/dossiq/case/${id}/unlock`
}

test.describe('An edit takes the lock, and the next handler is told whose it is', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		caseTypeId = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} vergunning`,
				identifier: `${RUN_PREFIX.toLowerCase()}-lock`,
				description: 'Throwaway caseType for the edit-lock e2e layer.',
				isDraft: false,
				version: 1,
			}),
		)

		contestedCase = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} betwiste zaak`,
				caseType: caseTypeId,
			}),
		)

		const admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', OTHER_USER, OTHER_PASS)
	})

	test.afterEach(async ({ playwright, baseURL }) => {
		// Every test leaves the case unlocked, or the next one starts against a
		// lock it did not take and reads its own leftovers as the subject.
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await api.post(unlockUrl(contestedCase), {
			headers: { requesttoken: token },
		})
	})

	/**
	 * @spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md#requirement-an-edit-takes-the-platform-lock-and-names-its-holder-req-cm-39
	 */
	test('the lock the first handler takes names them on the object', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const taken = await api.post(lockUrl(contestedCase), {
			headers: { requesttoken: token },
			data: { duration: 1800 },
		})
		expect(taken.ok(), await taken.text()).toBeTruthy()

		// The read every surface makes carries the marker, which is what lets
		// the header name the holder without a call of its own.
		const held = await showObject(api, 'case', contestedCase)
		const marker = held?.['@self']?.locked ?? held?.locked
		expect(marker, 'the case read carries the lock').toBeTruthy()
		expect(
			String(marker.user ?? marker.displayName ?? ''),
			'the marker names who holds it, not only that it is held',
		).not.toBe('')
	})

	/**
	 * 🔴 The second handler is a different ACCOUNT, so the uid is really compared.
	 *
	 * @spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md#requirement-an-edit-takes-the-platform-lock-and-names-its-holder-req-cm-39
	 */
	test('a second handler sees the holder, and is not the holder', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await api.post(lockUrl(contestedCase), {
			headers: { requesttoken: token },
			data: { duration: 1800 },
		})

		const basic = Buffer.from(`${OTHER_USER}:${OTHER_PASS}`).toString('base64')
		const asOther = await playwright.request.newContext({
			baseURL,
			// An explicit empty jar, or the admin's captured session rides along
			// and both halves of this test are the same person.
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: {
				Authorization: `Basic ${basic}`,
				'OCS-APIRequest': 'true',
			},
		})

		const seen = await asOther.get(
			`/index.php/apps/openregister/api/objects/dossiq/case/${contestedCase}`,
		)
		expect(seen.ok(), await seen.text()).toBeTruthy()

		const body = await seen.json()
		const marker = body?.['@self']?.locked ?? body?.locked
		expect(marker, 'the second handler sees the lock too').toBeTruthy()
		expect(
			String(marker.user ?? ''),
			'and the holder is the first handler, not themselves',
		).not.toBe(OTHER_USER)
	})

	/**
	 * 🔴 The scenario the DELETE bug made impossible.
	 *
	 * `useObjectLock.release()` sent `DELETE /lock` until nextcloud-vue#1202,
	 * and read its own 404 as "already released; idempotent". So the lock
	 * survived every release, and this is the assertion that says it does not
	 * any more: the release goes to `POST /unlock`, and afterwards the marker is
	 * gone.
	 *
	 * @spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md#requirement-an-edit-takes-the-platform-lock-and-names-its-holder-req-cm-39
	 */
	test('leaving releases, and the case is free for the next handler', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await api.post(lockUrl(contestedCase), {
			headers: { requesttoken: token },
			data: { duration: 1800 },
		})
		const lockedNow = await showObject(api, 'case', contestedCase)
		expect(
			lockedNow?.['@self']?.locked ?? lockedNow?.locked,
			'the lock was actually taken, so the release below proves something',
		).toBeTruthy()

		const released = await api.post(unlockUrl(contestedCase), {
			headers: { requesttoken: token },
		})
		expect(released.ok(), await released.text()).toBeTruthy()

		const free = await showObject(api, 'case', contestedCase)
		const marker = free?.['@self']?.locked ?? free?.locked
		expect(
			marker === null || marker === undefined || marker === false,
			'the case carries no lock once it is handed back',
		).toBe(true)
	})

	/**
	 * The control for the two tests above: a case nobody touched carries no
	 * lock. Without it a platform that never writes the marker at all would
	 * make "the lock is gone" pass for the wrong reason.
	 *
	 * @spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md#requirement-an-edit-takes-the-platform-lock-and-names-its-holder-req-cm-39
	 */
	test('an untouched case carries no lock', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const untouched = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} rustige zaak`,
				caseType: caseTypeId,
			}),
		)

		const read = await showObject(api, 'case', untouched)
		const marker = read?.['@self']?.locked ?? read?.locked
		expect(marker === null || marker === undefined || marker === false).toBe(
			true,
		)
	})
})
