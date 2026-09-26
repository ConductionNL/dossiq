/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reader arranges the dashboard they read.
 *
 * Five dashboards ship in this app and every one of them was the same page for
 * everybody. The library keeps a user's arrangement per page and merges it over
 * the manifest, so geometry becomes the reader's while membership stays the
 * administrator's. This app declares the key and adds no code path.
 *
 * THE LIBRARY PUBLISHED, SO THIS RUNS FOR REAL. It was written on 2026-09-18 to
 * be red: neither the installed `@conduction/nextcloud-vue` 3.2.0 nor the newest
 * published 3.3.0 carried `mergeUserLayout`, the `dashboardLayouts` store
 * plugin, `listUserAddableWidgetTypes` or `userWidgetPresets`. All of it sat on
 * nextcloud-vue `parity/round2` (#1209) and in no release. 3.4.0 ships the four
 * of them, read out of the published tarball on 2026-09-19, and this app's
 * lockfile moved to it in the same commit as this paragraph. What the spec
 * drives is unchanged, because the reason it was written this way has not
 * changed: a page carrying `userLayout` under a library that does not read the
 * key renders exactly the page that never mentioned it and says nothing, which
 * is the one state this spec exists to tell apart from the feature working. So
 * it stays on the real surface rather than skipped into a silence where nobody
 * would notice it clearing.
 *
 * 🔴 THIS IS ONE USER, BECAUSE A BROWSER IS ONE USER. The spec's first
 * scenario is written about two handlers, and the half that needs two is the
 * isolation: one reader's arrangement must not reach another's page. The store
 * plugin keys the preference per user and per page and is asserted at that
 * edge by the library's own `tests/store/plugins/dashboardLayouts.spec.js`,
 * named here so nobody has to guess whether it is covered. What is driven
 * below is the signed-in reader's own arrangement: moved, reloaded, and still
 * where they left it.
 *
 * THE WIDGET IS ADDRESSED BY ITS DECLARED ID, NEVER BY POSITION. Position is
 * the thing under test, so a locator that reads it cannot also assert it.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const DASHBOARD_URL = `/apps/${REGISTER}/#/dashboard`

/** The card the reader drags, declared on the Dashboard page as `kpi-overdue`. */
const MOVED_WIDGET = 'kpi-overdue'

/**
 * The grid cells in document order, by their declared widget id.
 *
 * @param page The Playwright page.
 * @return The widget ids the grid renders, in the order it renders them.
 */
async function widgetOrder(page: Page): Promise<string[]> {
	return await page
		.locator('[data-widget-id]')
		.evaluateAll((nodes) =>
			nodes.map((n) => n.getAttribute('data-widget-id') || ''),
		)
}

test.describe('a reader keeps their own arrangement of a dashboard', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(DASHBOARD_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
	})

	// @e2e openspec/changes/archive/2026-09-20-a-dashboard-the-reader-arranges/specs/dashboard/spec.md#scenario-one-users-arrangement-is-their-own
	test('a card moved to the top row is still there after a reload', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		const before = await widgetOrder(page)
		expect(
			before,
			'the Dashboard rendered no addressable widgets, so nothing can be dragged',
		).toContain(MOVED_WIDGET)
		expect(
			before[0],
			'the fixture assumes the moved card does not already lead the grid',
		).not.toBe(MOVED_WIDGET)

		const card = page.locator(`[data-widget-id="${MOVED_WIDGET}"]`)
		const target = page.locator(`[data-widget-id="${before[0]}"]`)
		await card.dragTo(target)

		await expect
			.poll(async () => (await widgetOrder(page))[0], {
				message: 'the card did not lead the grid after the drag',
			})
			.toBe(MOVED_WIDGET)

		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(async () => (await widgetOrder(page))[0], {
				message:
					'the arrangement did not survive the reload: either the page declares no userLayout, or the library reading it is not installed',
			})
			.toBe(MOVED_WIDGET)

		expect(errors, 'the dashboard logged errors while arranging').toEqual([])
	})

	// @e2e openspec/changes/archive/2026-09-20-a-dashboard-the-reader-arranges/specs/dashboard/spec.md#scenario-a-handler-adds-a-list-they-did-not-have-to-configure
	test('the add-widget modal offers the two lists by name and adds one', async ({
		page,
	}) => {
		await page.getByRole('button', { name: /add widget/i }).click()

		const modal = page.getByRole('dialog')
		await expect(modal.getByText('Cases you follow')).toBeVisible()
		await expect(modal.getByText("Your team's queue")).toBeVisible()

		// A preset carries its own register and schema, so adding one must ask
		// for neither. A modal that asks is the failure REQ-DASH-024 names.
		await expect(
			modal.getByLabel(/register/i),
			'the modal asked the reader for a register, which a preset carries',
		).toHaveCount(0)
		await expect(
			modal.getByLabel(/schema/i),
			'the modal asked the reader for a schema, which a preset carries',
		).toHaveCount(0)

		await modal.getByText('Cases you follow').click()
		await modal.getByRole('button', { name: /add/i }).click()

		await expect
			.poll(async () => await widgetOrder(page), {
				message: 'the preset was not added to the grid',
			})
			.toContain('cases-you-follow')
	})
})
