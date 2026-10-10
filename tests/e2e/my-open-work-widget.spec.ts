/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-DASH-023 and REQ-DASH-024: the start page widgets answer through
 * Nextcloud's widget item API.
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
 * position or by count: the seeded titles carry the run prefix, the request
 * asks for a limit large enough to hold them, and the order assertion compares
 * the two seeded cases with each other only.
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
const MY_TASKS = 'procest_my_tasks_widget'
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

test.describe('My open work on the start page', () => {
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
	test('answers the cases and the task through the item API', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const items = (await itemsOf(api, OPEN_WORK)).filter((item) =>
			item.title.startsWith(RUN_PREFIX),
		)
		await api.dispose()

		const titles = items.map((item) => item.title)
		expect(titles).toContain(`${RUN_PREFIX} widget soon case`)
		expect(titles).toContain(`${RUN_PREFIX} widget later case`)
		expect(titles).toContain(`${RUN_PREFIX} widget open task`)

		// Soonest due first, compared between the two seeded cases only.
		expect(titles.indexOf(`${RUN_PREFIX} widget soon case`)).toBeLessThan(
			titles.indexOf(`${RUN_PREFIX} widget later case`),
		)

		const soon = items.find(
			(item) => item.title === `${RUN_PREFIX} widget soon case`,
		)
		expect(soon?.link).toContain(`/apps/dossiq/cases/${seededCases.soon}`)
	})

	// @e2e openspec/specs/dashboard/spec.md#the-start-page-shows-my-cases-and-leads-to-my-work
	test('an item opens its case, and the button opens My work', async ({
		page,
		playwright,
		baseURL,
	}) => {
		trackDossiqErrors(page)
		const api = await playwright.request.newContext({ baseURL })
		const items = await itemsOf(api, OPEN_WORK)
		const buttons = await api.get(`/ocs/v2.php/apps/dashboard/api/v1/widgets`, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		const widget = (await buttons.json()).ocs.data[OPEN_WORK]
		await api.dispose()

		const open = (widget.buttons ?? []).find(
			(button: { type: string }) => button.type === 'more',
		)
		expect(open?.text).toMatch(/^(Open my work|Naar mijn werk)$/)
		expect(open?.link).toMatch(/\/apps\/dossiq\/my-work$/)

		const soon = items.find(
			(item) => item.title === `${RUN_PREFIX} widget soon case`,
		)
		await page.goto(soon!.link, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(
			page.getByText(`${RUN_PREFIX} widget soon case`).first(),
		).toBeVisible()

		await page.goto(open.link, PAGE_LOAD)
		await expect(page).toHaveURL(/\/apps\/dossiq\/my-work/)
	})

	// @e2e openspec/specs/dashboard/spec.md#a-start-page-that-reads-items-shows-my-tasks
	test('My tasks answers its open tasks through the item API', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const items = await itemsOf(api, MY_TASKS)
		await api.dispose()

		const task = items.find(
			(item) => item.title === `${RUN_PREFIX} widget open task`,
		)
		expect(task, 'the seeded task is answered').toBeTruthy()
		expect(task?.link).toMatch(/\/apps\/dossiq\/tasks\/[^/]+$/)
	})
})
