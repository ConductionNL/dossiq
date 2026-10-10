/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TAO-002, REQ-TAO-003, REQ-TAO-005: dossiq's tenant is OpenRegister's
 * active organisation.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. The unit tests drive the real
 * `TenantContext`, `TenantSessionService` and `TenantOrganisationResolver`
 * with OpenRegister doubled. They cannot prove that OpenRegister's own
 * `set-active` reaches dossiq's mandate check, or that OpenRegister's session
 * cache, which keeps no status, does not let a suspended organisation through.
 * Only a real instance can.
 *
 * The write under test is `POST /api/case-definitions/validate`: it changes
 * nothing, and the mandate matrix maps every POST to the `create` action. The
 * user is a `viewer` in organisation A and a `case_handler` in B, and both
 * carry a mandate row with no matrix, which gives the default matrix: a viewer
 * may not create, a case handler may.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken } from './helpers/addressFixtures.ts'
import {
	captureStorageState,
	ensureUser,
	provisioningContext,
	storageStatePath,
} from './helpers/auth.ts'

/** OpenRegister's API. */
const OR = '/index.php/apps/openregister/api'

/** A dossiq write that changes nothing. */
const WRITE = '/index.php/apps/dossiq/api/case-definitions/validate'

/** A handler who belongs to both organisations. */
const HANDLER = 'e2e-tao-handler'

/** Their password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Tao-Handler-2026!'

/** A run marker, so organisations from earlier runs are not reused. */
const RUN = `e2e-tao-${Date.now().toString(36)}`

let admin: APIRequestContext | null = null
let handler: APIRequestContext | null = null
let orgA = ''
let orgB = ''
const created: Array<{ schema: string; id: string }> = []

/**
 * The uuid in an OpenRegister organisation answer, whatever it is wrapped in.
 *
 * @param body The parsed response.
 * @return The uuid, or ''.
 */
function uuidOf(body: any): string {
	return String(body?.organisation?.uuid ?? body?.uuid ?? body?.data?.uuid ?? '')
}

/**
 * Create one object in the dossiq register as the admin.
 *
 * @param schema The schema slug.
 * @param data   The object.
 */
async function createObject(
	schema: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await admin!.post(`${OR}/objects/dossiq/${schema}`, { data })
	expect(
		res.status(),
		`creating a ${schema} row must succeed: ${await res.text()}`,
	).toBeLessThan(300)
	const body: any = await res.json()
	created.push({
		schema,
		id: String(body?.id ?? body?.uuid ?? body?.['@self']?.id ?? ''),
	})
}

/**
 * Set an organisation active for the handler, through OpenRegister.
 *
 * @param uuid The organisation.
 */
async function setActive(uuid: string): Promise<void> {
	const token = await getRequestToken(handler!)
	const res = await handler!.post(`${OR}/organisations/${uuid}/set-active`, {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `set-active must succeed: ${await res.text()}`).toBeTruthy()
}

/**
 * Make the dossiq write as the handler.
 *
 * @return The response status and body.
 */
async function write(): Promise<{ status: number; body: any }> {
	const token = await getRequestToken(handler!)
	const res = await handler!.post(WRITE, {
		headers: { requesttoken: token },
		data: {},
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

test.describe('The tenant follows the active organisation', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', HANDLER, PASSWORD)

		for (const name of ['A', 'B']) {
			const res = await admin.post(`${OR}/organisations`, {
				data: { name: `${RUN} ${name}` },
			})
			expect(
				res.status(),
				`creating organisation ${name}: ${await res.text()}`,
			).toBeLessThan(300)
			const uuid = uuidOf(await res.json())
			expect(uuid, `organisation ${name} must come back with a uuid`).not.toBe(
				'',
			)
			if (name === 'A') {
				orgA = uuid
			} else {
				orgB = uuid
			}
		}

		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: HANDLER,
			password: PASSWORD,
			statePath: storageStatePath(HANDLER),
		})
		handler = await playwright.request.newContext({
			baseURL: String(baseURL),
			storageState: storageStatePath(HANDLER),
		})

		for (const uuid of [orgA, orgB]) {
			const token = await getRequestToken(handler)
			const joined = await handler.post(`${OR}/organisations/${uuid}/join`, {
				headers: { requesttoken: token },
			})
			expect(
				joined.ok(),
				`the handler must join ${uuid}: ${await joined.text()}`,
			).toBeTruthy()
		}

		await createObject('tenantUser', {
			tenantRef: orgA,
			userRef: HANDLER,
			role: 'viewer',
		})
		await createObject('tenantUser', {
			tenantRef: orgB,
			userRef: HANDLER,
			role: 'case_handler',
		})
		await createObject('tenantMandate', {
			tenantRef: orgA,
			effectiveFrom: '2020-01-01',
		})
		await createObject('tenantMandate', {
			tenantRef: orgB,
			effectiveFrom: '2020-01-01',
		})
	})

	test.afterAll(async () => {
		for (const row of created) {
			if (row.id !== '') {
				await admin?.delete(`${OR}/objects/dossiq/${row.schema}/${row.id}`)
			}
		}

		if (orgB !== '') {
			await admin?.put(`${OR}/organisations/${orgB}/activate`)
		}

		await handler?.dispose()
		await admin?.dispose()
	})

	// @e2e openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md#scenario-the-mandate-check-follows-the-organisation-set-active-in-openregister
	test('a dossiq write is checked against the matrix of the organisation set active in OpenRegister', async () => {
		await setActive(orgB)
		const asHandler = await write()
		expect(
			asHandler.status === 403
				&& /not authorised/.test(String(asHandler.body?.error ?? '')),
			`as a case handler in B the mandate check must pass; got ${asHandler.status} ${JSON.stringify(asHandler.body)}`,
		).toBeFalsy()

		await setActive(orgA)
		const asViewer = await write()
		expect(
			asViewer.status,
			'as a viewer in A the same write must be refused',
		).toBe(403)
		expect(String(asViewer.body?.error ?? '')).toContain('viewer')
	})

	// @e2e openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md#scenario-a-suspended-organisation-cannot-work-in-dossiq
	test('suspending the active organisation refuses the next dossiq write', async () => {
		await setActive(orgB)
		const suspended = await admin!.put(`${OR}/organisations/${orgB}/suspend`)
		expect(
			suspended.ok(),
			`suspending B: ${await suspended.text()}`,
		).toBeTruthy()

		try {
			const refused = await write()
			expect(refused.status).toBe(403)
			expect(refused.body).toMatchObject({
				success: false,
				status: 'suspended',
			})
		} finally {
			await admin!.put(`${OR}/organisations/${orgB}/activate`)
		}
	})

	// @e2e openspec/changes/tenancy-onto-openregister-organisation-active-organisation/specs/tenant-organisation-boundary/spec.md#scenario-the-tenant-routes-are-gone
	test("an admin reads an organisation's usage from OpenRegister, and dossiq answers no tenant route", async () => {
		const usage = await admin!.get(`${OR}/organisations/${orgB}/usage`)
		expect(usage.status(), `usage: ${await usage.text()}`).toBe(200)

		const gone = await admin!.get(
			`/index.php/apps/dossiq/api/tenants/${orgB}/usage`,
		)
		expect(gone.status(), 'dossiq must route no tenant API of its own').toBe(404)
	})
})
