/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An adaptive case plan lives in OpenRegister, and dossiq's own engine is gone.
 *
 * Three things only a running instance can show. The case page of a case
 * whose type is driven by the case layer renders the plan OpenRegister holds
 * and asks dossiq's retired routes nothing. A caseworker completes an item
 * from the panel and the page shows the state OpenRegister answers. And the
 * retired routes answer 404.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every locator is a `data-testid` or a `data-state`, and the only text
 * asserted is text this fixture seeded.
 *
 * WHAT IS NOT HERE. An illegal transition and an unreachable OpenRegister are
 * excluded in the spec and carry their proof in
 * `tests/vitest/casePlanPanel.spec.js`. The drain and its proof left with the
 * `casePlanState` property; `tests/Unit/Service/CmmnRetirementTest.php` keeps
 * both out.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	FIXTURE_SCHEMAS,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The plan every case of this suite gets: one stage holding two tasks. */
const PLAN_ITEMS = [
	{ id: 'intake', type: 'stage', name: `${RUN_PREFIX} Intake` },
	{
		id: 'controle',
		type: 'humanTask',
		name: `${RUN_PREFIX} Controle`,
		parentId: 'intake',
	},
	{
		id: 'advies',
		type: 'humanTask',
		name: `${RUN_PREFIX} Advies`,
		parentId: 'intake',
	},
]

/** The case started under the published model. */
let liveCase = ''

test.describe('The adaptive case plan after the local engine retired', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Adaptief`,
				identifier: `${RUN_PREFIX.toLowerCase()}-adaptief`,
				description: 'Throwaway caseType for the cmmn-retirement e2e layer.',
				handlingModel: 'cmmn',
				isDraft: false,
			}),
		)

		const seed = async (key: string) =>
			objectId(
				await seedCase(api, token, {
					title: `${RUN_PREFIX} ${key}`,
					caseType,
					startDate: new Date().toISOString().slice(0, 10),
				}),
			)

		await createObject(api, token, 'caseModel', {
			title: `${RUN_PREFIX} Plan`,
			caseType,
			lifecycleStatus: 'published',
			planItems: PLAN_ITEMS,
		})

		// The case gets its plan in OpenRegister at case start.
		liveCase = await seed('live')

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token, ['caseModel', ...FIXTURE_SCHEMAS])
		await api.dispose()
	})

	/**
	 * The row of one plan item inside the panel.
	 *
	 * @param page The page.
	 * @param key  The plan item's id in the model.
	 */
	const row = (page: any, key: string) =>
		page
			.getByTestId('case-plan')
			.locator('.case-plan__row')
			.filter({
				hasText: PLAN_ITEMS.find((item) => item.id === key)?.name ?? key,
			})

	// @e2e openspec/specs/retire-cmmn-caseplanstate/spec.md#a-cmmn-case-shows-the-plan-openregister-holds
	// @e2e openspec/specs/retire-cmmn-caseplanstate/spec.md#working-a-plan-item-through-the-panel
	test('a case shows the plan OpenRegister holds, and an item is worked from the panel', async ({
		page,
	}) => {
		const retiredCalls: string[] = []
		const planCalls: string[] = []
		page.on('request', (r) => {
			if (/\/apps\/dossiq\/api\/case\/[^/]+\/cmmn-plan/.test(r.url())) {
				retiredCalls.push(r.url())
			}
			if (/\/apps\/openregister\/api\/cases\//.test(r.url())) {
				planCalls.push(`${r.method()} ${r.url()}`)
			}
		})

		await page.goto(`/apps/${REGISTER}/cases/${liveCase}`)
		await expect(page.getByTestId('case-plan')).toBeVisible(PAGE_LOAD)

		for (const item of PLAN_ITEMS) {
			await expect(row(page, item.id)).toBeVisible({ timeout: 30_000 })
		}
		await expect(page.getByTestId('case-plan-error')).toHaveCount(0)
		expect(
			planCalls.some((call) => call.includes(liveCase)),
			'the plan is read from OpenRegister',
		).toBe(true)

		// The panel offers the move; OpenRegister takes it.
		await page.getByTestId('case-plan-completed-controle').click()
		await expect(
			row(page, 'controle').locator('.case-plan__state'),
		).toHaveAttribute('data-state', 'completed', { timeout: 30_000 })
		expect(
			planCalls.some((call) => /^POST .*\/transition/.test(call)),
			'the completion went to OpenRegister',
		).toBe(true)

		expect(retiredCalls, 'nothing asks the retired routes').toEqual([])
	})

	// @e2e openspec/specs/retire-cmmn-caseplanstate/spec.md#the-old-routes-answer-404-after-removal
	test('the retired routes answer 404 and name no engine', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const read = await api.get(
			`/index.php/apps/${REGISTER}/api/case/${liveCase}/cmmn-plan`,
		)
		expect(read.status()).toBe(404)
		expect(await read.text()).not.toMatch(/cmmn|caseModelEngine/i)

		for (const verb of ['enable', 'complete', 'terminate', 'signal']) {
			const write = await api.post(
				`/index.php/apps/${REGISTER}/api/case/${liveCase}/cmmn-plan/${verb}`,
				{ headers: { requesttoken: token }, data: { planItemId: 'advies' } },
			)
			expect(write.status(), `${verb} is gone`).toBe(404)
		}

		await api.dispose()
	})
})
