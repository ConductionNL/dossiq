/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Taking a case, and giving it back.
 *
 * Everything here is a seam no unit test reaches. The vitest spec can show
 * that the manifest declares an `api-call` at a url a route answers; only a
 * browser can show that pressing the button in the header actions menu moves
 * `assignee` on the stored case, and that the same gesture on a queue row
 * takes that row out of the queue.
 *
 * THE REFUSAL IS ASSERTED OVER THE API, NOT OFF A TOAST. Nothing forces the
 * language of the E2E instance, and the refusal sentence is translated, so an
 * assertion on the toast text would be an assertion about the instance's
 * locale. The status and the `code` are the same in every language, and they
 * are what the surfaces act on.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN, which passes `CaseAccessGuard` on any case.
 * That is why the refusals asserted here are the ones the ASSIGNMENT rule
 * makes (a case somebody else holds, a case nobody holds) rather than the
 * per-case authorization ones: an admin cannot take a lesser role in a browser
 * and those branches belong to CaseAssignmentControllerTest.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The uid Playwright signs in as, read once off the loaded page. */
const ADMIN_USER = 'admin'

/** A colleague who holds one of the seeded cases, so a claim on it is refused. */
const COLLEAGUE = `${RUN_PREFIX}-collega`

let caseTypeId = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

test.describe('Claim and release a case', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		const seed = async (key: string, assignee?: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} Claim ${key}`,
				caseType: caseTypeId,
				description: 'Throwaway case for the case-claim-action e2e layer.',
				startDate: new Date().toISOString().slice(0, 10),
				...(assignee === undefined ? {} : { assignee }),
			})
			cases[key] = objectId(row)
		}

		// Unclaimed: the queue's own condition is `assignee IS NULL`, so these
		// are seeded with the field absent rather than with an empty string.
		await seed('page')
		await seed('queue')
		await seed('taken', COLLEAGUE)
		await seed('mine', ADMIN_USER)

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one seeded case and wait for the detail page to have rendered.
	 *
	 * @param page The Playwright page.
	 * @param key Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
	}

	/**
	 * The assignee stored on a case right now.
	 *
	 * @param api An authenticated request context.
	 * @param id The case id.
	 * @return The assignee, or an empty string when nobody holds the case.
	 */
	const storedAssignee = async (api: any, id: string): Promise<string> =>
		String((await showObject(api, 'case', id)).assignee ?? '')

	// @e2e openspec/changes/case-claim-action/specs/case-management/spec.md#claim-from-the-case-page
	test('Claim on the case page puts the case in your hands', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'page')

		await clickHeaderAction(page, 'cn-action-case-claim')

		const api = await playwright.request.newContext({ baseURL })
		await expect
			.poll(async () => await storedAssignee(api, cases.page), {
				timeout: 30_000,
				message: 'Claim did not write the signed-in user onto the case',
			})
			.toBe(ADMIN_USER)

		// And the gesture does not repeat: a second claim on a case you already
		// hold is refused, rather than quietly rewriting the same field and
		// adding an audit row that says nothing happened.
		const token = await getRequestToken(api)
		const again = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.page}/claim`,
			{ headers: { requesttoken: token } },
		)
		expect(again.status()).toBe(409)
		expect((await again.json()).code).toBe('already_yours')
		expect(await storedAssignee(api, cases.page)).toBe(ADMIN_USER)
		await api.dispose()

		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/case-claim-action/specs/case-management/spec.md#claim-from-the-queue
	test('Claim on a queue row takes the case out of the queue', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)
		const title = `${RUN_PREFIX} Claim queue`

		// The queue is `assignee IS NULL` + `isFinalStatus false`, so the
		// seeded case is on it by construction.
		await page.goto(`/apps/${REGISTER}/queue`, PAGE_LOAD)
		const row = page.locator('tbody tr').filter({ hasText: title })
		await expect(row).toHaveCount(1, { timeout: 30_000 })

		// The overflow trigger is the only button in the row-actions cell.
		await row.locator('button').last().click()
		const menu = page.locator('[role="menu"]').last()
		await expect(menu).toBeVisible({ timeout: 10_000 })
		await menu
			.locator('[role="menuitem"], li')
			.filter({ hasText: /Claim|Oppakken/ })
			.first()
			.click()

		const api = await playwright.request.newContext({ baseURL })
		await expect
			.poll(async () => await storedAssignee(api, cases.queue), {
				timeout: 30_000,
				message: 'the row action did not claim the case it was used on',
			})
			.toBe(ADMIN_USER)
		await api.dispose()

		// The row leaves the queue: the list re-reads through its live
		// collection subscription, and a claimed case no longer answers the
		// page's own filter.
		await expect(row).toHaveCount(0, { timeout: 30_000 })

		// And it is on Cases under Mine.
		await page.goto(`/apps/${REGISTER}/cases`, PAGE_LOAD)
		await page
			.locator('[role="tab"], button')
			.filter({ hasText: /^(Mine|Van mij)$/ })
			.first()
			.click()
		await expect(
			page.locator('tbody tr').filter({ hasText: title }),
		).toHaveCount(1, { timeout: 30_000 })

		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/case-claim-action/specs/case-management/spec.md#release-returns-the-case-to-the-queue
	test('Release gives the case back to the queue', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'mine')

		await clickHeaderAction(page, 'cn-action-case-release')

		const api = await playwright.request.newContext({ baseURL })
		await expect
			.poll(async () => await storedAssignee(api, cases.mine), {
				timeout: 30_000,
				message: 'Release did not clear the assignee',
			})
			.toBe('')
		await api.dispose()

		await page.goto(`/apps/${REGISTER}/queue`, PAGE_LOAD)
		await expect(
			page.locator('tbody tr').filter({ hasText: `${RUN_PREFIX} Claim mine` }),
		).toHaveCount(1, { timeout: 30_000 })

		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/case-claim-action/specs/case-management/spec.md#claim-from-the-case-page
	test('a claim on a case somebody else holds is refused and changes nothing', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const refused = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.taken}/claim`,
			{ headers: { requesttoken: token } },
		)

		expect(refused.status()).toBe(409)
		expect((await refused.json()).code).toBe('already_assigned')
		expect(await storedAssignee(api, cases.taken)).toBe(COLLEAGUE)

		// The same rule from the other side: a release of a case that is not
		// yours leaves the handler where they were.
		const notYours = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.taken}/release`,
			{ headers: { requesttoken: token } },
		)
		expect([403, 409]).toContain(notYours.status())
		expect(await storedAssignee(api, cases.taken)).toBe(COLLEAGUE)

		await api.dispose()
	})
})
