/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Only named people and groups see a document with an access exception.
 *
 * WHAT THIS SPEC IS FOR. The rule, the writer, the controller and the listing
 * decorations are pinned by PHPUnit against doubles (tests/Unit/Service/
 * Zaakdossier/DocumentAccessException*, tests/Unit/Controller/
 * DocumentAccessExceptionControllerTest.php). What none of those can show is
 * the part that lives in OpenRegister: that the informatieobject's own
 * authorization (register.d/99-document-access-exception.json) really leaves a
 * restricted record out for a colleague the exception does not name, admits a
 * member of a group it does name, and refuses a raw write of the protected
 * fields. That is task 6.1 of the change, the live check, written down so it
 * can be run on a rig instead of by hand.
 *
 * 🔴 THE COLLEAGUE IS A DIFFERENT ACCOUNT. Every read that must be refused is
 * made by `E2E_USER_NAME` through `unprivilegedContext`, with an explicit
 * empty cookie jar, never by the admin: an administrator may read the document
 * by design, so an assertion made from the admin session cannot fail.
 *
 * 🔴 THE PRECONDITION IS ASSERTED, NOT ASSUMED. Before the exception is set the
 * colleague reads the record. Without that, a refusal afterwards could be any
 * refusal (no group, no such account), and would read exactly like the one
 * under test.
 *
 * WHAT IT WRITES, AND WHAT IT LEAVES. One informatieobjecttype and one
 * informatieobject (tracked, removed by the run teardown), one throwaway group
 * (removed in afterAll), and the colleague's membership of
 * `dossiq-medewerkers` and of that group, each taken away again in afterAll
 * unless the colleague already held it.
 *
 * WHAT IT DOES NOT COVER. The file restriction itself (FileRestrictionMirror)
 * needs a document with a file and OpenRegister's per-file-read-restriction;
 * the seeded record has no file, so the writer skips that step. The ZIP, the
 * portal and the Woo refusal are pinned in PHPUnit.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { provisioningContext } from './helpers/auth.ts'
import {
	ensureTeam,
	getRequestToken,
	objectId,
	REGISTER,
	removeTeams,
	RUN_PREFIX,
	seedDocument,
	showObject,
} from './helpers/fixtures.ts'
import { unprivilegedContext } from './helpers/principals.ts'

/** The colleague who reads cases but is not named on the exception. */
const COLLEAGUE = process.env.E2E_USER_NAME ?? 'e2euser'

/** The staff group the informatieobject's authorization admits by default. */
const STAFF_GROUP = 'dossiq-medewerkers'

/** OpenRegister's object route for one informatieobject. */
function rawRecordUrl(id: string): string {
	return `/index.php/apps/openregister/api/objects/${REGISTER}/informatieobject/${id}`
}

/** dossiq's access-exception route for one informatieobject. */
function exceptionUrl(id: string): string {
	return `/index.php/apps/dossiq/api/informatieobjecten/${id}/access-exception`
}

/**
 * The ids of the informatieobjecten a caller gets from OpenRegister's list.
 *
 * @param ctx The caller.
 * @return The ids in the first page, filtered to this run's titles.
 */
async function listedIds(ctx: APIRequestContext): Promise<string[]> {
	const qs = new URLSearchParams({ _limit: '200', _search: RUN_PREFIX }).toString()
	const res = await ctx.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/informatieobject?${qs}`,
	)
	expect(res.ok(), `list informatieobject -> ${res.status()}`).toBeTruthy()
	const body: any = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body?.results ?? [])
	return rows.map((row) => objectId(row))
}

/**
 * The groups a user is in, over OCS provisioning.
 *
 * @param admin An administrator context.
 * @param uid   The account.
 * @return Its group ids.
 */
async function groupsOf(admin: APIRequestContext, uid: string): Promise<string[]> {
	const res = await admin.get(
		`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/groups?format=json`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	const body: any = await res.json().catch(() => ({}))
	const groups = body?.ocs?.data?.groups
	return Array.isArray(groups) ? groups.map(String) : []
}

/**
 * Add or remove a user's membership of a group, over OCS provisioning.
 *
 * @param admin  An administrator context.
 * @param uid    The account.
 * @param gid    The group.
 * @param member True to add, false to remove.
 */
async function setMembership(
	admin: APIRequestContext,
	uid: string,
	gid: string,
	member: boolean,
): Promise<void> {
	const url = `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/groups?format=json`
	const headers = { 'OCS-APIRequest': 'true' }
	// A DELETE carries its group in the query: not every stack parses a body on it.
	const res = member
		? await admin.post(url, { headers, form: { groupid: gid } })
		: await admin.delete(`${url}&groupid=${encodeURIComponent(gid)}`, { headers })
	const status = Number((await res.json().catch(() => ({})))?.ocs?.meta?.statuscode ?? -1)
	expect(
		[100, 200],
		`${member ? 'adding' : 'removing'} ${uid} ${member ? 'to' : 'from'} ${gid} answered OCS ${status}`,
	).toContain(status)
}

test.describe('A document with an access exception is seen only by whom it names', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	let api: APIRequestContext
	let admin: APIRequestContext
	let token = ''
	let documentId = ''
	let team = { id: '', name: '' }
	const addedMemberships: string[] = []

	test.beforeAll(async ({ playwright, baseURL }) => {
		test.setTimeout(120_000)
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		admin = await provisioningContext(playwright, String(baseURL))

		team = await ensureTeam(admin, token, 'afscherming')

		// The colleague must be staff, so that BEFORE the exception the record
		// is theirs to read. Only what this run added is taken away again.
		const held = await groupsOf(admin, COLLEAGUE)
		expect(
			held,
			`${COLLEAGUE} must be an ordinary account; ci-seed.sh refuses one in admin`,
		).not.toContain('admin')
		if (held.includes(STAFF_GROUP) === false) {
			await setMembership(admin, COLLEAGUE, STAFF_GROUP, true)
			addedMemberships.push(STAFF_GROUP)
		}

		documentId = objectId(
			await seedDocument(api, token, { title: `${RUN_PREFIX} Offerte verlichting` }),
		)
		expect(documentId, 'the seeded document has an id').not.toBe('')
	})

	test.afterAll(async () => {
		if (documentId !== '') {
			// Free the record first, so the run teardown can delete it as usual.
			await api.delete(exceptionUrl(documentId), {
				headers: { requesttoken: token },
			})
		}
		for (const gid of addedMemberships) {
			await setMembership(admin, COLLEAGUE, gid, false).catch(() => undefined)
		}
		await removeTeams(admin)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-a-reader-of-the-case-who-is-not-listed
	 */
	test('before the exception, the colleague reads the record', async ({
		playwright,
		baseURL,
	}) => {
		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const res = await colleague.get(rawRecordUrl(documentId))
		expect(res.status(), await res.text()).toBe(200)
		expect(await listedIds(colleague)).toContain(documentId)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-an-exception-without-people-groups-or-reason-is-refused
	 */
	test('an exception naming nobody, or without a reason, is refused and nothing is stored', async () => {
		const nobody = await api.put(exceptionUrl(documentId), {
			headers: { requesttoken: token },
			data: { users: [], groups: [], reason: 'Bedrijfsgevoelige prijzen' },
		})
		expect(nobody.status(), await nobody.text()).toBe(400)

		const noReason = await api.put(exceptionUrl(documentId), {
			headers: { requesttoken: token },
			data: { users: [], groups: [team.id], reason: '' },
		})
		expect(noReason.status(), await noReason.text()).toBe(400)

		const record = await showObject(api, 'informatieobject', documentId)
		expect(record.accessException ?? null, 'no exception was stored').toBeNull()
		expect(record.accessExceptionHistory ?? [], 'and no history either').toHaveLength(0)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-setting-an-exception-records-it
	 */
	test('setting the exception records it, with who, when and why', async () => {
		const adminUid = process.env.ADMIN_USER ?? 'admin'
		const res = await api.put(exceptionUrl(documentId), {
			headers: { requesttoken: token },
			data: { users: [adminUid], groups: [team.id], reason: 'Bedrijfsgevoelige prijzen' },
		})
		expect(res.status(), await res.text()).toBe(200)

		const stored = (await res.json()).accessException
		expect(stored.users).toEqual([adminUid])
		expect(stored.groups).toEqual([team.id])
		expect(stored.reason).toBe('Bedrijfsgevoelige prijzen')
		expect(stored.setBy).toBe(adminUid)
		expect(String(stored.setAt)).not.toBe('')

		const record = await showObject(api, 'informatieobject', documentId)
		expect(record.accessException?.groups).toEqual([team.id])
		const history: any[] = record.accessExceptionHistory ?? []
		expect(history, 'one history entry').toHaveLength(1)
		expect(history[0].action).toBe('set')
		expect(history[0].by).toBe(adminUid)
		expect(history[0].old ?? null).toBeNull()
		expect(history[0].new?.groups).toEqual([team.id])
		expect(history[0].reason).toBe('Bedrijfsgevoelige prijzen')
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-staff-who-are-not-listed-do-not-get-the-record-from-openregister
	 */
	test('a colleague the exception does not name no longer gets the record', async ({
		playwright,
		baseURL,
	}) => {
		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const direct = await colleague.get(rawRecordUrl(documentId))
		expect(
			[403, 404],
			`a direct read answered ${direct.status()} ${await direct.text()}`,
		).toContain(direct.status())
		expect(await listedIds(colleague), 'nor is it in the list').not.toContain(documentId)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-a-raw-write-of-the-exception-is-refused
	 */
	test('the colleague cannot lift the exception by writing the record', async ({
		playwright,
		baseURL,
	}) => {
		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const res = await colleague.patch(rawRecordUrl(documentId), {
			data: { accessException: null, restricted: false, accessUsers: [], accessGroups: [] },
		})
		expect(res.ok(), `a raw write answered ${res.status()} ${await res.text()}`).toBeFalsy()

		const record = await showObject(api, 'informatieobject', documentId)
		expect(record.accessException?.groups, 'the exception is still there').toEqual([team.id])
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-listed-people-the-handler-and-an-administrator
	 */
	test('a member of a listed group reads the record', async ({ playwright, baseURL }) => {
		await setMembership(admin, COLLEAGUE, team.id, true)
		addedMemberships.push(team.id)

		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const res = await colleague.get(rawRecordUrl(documentId))
		expect(res.status(), await res.text()).toBe(200)
		expect(await listedIds(colleague)).toContain(documentId)

		await setMembership(admin, COLLEAGUE, team.id, false)
		addedMemberships.splice(addedMemberships.indexOf(team.id), 1)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-a-reader-who-may-not-change-the-case
	 */
	test('the colleague cannot set or remove the exception through dossiq', async ({
		playwright,
		baseURL,
	}) => {
		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const set = await colleague.put(exceptionUrl(documentId), {
			data: { users: [COLLEAGUE], groups: [], reason: 'Ik wil het zien' },
		})
		expect(set.ok(), `set answered ${set.status()} ${await set.text()}`).toBeFalsy()
		const removed = await colleague.delete(exceptionUrl(documentId))
		expect(removed.ok(), `remove answered ${removed.status()}`).toBeFalsy()

		const record = await showObject(api, 'informatieobject', documentId)
		expect(record.accessException?.groups).toEqual([team.id])
		expect(record.accessExceptionHistory ?? []).toHaveLength(1)
	})

	/**
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#scenario-removing-the-exception-records-it
	 */
	test('removing the exception records it, and the colleague reads again', async ({
		playwright,
		baseURL,
	}) => {
		const res = await api.delete(exceptionUrl(documentId), {
			headers: { requesttoken: token },
		})
		expect(res.status(), await res.text()).toBe(200)
		expect((await res.json()).removed).toBe(true)

		const record = await showObject(api, 'informatieobject', documentId)
		expect(record.accessException ?? null).toBeNull()
		const history: any[] = record.accessExceptionHistory ?? []
		expect(history).toHaveLength(2)
		expect(history[1].action).toBe('remove')
		expect(history[1].old?.groups).toEqual([team.id])
		expect(history[1].new ?? null).toBeNull()

		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const read = await colleague.get(rawRecordUrl(documentId))
		expect(read.status(), await read.text()).toBe(200)
	})
})
