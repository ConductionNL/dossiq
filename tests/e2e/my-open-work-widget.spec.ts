/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-DASH-023: the Mijn werk widget answers through Nextcloud's widget item
 * API, as summary rows (board DqNextcloudDashboard).
 *
 * WHY THROUGH THE OCS ENDPOINT AND NOT THE DASHBOARD PAGE. The requirement is
 * that a host WITHOUT dossiq's bundle can render the widget: the mobile apps,
 * launchpad, any start page that reads items. The endpoint is what all of them
 * call, so it is the subject. The one browser step afterwards follows an item's
 * link, because a link that points at a page that does not exist passes every
 * assertion on the JSON.
 *
 * THE QUEUE IS THE ADMIN'S, AND IT IS SHARED. The admin's queue also holds the
 * demo caseload and other runs' fixtures, so nothing here is addressed by
 * exact count: the assertions read the rows' shape, and the seeded cases and
 * task make sure each row is there.
 *
 * Runs against the nightly instance, not in the build loop.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const ADMIN_USER = 'admin'
const OPEN_WORK = 'dossiq_my_open_work_widget'
const ITEMS_API = '/ocs/v2.php/apps/dashboard/api/v2/widget-items'

/** Large enough that the seeded items are not cut off by the shared queue. */
const LIMIT = 200

interface Item {
	title: string
	link: string
	subtitle: string
}

const seededCases: Record<string, string> = {}

/**
 * The items one widget answers for the signed-in admin.
 *
 * @param api The admin's request context.
 * @param widget The widget id.
 * @return The items.
 */
async function itemsOf(api: APIRequestContext, widget: string): Promise<Item[]> {
	const response = await api.get(
		`${ITEMS_API}?widgets[]=${widget}&limit=${LIMIT}`,
		{
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		},
	)
	expect(response.status(), `widget items for ${widget}`).toBe(200)
	const body = await response.json()
	return (body.ocs.data[widget]?.items ?? []) as Item[]
}

test.describe('Mijn werk on the start page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const caseTypeId = (await ensureCaseType(api, token)).id

		seededCases.soon = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} widget soon case`,
				caseType: caseTypeId,
				assignee: ADMIN_USER,
				deadline: '2030-01-10',
			}),
		)
		seededCases.later = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} widget later case`,
				caseType: caseTypeId,
				assignee: ADMIN_USER,
				deadline: '2030-02-10',
			}),
		)
		await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} widget open task`,
			assignee: ADMIN_USER,
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/dashboard/spec.md#items-reach-a-host-without-dossiqs-bundle
	test('answers a cases row and a tasks row through the item API', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const items = await itemsOf(api, OPEN_WORK)
		await api.dispose()

		const titles = items.map((item) => item.title)
		expect(titles[0]).toMatch(/^\d+ open (cases? on your name|zaak op uw naam|zaken op uw naam)$/)
		expect(titles[1]).toMatch(/^\d+ open (tasks?|taak|taken)$/)
		expect(items[1].subtitle).toMatch(/^\d+ (late|te laat), \d+ (today|vandaag)$/)
		for (const item of items) {
			expect(item.link).toMatch(/\/apps\/dossiq\/my-work$/)
		}
	})

	// @e2e openspec/specs/dashboard/spec.md#the-start-page-shows-my-cases-and-leads-to-my-work
	test('the button opens My work', async ({ page, playwright, baseURL }) => {
		trackDossiqErrors(page)
		const api = await playwright.request.newContext({ baseURL })
		const buttons = await api.get(`/ocs/v2.php/apps/dashboard/api/v1/widgets`, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		const widget = (await buttons.json()).ocs.data[OPEN_WORK]
		await api.dispose()

		const open = (widget.buttons ?? []).find(
			(button: { type: string }) => button.type === 'more',
		)
		expect(open?.text).toMatch(/^(Go to My work|Naar Mijn werk)$/)
		expect(open?.link).toMatch(/\/apps\/dossiq\/my-work$/)

		await page.goto(open.link, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page).toHaveURL(/\/apps\/dossiq\/my-work/)
	})
})
