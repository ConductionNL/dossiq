/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the gis-integration spec.
 *
 * Scope: the cases map is the only spec scenario that asserts a real UI
 * surface; the geo/WFS backend scenarios and the pure data-shaping helpers are
 * excluded in the spec (covered by PHPUnit / vitest / Newman).
 *
 * The map is the Cases index in its map view (`/cases?_view=map`). The old
 * `/map` address redirects there, so a bookmark or a link written against it
 * still lands on the map. The rows the index reads are answered by
 * `page.route()` rather than by seeded data, so the count it prints is known
 * in advance. Map tiles need external network and are never asserted.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog } from '../helpers/nav.ts'

/** The collection read the Cases index makes for its rows. */
const CASE_ROWS = /\/apps\/openregister\/api\/objects\/dossiq\/case(\?|$)/

/**
 * Three cases: two carry a geometry the map can place (a JSON-string Point, as
 * the case schema types it, and a Polygon object), one carries none.
 */
const ROWS = [
	{
		id: 'e2e-map-point',
		title: 'Map case with a point',
		status: 'open',
		geometry: JSON.stringify({ type: 'Point', coordinates: [5.12, 52.09] }),
	},
	{
		id: 'e2e-map-polygon',
		title: 'Map case with a polygon',
		status: 'in_progress',
		geometry: {
			type: 'Polygon',
			coordinates: [
				[
					[4.89, 52.37],
					[4.9, 52.37],
					[4.9, 52.38],
					[4.89, 52.37],
				],
			],
		},
	},
	{ id: 'e2e-map-nowhere', title: 'Map case without a location', status: 'open' },
]

/**
 * Open the old `/map` address and require it to land on the Cases map view.
 *
 * @param page The page under test.
 */
async function openCaseMap(page: Page): Promise<void> {
	const response = await page.goto('/apps/dossiq/map', {
		waitUntil: 'domcontentloaded',
		timeout: 60_000,
	})
	expect(response?.status() ?? 0).toBeLessThan(500)
	await dismissSupportDialog(page)
	await expect(
		page,
		'/map must redirect to the Cases index in its map view',
	).toHaveURL(/\/cases\?_view=map$/, { timeout: 30_000 })
}

test.describe('GIS integration spec coverage', () => {
	// A cold app page can take 20s on a loaded rig; the goto alone has 60s.
	test.slow()

	// @e2e openspec/specs/gis-integration/spec.md#the-case-map-renders-the-cases-index-in-map-view
	test('the cases map renders the map and draws only the located cases', async ({
		page,
	}) => {
		await page.route(CASE_ROWS, (route) =>
			route.request().method() === 'GET'
				? route.fulfill({ json: { results: ROWS, total: ROWS.length } })
				: route.fallback(),
		)
		await openCaseMap(page)

		await expect(page.locator('.leaflet-container').first()).toBeVisible({
			timeout: 30_000,
		})
		// Two of the three rows carry a geometry. CnMapWidget draws each
		// feature with `L.circleMarker`, an SVG path in the overlay pane.
		await expect(
			page.locator('.leaflet-overlay-pane path.leaflet-interactive'),
			'every located case is drawn on the map, and the unlocated one is not',
		).toHaveCount(2, { timeout: 15_000 })
	})

	// @e2e openspec/specs/gis-integration/spec.md#the-case-map-renders-the-cases-index-in-map-view
	test('an unreachable case backend leaves the map page usable', async ({
		page,
	}) => {
		// The scenario's second clause. The read fails outright, as it does when
		// OpenRegister is down, and the page must stay usable.
		await page.route(CASE_ROWS, (route) =>
			route.request().method() === 'GET'
				? route.abort('failed')
				: route.fallback(),
		)
		await openCaseMap(page)

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(page.getByRole('navigation').first()).toBeVisible()
	})
})
