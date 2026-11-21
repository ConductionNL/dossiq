/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A member of a case type's handling team opens an unclaimed case of that type
 * and claims it from the queue. The member holds no admin rights: the read
 * comes from the case type naming `behandelaars` as its handling team, the
 * branch of CaseAccessGuard::hasCaseReadAccess this change added.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, login } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'
import { occRun } from './helpers/occ.ts'

const TEAM = 'behandelaars'
const PASSWORD = 'handling-team-e2e-Pass-1'
const MEMBER = `${RUN_PREFIX.toLowerCase()}-wb`.replace(/[^a-z0-9-]/g, '')

let api: APIRequestContext
let token = ''
let caseId = ''
const title = `${RUN_PREFIX} unclaimed Woo request`

test.describe('A handling team member claims an unclaimed case from the queue', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const types = await api.get(`/index.php/apps/openregister/api/objects/${REGISTER}/caseType?identifier=woo-verzoek&_limit=10`)
		const body: any = await types.json()
		const woo = (body?.results ?? []).find((row: any) => row?.handling?.defaultGroup === TEAM && row?.isDraft !== true)
		expect(woo, 'no published Woo request case type handled by behandelaars on this instance').toBeTruthy()

		await ensureUser(api, token, MEMBER, PASSWORD)
		const joined = await occRun(['group:adduser', TEAM, MEMBER])
		expect(joined.code, joined.output).toBe(0)

		caseId = objectId(await seedCase(api, token, { title, caseType: objectId(woo) }))
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['case'])
		await occRun(['user:delete', MEMBER])
		await api.dispose()
	})

	test('the member opens the case, claims it from the queue and the queue drops it', async ({ browser, baseURL }) => {
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		await login(page, MEMBER, PASSWORD)

		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })
		await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 30_000 })

		await page.goto(`/apps/${REGISTER}/queue`, PAGE_LOAD)
		const row = page.locator('tbody tr').filter({ hasText: title })
		await expect(row).toHaveCount(1, { timeout: 30_000 })

		await row.locator('button').last().click()
		const menu = page.locator('[role="menu"]').last()
		await expect(menu).toBeVisible({ timeout: 10_000 })
		await menu.locator('[role="menuitem"], li').filter({ hasText: /Claim|Oppakken/ }).first().click()

		await expect
			.poll(async () => String((await showObject(api, 'case', caseId)).assignee ?? ''), {
				timeout: 30_000,
				message: 'the claim did not put the case in the member hands',
			})
			.toBe(MEMBER)
		await expect(row).toHaveCount(0, { timeout: 30_000 })
		await context.close()
	})
})
