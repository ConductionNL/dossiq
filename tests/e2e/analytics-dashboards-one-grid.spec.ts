/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * page-topology-cleanup F2: the three analytics dashboards render through the
 * one dashboard page type, with one page heading and a grid of several widgets.
 *
 * WHAT THIS ADDS TO page-shells.spec.ts AND ui-pages.spec.ts. Those assert
 * that each page's heading renders, which a page that delegated its whole body
 * to one custom component also does: the old dashboard-in-dashboard shape
 * rendered the page heading AND a second heading inside its single 12x12
 * widget. So this counts. Exactly one level-2 heading in the dashboard page
 * host, and two or more grid items in its grid. Both numbers are properties of
 * the page DECLARATION (`config.widgets`, `config.layout`), not of data, so
 * they hold on an empty instance.
 *
 * Runs against the nightly instance, not in the build loop.
 */

import { expect, test } from '@playwright/test'
import { navToRoute, trackDossiqErrors } from './helpers/nav.ts'
import { ProcessMiningDashboard, TermijnDashboard } from './helpers/page-components.ts'

/** The three analytics dashboards and the heading each page type supplies. */
const DASHBOARDS: Array<{ route: string, heading: RegExp }> = [
	{ route: TermijnDashboard, heading: /^(Deadline monitoring|Termijnbewaking)$/ },
	{ route: '/doorlooptijd', heading: /^(Processing time|Doorlooptijd)$/ },
	{ route: ProcessMiningDashboard, heading: /^(Process mining|Procesanalyse)$/ },
]

test.describe('analytics dashboards share one render path', () => {
	for (const { route, heading } of DASHBOARDS) {
		// @e2e openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md#a-dashboard-page-renders-exactly-one-page-heading
		test(`${route} renders one page heading and a grid of several widgets`, async ({ page }) => {
			const errors = trackDossiqErrors(page)
			await navToRoute(page, route)

			const host = page.getByTestId('cn-dashboard-page')
			await expect(host, 'one dashboard page host, not one nested in a widget').toHaveCount(1, { timeout: 15000 })

			const headings = host.getByRole('heading', { level: 2 })
			await expect(headings.filter({ hasText: heading })).toHaveCount(1)
			// No widget renders a page-level heading of its own.
			await expect(headings).toHaveCount(1)

			const items = host.locator('.cn-dashboard-grid .grid-stack-item')
			await expect.poll(() => items.count(), { timeout: 15000 }).toBeGreaterThanOrEqual(2)

			await expect(page.locator('body')).not.toContainText('Internal Server Error')
			expect(errors, 'no dossiq errors while the dashboard rendered').toEqual([])
		})
	}
})
