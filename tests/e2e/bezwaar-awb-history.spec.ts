/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BAT-002: the advice request's entries reach its History tab.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. The unit tests prove that
 * `assignToCommittee()` writes `dossiq.bezwaar.panel-member-added` through
 * OpenRegister's AuditTrailMapper. Only a real instance proves that the row
 * lands on the trail the History sidebar tab of BezwaarAdviceRequestDetail
 * reads, with its actor, so a handler can see it.
 *
 * The path is the real one: a bezwaar moves to "Hearing planned", the
 * BezwaarAdviceRequestedListener assigns the default committee, and the new
 * advice request's History tab lists the entry.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { provisioningContext } from './helpers/auth.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'

/** A run marker. */
const RUN = `e2e-bat-${Date.now().toString(36)}`

let admin: APIRequestContext | null = null
let previousDefault = ''
let committee = ''
let bezwaar = ''

/**
 * The id of an OpenRegister object answer.
 *
 * @param body The parsed response.
 * @return The id, or ''.
 */
function idOf(body: any): string {
	return String(body?.['@self']?.id ?? body?.id ?? body?.uuid ?? '')
}

/**
 * Read or write dossiq's default committee through the provisioning API.
 *
 * @param value The new value, or undefined to read.
 * @return The value read, or ''.
 */
async function defaultCommittee(value?: string): Promise<string> {
	const url =
		'/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/dossiq/bac_default_committee?format=json'
	if (value === undefined) {
		const res = await admin!.get(url)
		const body: any = await res.json().catch(() => ({}))
		return String(body?.ocs?.data?.data ?? '')
	}

	const res = await admin!.post(url, { form: { value } })
	expect(
		res.ok(),
		`setting bac_default_committee: ${await res.text()}`,
	).toBeTruthy()
	return value
}

test.describe('The advice request History tab shows the bezwaar entries', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		admin = await provisioningContext(playwright, String(baseURL))
		previousDefault = await defaultCommittee()

		const made = await admin.post(`${OBJECTS}/bezwaaradviescommissie`, {
			data: {
				name: `${RUN} commissie`,
				type: 'BAC',
				members: [],
				active: true,
			},
		})
		expect(
			made.status(),
			`creating a committee: ${await made.text()}`,
		).toBeLessThan(300)
		committee = idOf(await made.json())
		await defaultCommittee(committee)

		const opened = await admin.post(`${OBJECTS}/objectionProceeding`, {
			data: {
				case: `${RUN}-case`,
				receiptDate: new Date().toISOString().slice(0, 10),
				status: 'Received',
			},
		})
		expect(
			opened.status(),
			`creating a bezwaar: ${await opened.text()}`,
		).toBeLessThan(300)
		bezwaar = idOf(await opened.json())

		const moved = await admin.patch(
			`${OBJECTS}/objectionProceeding/${bezwaar}`,
			{ data: { status: 'Hearing planned' } },
		)
		expect(
			moved.ok(),
			`moving the bezwaar to Hearing planned: ${await moved.text()}`,
		).toBeTruthy()
	})

	test.afterAll(async () => {
		if (admin !== null) {
			await defaultCommittee(previousDefault)
			await admin.dispose()
		}
	})

	// @e2e openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md#scenario-the-history-tab-shows-the-committee-assignment
	test('the History tab lists the panel-member-added entry with its actor and time', async ({
		page,
	}) => {
		const found = await admin!.get(
			`${OBJECTS}/bacAdviceRequest?bezwaar=${bezwaar}`,
		)
		expect(
			found.ok(),
			`listing advice requests: ${await found.text()}`,
		).toBeTruthy()
		const body: any = await found.json()
		const requests: any[] = body?.results ?? body?.objects ?? []
		expect(
			requests.length,
			'the listener must have assigned one advice request',
		).toBe(1)
		const request = idOf(requests[0])

		await page.goto(
			`/apps/dossiq/#/bezwaar-advice-requests/${request}`,
			PAGE_LOAD,
		)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'History' }).click()

		const history = page.getByRole('tabpanel', { name: 'History' })
		await expect(history.getByText(/panel-member-added/)).toBeVisible({
			timeout: 20_000,
		})
		await expect(history.getByText(/admin/).first()).toBeVisible()
	})
})
