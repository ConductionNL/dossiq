/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A mandate holder opens the case she holds a mandate for and sees its steps.
 *
 * The defect this guards: the steps provider read the case as the system, and
 * OpenRegister lifts RBAC there only for a request without a Nextcloud user.
 * A mandate holder signed in to Nextcloud as herself is such a user, RBAC does
 * not know her as the case's party, and the steps came back empty. So the
 * resident's portal session here rides on the session of an ordinary
 * Nextcloud account, never the administrator's, which would read the case
 * through RBAC and pass for the wrong reason.
 */

import { expect, test } from '@playwright/test'
import { captureStorageState, storageStatePath } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { asResident, portaliqEnabled, residentSession } from './helpers/portal-resident.ts'

/** The ordinary Nextcloud account ci-seed creates. */
const USER = process.env.E2E_USER_NAME ?? 'e2euser'
const PASSWORD = process.env.E2E_USER_PASS ?? 'e2e-user-pass'

/** Where portaliq keeps its mandates. */
const MANDATES = '/index.php/apps/openregister/api/objects/portaliq/portalMandate'

test.describe('A mandated case read by the mandate holder', () => {
	test.setTimeout(180_000)

	let mandateId = ''

	test.afterAll(async ({ request }) => {
		const token = await getRequestToken(request)
		if (mandateId !== '') {
			await request.delete(`${MANDATES}/${mandateId}`, {
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			})
		}
		await cleanupRunObjects(request, token)
	})

	// @e2e openspec/changes/mandated-case-steps-read-unscoped/specs/portal-contribution/spec.md#scenario-a-mandated-reader-sees-where-the-case-stands
	test('the mandate holder sees the steps of the case', async ({
		playwright,
		browser,
		baseURL,
		request,
	}) => {
		test.skip(!(await portaliqEnabled()), 'portaliq is not installed on this instance')

		const token = await getRequestToken(request)
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }
		const prefix = RUN_PREFIX.toLowerCase()
		const father = `${prefix}-vader`
		const holder = `${prefix}-linda`

		const caseType = await ensureCaseType(request, token)
		for (const [order, name] of [[1, 'Ontvangen'], [2, 'In behandeling']] as const) {
			const status = await request.post(
				`/index.php/apps/openregister/api/objects/${REGISTER}/statusType`,
				{ headers, data: { name: `${RUN_PREFIX} ${name}`, caseType: caseType.id, order, isFinal: false } },
			)
			expect(status.ok(), await status.text()).toBeTruthy()
		}
		const caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Aanvraag van vader`,
				caseType: caseType.id,
				portalSubject: father,
			}),
		)

		const granted = await request.post(MANDATES, {
			headers,
			data: {
				subjectRef: holder,
				onBehalfOf: father,
				organisation: 'dev-org',
				label: `${RUN_PREFIX} Machtiging vader`,
				reach: 'organisation',
				status: 'active',
				grantedAt: new Date().toISOString(),
			},
		})
		expect(granted.ok(), await granted.text()).toBeTruthy()
		mandateId = objectId(await granted.json())

		const statePath = storageStatePath(USER)
		await captureStorageState(browser, { baseURL: baseURL!, user: USER, password: PASSWORD, statePath })
		const asUser = await playwright.request.newContext({ baseURL, storageState: statePath })
		const session = await residentSession(asUser, holder)
		expect(session, 'portaliq dev-login mints a resident session').not.toBeNull()

		const mine = await asResident(asUser, session!, `/my-cases?mandate=${mandateId}`)
		expect(mine.status, JSON.stringify(mine.body)).toBe(200)
		expect(JSON.stringify(mine.body.cases ?? [])).toContain(caseId)

		const steps = await asResident(
			asUser,
			session!,
			`/collections/${REGISTER}/case/${caseId}/steps?mandate=${mandateId}`,
		)
		expect(steps.status, JSON.stringify(steps.body)).toBe(200)
		expect((steps.body.steps ?? []).length, JSON.stringify(steps.body)).toBeGreaterThan(0)

		await asUser.dispose()
	})
})
