/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-widgets-declare-who-may-see-them task 4.1: a dashboard widget declared
 * for one group is not offered to, nor read for, a user outside it, and is for
 * a member (REQ-DWV-002, REQ-DWV-004).
 *
 * The probe reads Nextcloud's own dashboard API rather than one dashboard's
 * screen: the widget list it answers is what every dashboard, launchpad's add
 * widget dialog included, picks from, so a widget missing there cannot be
 * placed anywhere. The item read is the second half: a placement made before
 * the declaration must not render cases either.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, provisioningContext } from './helpers/auth.ts'

/** The widget the scenarios narrow. */
const WIDGET = 'procest_stalled_cases_widget'

/** The group it is narrowed to. */
const GROUP = 'e2e-vergunningen'

/** A member of that group, and an account outside it. */
const MEMBER = 'e2e-widget-member'
const OUTSIDER = 'e2e-widget-outsider'

/** Both accounts' password. Must satisfy the instance's password policy. */
const PASSWORD = 'e2e-Widget-2026!'

/** The admin-only audience endpoint. */
const AUDIENCE = '/index.php/apps/dossiq/api/settings/widget-audience'

/** Nextcloud's dashboard API: the widgets a user may place, and their items. */
const WIDGETS = '/ocs/v2.php/apps/dashboard/api/v1/widgets?format=json'
const ITEMS = `/ocs/v2.php/apps/dashboard/api/v1/widget-items?format=json&widgets[]=${WIDGET}`

/**
 * A request context logged in with basic credentials and no session.
 *
 * @param playwright The Playwright fixture.
 * @param baseURL    The instance.
 * @param user       The account.
 *
 * @return The context.
 */
async function as(playwright: any, baseURL: string, user: string): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${PASSWORD}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
		},
	})
}

/**
 * The widget ids a user may place.
 *
 * @param api The user's context.
 *
 * @return The ids.
 */
async function placeable(api: APIRequestContext): Promise<string[]> {
	const res = await api.get(WIDGETS)
	expect(res.ok(), `the dashboard API must answer the widget list; got ${res.status()}`).toBeTruthy()
	const body: any = await res.json()

	return Object.keys(body?.ocs?.data ?? {})
}

test.describe('A case widget declares who may see it', () => {
	test.setTimeout(180_000)

	let admin: APIRequestContext
	let member: APIRequestContext
	let outsider: APIRequestContext

	test.beforeAll(async ({ playwright, baseURL }) => {
		admin = await provisioningContext(playwright, String(baseURL))
		await ensureUser(admin, '', MEMBER, PASSWORD)
		await ensureUser(admin, '', OUTSIDER, PASSWORD)
		await admin.post('/ocs/v2.php/cloud/groups?format=json', { form: { groupid: GROUP } })
		await admin.post(`/ocs/v2.php/cloud/users/${MEMBER}/groups?format=json`, { form: { groupid: GROUP } })

		member = await as(playwright, String(baseURL), MEMBER)
		outsider = await as(playwright, String(baseURL), OUTSIDER)
	})

	test.afterAll(async () => {
		// Open the widget to everybody again, so the next suite meets the shipped default.
		await admin?.put(AUDIENCE, { data: { [WIDGET]: '' } })
		await Promise.all([admin?.dispose(), member?.dispose(), outsider?.dispose()])
	})

	// @e2e openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#scenario-narrowing-a-widget
	test('an administrator narrows a widget and reads the groups back', async () => {
		const saved = await admin.put(AUDIENCE, { data: { [WIDGET]: GROUP } })
		expect(saved.status()).toBe(200)

		const read: any = await (await admin.get(AUDIENCE)).json()
		expect(read[WIDGET]).toBe(GROUP)
	})

	// @e2e openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#requirement-req-dwv-004-an-administrator-sets-the-audience-per-widget
	test('a non-administrator cannot change the audience', async () => {
		const res = await outsider.put(AUDIENCE, { data: { [WIDGET]: '' } })
		expect(res.status(), 'the server refuses the save, whatever the page shows').toBeGreaterThanOrEqual(401)
		expect(res.status()).toBeLessThan(500)

		const read: any = await (await admin.get(AUDIENCE)).json()
		expect(read[WIDGET], 'the refused save left the audience as it was').toBe(GROUP)
	})

	// @e2e openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#scenario-launchpads-widget-picker
	test('the widget is offered to a member and not to an outsider', async () => {
		await admin.put(AUDIENCE, { data: { [WIDGET]: GROUP } })

		expect(await placeable(member)).toContain(WIDGET)
		expect(await placeable(outsider)).not.toContain(WIDGET)
	})

	// @e2e openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md#scenario-a-direct-item-read
	test('an outsider reading the items directly gets no case', async () => {
		await admin.put(AUDIENCE, { data: { [WIDGET]: GROUP } })

		const res = await outsider.get(ITEMS)
		const body: any = await res.json().catch(() => ({}))
		const items = body?.ocs?.data?.[WIDGET] ?? []
		expect(items, 'no item may reach a user outside the audience').toEqual([])
	})
})
