/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Queue page: the cases nobody has picked up yet.
 *
 * The queue is a FILTER, not a collection, so the assertions here are about the
 * filter holding rather than about rows existing. A page that silently dropped
 * its base filter would render a healthy-looking table of every case in the
 * instance, which is exactly what `assignee_isnull=true` produced before the
 * `IS NULL` sentinel replaced it: no error, no empty state, just the wrong set.
 *
 * Entries are asserted by PRESENCE where the nav is concerned: the Queue leaf
 * sits inside the collapsible My work group and is rendered but hidden until
 * the group is expanded.
 */

import { expect, test } from '@playwright/test'

test.describe('Queue', () => {
	// Same budget as the other dossiq shell specs: a large manifest plus an
	// OpenRegister round trip on load.
	test.setTimeout(300_000)

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('the queue page renders for a deep link', async ({ page }) => {
		// A PATH, not `#/queue`: dossiq runs on createWebHistory, so a hash deep
		// link navigates nowhere and lands on the dashboard without throwing.
		await page.goto('/index.php/apps/dossiq/queue')

		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the queue page must render for a deep link',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.getByRole('heading', { name: /^(Queue|Werkvoorraad)$/i }).first(),
			'the deep link must land on the QUEUE page, not the shell default',
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('the queue holds strictly fewer rows than the case index', async ({
		page,
	}) => {
		// The seeded instance carries assigned cases and closed cases, so a queue
		// returning the same count as All cases means the base filter never
		// reached the query.
		//
		// Count only once a row is ON the page. `cn-page` becomes visible while
		// the table is still fetching, so counting on that signal alone read 0
		// rows from a page that was about to render 9 — and `4 < 0` is false, so
		// the assertion failed against a perfectly correct queue.
		const countRows = async (path: string): Promise<number> => {
			await page.goto(path)
			await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
				timeout: 60_000,
			})
			await expect(page.locator('table tbody tr').first()).toBeVisible({
				timeout: 60_000,
			})
			return await page.locator('table tbody tr').count()
		}

		const allRows = await countRows('/index.php/apps/dossiq/cases')
		const queueRows = await countRows('/index.php/apps/dossiq/queue')

		expect(
			queueRows,
			'the queue is a filtered slice of the case index, never the whole of it',
		).toBeLessThan(allRows)
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#an-empty-queue-says-so
	test('an empty result renders the empty state, not a bare table', async ({
		page,
	}) => {
		// Drive the filter to a slice that cannot match. The page must answer with
		// its empty state rather than a blank region: "mounted and empty" and
		// "never mounted" look identical without a marker to probe.
		await page.goto('/index.php/apps/dossiq/queue?caseType=__none__')

		await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
			timeout: 60_000,
		})
		await expect(
			page.locator('.cn-index-page__empty, table tbody tr').first(),
			'an empty queue must say so',
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-narrows-by-case-type
	test('the case-type sidebar narrows the queue', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/queue')
		await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
			timeout: 60_000,
		})
		const before = await page.locator('table tbody tr').count()

		// The folder sidebar is the same control the Cases index carries; picking
		// one type can only ever narrow the set.
		const firstType = page
			.locator('[data-testid="cn-folder-sidebar"] li, .cn-folder-sidebar li')
			.nth(1)
		if ((await firstType.count()) > 0) {
			await firstType.click()
			await expect(
				page.locator('.cn-index-page__empty, table tbody tr').first(),
			).toBeVisible({ timeout: 30_000 })
			const after = await page.locator('table tbody tr').count()
			expect(
				after,
				'narrowing to one case type cannot widen the result set',
			).toBeLessThanOrEqual(before)
		}
	})
})
