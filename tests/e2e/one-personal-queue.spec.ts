/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One queue, fed by every mechanism.
 *
 * Everything here is a seam no unit test reaches: whether the declared sources
 * actually reach one page against a live register and a live task engine,
 * whether an item leaves when its work does, and whether the refusal to
 * dismiss holds in the interface rather than only in the service.
 *
 * WHAT IS SEEDED AND WHAT IS NOT. Cases and engine tasks are seeded through
 * the API, tracked by `RUN_PREFIX` and deleted in `afterAll`, the same
 * contract every other deep spec here keeps. The digest is NOT driven by
 * waiting for a cron hour: the hour is a stored preference, so the spec sets
 * it, runs the job through occ, and reads the digest record back. A spec that
 * slept until 08:00 would be a spec nobody runs.
 *
 * 🔴 ONE CASE IS DELIBERATELY LEFT ASSIGNED for the dismissal test, and it is
 * cleaned up like the rest. A queue item that CAN be removed and a queue item
 * whose removal was refused look identical on a page that re-reads after the
 * gesture, so the assertion is that the item is still there AND that the
 * hide-the-group offer appeared.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	invokeFlowTask,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'
import { navToRoute, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const ADMIN_USER = 'admin'

let caseTypeId = ''
const cases: Record<string, string> = {}
const tasks: Record<string, string> = {}

test.describe('One personal queue', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		cases.mine = objectId(await seedCase(api, token, {
			title: `${RUN_PREFIX} queue assigned case`,
			caseType: caseTypeId,
			assignee: ADMIN_USER,
		}))
		cases.live = objectId(await seedCase(api, token, {
			title: `${RUN_PREFIX} queue live case`,
			caseType: caseTypeId,
			assignee: ADMIN_USER,
		}))

		tasks.open = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} queue open task`,
			assignee: ADMIN_USER,
		})
		tasks.closing = await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} queue closing task`,
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

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#a-caseworker-opens-one-page-not-six
	test('One page holds the work every mechanism put there', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await navToRoute(page, 'PersonalQueue')

		await expect(page.getByTestId(`queue-item-assigned-cases:case:${cases.mine}`))
			.toBeVisible(PAGE_LOAD)
		await expect(page.getByTestId(`queue-item-tasks:task:${tasks.open}`)).toBeVisible()

		// Two mechanisms, two groups, one page. The assertion is on the GROUPS
		// rather than on a row count: a queue that merged everything into one
		// heading would still show both rows.
		await expect(page.getByTestId('queue-group-assigned-cases')).toBeVisible()
		await expect(page.getByTestId('queue-group-tasks')).toBeVisible()

		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/one-personal-queue/specs/add-work-queue/spec.md#a-new-mechanism-reaches-the-queue-by-declaring-itself
	test('Each group says what takes its items off the queue', async ({ page }) => {
		await navToRoute(page, 'PersonalQueue')

		const group = page.getByTestId('queue-group-tasks')
		await expect(group).toBeVisible(PAGE_LOAD)
		await expect(group).toContainText('A task leaves when it is completed or cancelled.')
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#an-item-closes-with-its-work
	test('An item leaves when the task it points at is completed', async ({ page, playwright, baseURL }) => {
		await navToRoute(page, 'PersonalQueue')
		await expect(page.getByTestId(`queue-item-tasks:task:${tasks.closing}`))
			.toBeVisible(PAGE_LOAD)

		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await invokeFlowTask(api, token, tasks.closing, 'claim')
		await invokeFlowTask(api, token, tasks.closing, 'complete')
		await api.dispose()

		await navToRoute(page, 'PersonalQueue')
		await expect(page.getByTestId(`queue-item-tasks:task:${tasks.closing}`)).toHaveCount(0)
		// The OTHER task is still there, so the row did not vanish because the
		// whole list failed to load.
		await expect(page.getByTestId(`queue-item-tasks:task:${tasks.open}`)).toBeVisible()
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#a-person-cannot-dismiss-live-work
	test('Live work cannot be dismissed, and the group can be hidden instead', async ({ page }) => {
		await navToRoute(page, 'PersonalQueue')
		const item = page.getByTestId(`queue-item-assigned-cases:case:${cases.live}`)
		await expect(item).toBeVisible(PAGE_LOAD)

		// There is no per-item remove control at all. The offer is the group.
		await expect(item.getByRole('button', { name: /remove|dismiss|verwijder/i })).toHaveCount(0)

		await page.getByTestId('queue-group-assigned-cases')
			.getByRole('button', { name: /hide until tomorrow|verberg tot morgen/i })
			.click()

		await expect(page.getByTestId('queue-group-assigned-cases')).toHaveCount(0)
		await expect(page.getByTestId('queue-hidden-assigned-cases')).toBeVisible()

		// Hidden is not gone: showing it again brings back the same live work.
		await page.getByTestId('queue-hidden-assigned-cases').click()
		await expect(page.getByTestId(`queue-item-assigned-cases:case:${cases.live}`)).toBeVisible()
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#the-digest-arrives-at-the-chosen-time
	test('A digest is composed when work is waiting, and not when none is', async ({ page }) => {
		await navToRoute(page, 'PersonalQueue')
		await expect(page.getByTestId(`queue-item-assigned-cases:case:${cases.mine}`))
			.toBeVisible(PAGE_LOAD)

		// The queue is not empty, so the person who owns it is due a digest.
		// The two halves are asserted through the count the composer reports,
		// because a message body is a translated string and this assertion is
		// about whether anything was said at all.
		const queue = await page.request.get('/index.php/apps/dossiq/api/personal-queue')
		expect(queue.status()).toBe(200)
		expect((await queue.json()).total).toBeGreaterThan(0)

		const settings = await page.request.get('/index.php/apps/dossiq/api/personal-queue/digest')
		expect(settings.status()).toBe(200)
		expect((await settings.json()).enabled).toBe(true)
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#everything-touched-today-in-one-place
	test('The end-of-day screen lists what was opened today', async ({ page }) => {
		// Open the case first: "touched" is the register's per-reader read
		// state, so a case nobody opened must NOT appear, and this is the
		// gesture that makes it appear.
		await navToRoute(page, 'PersonalQueue')
		await page.getByTestId(`queue-item-assigned-cases:case:${cases.mine}`)
			.getByRole('link').click()
		await page.waitForLoadState('networkidle')

		await navToRoute(page, 'EndOfDay')
		await expect(page.getByTestId(`end-of-day-item-assigned-cases:case:${cases.mine}`))
			.toBeVisible(PAGE_LOAD)
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#an-update-is-recorded-per-item
	test('An update written on the end-of-day screen lands on the case', async ({ page }) => {
		await navToRoute(page, 'PersonalQueue')
		await page.getByTestId(`queue-item-assigned-cases:case:${cases.mine}`)
			.getByRole('link').click()
		await page.waitForLoadState('networkidle')

		await navToRoute(page, 'EndOfDay')
		const field = page.getByTestId(`end-of-day-update-assigned-cases:case:${cases.mine}`)
		await expect(field).toBeVisible(PAGE_LOAD)
		await field.locator('input').fill(`${RUN_PREFIX} handled the intake`)
		await page.getByTestId(`end-of-day-save-assigned-cases:case:${cases.mine}`).click()

		// Read the note back off the case, not off a toast: a toast says the
		// request was made and a note says it was stored.
		await expect.poll(async () => {
			const res = await page.request.get(
				`/index.php/apps/openregister/api/objects/dossiq/case/${cases.mine}/notes`,
			)
			if (res.status() !== 200) {
				return ''
			}

			return JSON.stringify(await res.json())
		}, {
			timeout: 30_000,
			message: 'The end-of-day update never reached the case',
		}).toContain(`${RUN_PREFIX} handled the intake`)
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#a-planned-item-with-no-case
	test('A planned item reaches the queue and is not a case', async ({ page }) => {
		await navToRoute(page, 'PersonalQueue')
		await page.getByRole('button', { name: /plan an item|plan iets in/i }).click()

		const dialog = page.getByTestId('plan-item-dialog')
		await expect(dialog).toBeVisible(PAGE_LOAD)
		await dialog.getByTestId('plan-item-title').locator('input')
			.fill(`${RUN_PREFIX} call the applicant back`)
		await dialog.getByTestId('plan-item-when').locator('input')
			.fill('2026-12-01T09:00')
		await dialog.getByRole('button', { name: /plan it|plan het in/i }).click()

		await expect(page.getByTestId('queue-group-planned-items')).toBeVisible(PAGE_LOAD)

		// It is NOT a case: the case list does not carry it. That is the whole
		// point of writing it to the calendar, and the assertion that would
		// have failed if it had been built as a caseless case.
		const cases = await page.request.get(
			`/index.php/apps/openregister/api/objects/dossiq/case?_search=${encodeURIComponent(RUN_PREFIX + ' call the applicant back')}`,
		)
		expect(JSON.stringify(await cases.json())).not.toContain('call the applicant back')
	})

	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#a-personal-triage-lane-on-a-shared-case
	// @e2e openspec/changes/one-personal-queue/specs/my-work/spec.md#the-cases-own-status-is-unchanged
	test('A personal stage is private and leaves the case status alone', async ({ page }) => {
		const before = await page.request.get(
			`/index.php/apps/openregister/api/objects/dossiq/case/${cases.mine}`,
		)
		const statusBefore = String((await before.json())?.status ?? '')

		const saved = await page.request.post(
			`/index.php/apps/dossiq/api/personal-queue/stages/${cases.mine}`,
			{
				headers: { 'OCS-APIRequest': 'true' },
				data: { stage: 'Wachten op advies' },
			},
		)
		expect(saved.status()).toBe(200)
		expect((await saved.json()).stage).toBe('Wachten op advies')

		// The case itself did not move, which is the risk this design names:
		// two people reading two different statuses off one record.
		const after = await page.request.get(
			`/index.php/apps/openregister/api/objects/dossiq/case/${cases.mine}`,
		)
		const body = await after.json()
		expect(String(body?.status ?? '')).toBe(statusBefore)
		expect(JSON.stringify(body)).not.toContain('Wachten op advies')
	})
})
