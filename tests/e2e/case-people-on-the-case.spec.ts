/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * People on the case: a person linked to the case is a party of it, the link
 * becomes a role record, an initiator link names the requester, and a file
 * request goes to one of those parties.
 *
 * WHAT THIS SPEC WRITES, AND WHAT IT LEAVES. One archival case (purged
 * through the case purge helper), one person link on it through
 * OpenRegister's contacts endpoint, and the role record the projection makes
 * for it. The link is removed in afterAll and the case purged, which takes
 * the role record with it.
 *
 * WHAT IT DOES NOT COVER, AND WHY. The file request's mail: creating an email
 * share needs a configured mail server, which this instance has no fixture
 * for. The dialog's own behaviour is pinned in tests/vitest/fileRequestDialog
 * .spec.js and the share itself in tests/Unit/Service/People/FileRequestServi
 * ceTest.php; this spec asserts that the entry exists and that the parties
 * endpoint answers the people of the case, which is what the Nextcloud file
 * request could not do.
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	getRequestToken,
	objectId,
	purgeObject,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const ADMIN = process.env.ADMIN_USER ?? 'admin'

let api: APIRequestContext
let token: string
let caseId = ''
let roleTypeId = ''

/**
 * The people linked to a case, as OpenRegister answers them.
 *
 * @param id The case uuid.
 * @return The listing.
 */
async function peopleOn(id: string): Promise<any> {
	const res = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/case/${id}/contacts`,
		{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
	)
	if (!res.ok()) {
		return { results: [], roles: [] }
	}
	return await res.json()
}

/**
 * The role records of a case.
 *
 * @param id The case uuid.
 * @return The rows.
 */
async function rolesOn(id: string): Promise<any[]> {
	const res = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/role?_limit=100&case=${id}`,
		{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
	)
	if (!res.ok()) {
		return []
	}
	const body = await res.json()
	return Array.isArray(body?.results) ? body.results : []
}

test.describe('People on the case', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const caseTypes = await adoptableCaseTypes(api)
		const chosen = caseTypes[0]
		expect(
			chosen,
			'the instance must ship at least one published case type',
		).toBeTruthy()

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} People on the case`,
			caseType: objectId(chosen),
			startDate: new Date().toISOString().slice(0, 10),
		})
		caseId = objectId(seeded)

		// The case schema's link vocabulary is this instance's role types
		// (REQ-POC-002), written by SyncCaseRoleVocabulary on upgrade.
		const listing = await peopleOn(caseId)
		roleTypeId = String(listing?.roles?.[0]?.key ?? '')
	})

	test.afterAll(async () => {
		if (caseId !== '') {
			await purgeObject(api, token, 'case', caseId).catch(() => undefined)
		}
		await api.dispose()
	})

	// @e2e openspec/specs/people-on-the-case/spec.md#req-poc-002a-the-vocabulary-follows-the-role-types
	test("the case schema declares the instance's role types as its roles", async () => {
		const listing = await peopleOn(caseId)
		expect(
			Array.isArray(listing.roles) && listing.roles.length > 0,
			'the case schema must declare at least one role a person can hold',
		).toBeTruthy()
		for (const role of listing.roles) {
			expect(String(role.key ?? '')).not.toBe('')
			expect(String(role.label ?? '')).not.toBe('')
		}
	})

	// @e2e openspec/specs/people-on-the-case/spec.md#req-poc-001a-linking-a-user-writes-the-role-record
	test('linking a user to the case writes the role record', async () => {
		test.skip(roleTypeId === '', 'the instance declares no role types')

		const linked = await api.post(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}/contacts`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: {
					userId: ADMIN,
					role: roleTypeId,
					note: `${RUN_PREFIX} party`,
				},
			},
		)
		expect(
			linked.ok(),
			`the link must be created, got ${linked.status()}`,
		).toBeTruthy()

		await expect
			.poll(
				async () =>
					(await rolesOn(caseId)).find(
						(row) => String(row.participant ?? '') === `user:${ADMIN}`,
					) ?? null,
				{
					timeout: 20_000,
					message: 'the link must become a role record on the case',
				},
			)
			.not.toBeNull()

		const record = (await rolesOn(caseId)).find(
			(row) => String(row.participant ?? '') === `user:${ADMIN}`,
		)
		expect(String(record.roleType ?? '')).toBe(roleTypeId)
		expect(String(record.name ?? '')).not.toBe('')
	})

	// @e2e openspec/specs/people-on-the-case/spec.md#req-poc-004a-parties-lists-people-not-identifiers
	test('the People tab lists the party by name', async ({ page }) => {
		test.skip(roleTypeId === '', 'the instance declares no role types')
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip.getByRole('tab', { name: /^(People|Mensen)$/ }).click()

		const parties = page.locator('.cn-contacts-tab')
		await expect(parties).toBeVisible({ timeout: 30_000 })
		await expect(parties).toContainText(ADMIN, { ignoreCase: true })
	})

	// @e2e openspec/specs/people-on-the-case/spec.md#req-poc-005a-the-request-names-a-person-of-the-case
	test('a file request can be addressed to a party of the case', async () => {
		test.skip(roleTypeId === '', 'the instance declares no role types')

		const res = await api.get(
			`/index.php/apps/dossiq/api/cases/${caseId}/file-requests/parties`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(
			res.ok(),
			`the parties must be listed, got ${res.status()}`,
		).toBeTruthy()
		const parties = (await res.json())?.parties ?? []
		const party = parties.find(
			(row: any) => String(row.id ?? '') === `user:${ADMIN}`,
		)
		expect(party, 'the linked user must be a party of the case').toBeTruthy()
		// Whether they can be asked follows from having an address, and the
		// dialog says so either way rather than hiding them.
		expect(typeof party.canBeAsked).toBe('boolean')
		expect(party.canBeAsked).toBe(String(party.email ?? '') !== '')

		// A person who is not on the case cannot be asked at all.
		const refused = await api.post(
			`/index.php/apps/dossiq/api/cases/${caseId}/file-requests`,
			{
				headers: {
					requesttoken: token,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				data: { personId: 'user:nobody-here' },
			},
		)
		expect(refused.status()).toBe(404)
	})

	// @e2e openspec/specs/people-on-the-case/spec.md#req-poc-001b-unlinking-removes-the-record-it-wrote
	test('unlinking the party removes the role record', async () => {
		test.skip(roleTypeId === '', 'the instance declares no role types')

		const unlinked = await api.delete(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}/contacts/${encodeURIComponent(`user:${ADMIN}`)}?role=${encodeURIComponent(roleTypeId)}`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(unlinked.ok()).toBeTruthy()

		await expect
			.poll(
				async () =>
					(await rolesOn(caseId)).filter(
						(row) => String(row.participant ?? '') === `user:${ADMIN}`,
					).length,
				{
					timeout: 20_000,
					message: 'the role record must go with the link',
				},
			)
			.toBe(0)

		// The case itself survives, which is the point of a projection.
		const shown = await showObject(api, 'case', caseId)
		expect(String(shown?.title ?? '')).toContain(RUN_PREFIX)
	})
})
