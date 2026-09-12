/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the gis-integration spec.
 *
 * Scope: the cases-on-map dashboard is the only spec scenario that asserts a
 * real UI surface; the geo/WFS backend scenarios and the pure data-shaping
 * helpers are excluded in the spec (covered by PHPUnit / vitest / Newman).
 *
 * The page lives at `/map` (manifest page `CaseMap`, component
 * CasesOnMapView). This file used to open `/cases-map`, a route the manifest
 * never declared, and asserted the sidebar only `if` its heading was there. It
 * never was, so every run took the soft-pass branch and proved nothing beyond
 * the absence of a 5xx.
 *
 * The case rows the view reads are answered by `page.route()` rather than by
 * seeded data. The count summary is the claim under test, and it is only a
 * claim when the number it must show is known in advance: on a shared instance
 * "Showing 0 of 0" is as green as "Showing 2 of 2" unless the input is fixed.
 * Map tiles need external network and are never asserted.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog } from '../helpers/nav.ts'

/** The collection read CasesOnMapView makes for its markers. */
const CASE_ROWS = /\/apps\/openregister\/api\/objects\/dossiq\/case(\?|$)/

/**
 * Three cases: two carry a geometry the view can place (a JSON-string Point, as
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
 * Open the cases-on-map page and wait for its sidebar.
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
		page.getByRole('heading', { name: 'Cases on map' }),
		'the cases-on-map page must mount at /map rather than fall through to the dashboard',
	).toBeVisible({ timeout: 30_000 })
}

test.describe('GIS integration spec coverage', () => {
	// A cold app page can take 20s on a loaded rig; the goto alone has 60s.
	test.slow()

	// @e2e openspec/specs/gis-integration/spec.md#cases-on-map-view-renders-the-map-dashboard
	test('the cases-on-map view renders its filter sidebar and counts the located cases', async ({
		page,
	}) => {
		await page.route(CASE_ROWS, (route) =>
			route.request().method() === 'GET'
				? route.fulfill({ json: { results: ROWS, total: ROWS.length } })
				: route.fallback(),
		)
		await openCaseMap(page)

		// The filter sidebar: both filters, by their accessible names.
		await expect(page.getByRole('combobox', { name: 'Case type' })).toBeVisible()
		await expect(page.getByRole('combobox', { name: 'Status' })).toBeVisible()

		// The count summary names the cases it could place: two of three rows
		// carry a geometry, so two are shown out of two located.
		await expect(page.locator('.cases-on-map__summary')).toHaveText(
			'Showing 2 of 2 located cases',
			{ timeout: 15_000 },
		)
		await expect(page.locator('.cases-on-map__map')).toBeVisible()
		await expect(page.locator('.cases-on-map__notice')).toHaveCount(0)
	})

	// @e2e openspec/specs/gis-integration/spec.md#cases-on-map-view-renders-the-map-dashboard
	test('an unreachable case backend surfaces a notice instead of a broken page', async ({
		page,
	}) => {
		// The scenario's second clause. The read fails outright, as it does when
		// OpenRegister is down, and the page must say so and stay usable.
		//
		// Measured, not assumed: an HTTP 500 that carries a JSON body does NOT
		// raise this notice today. CasesOnMapView parses the body, finds no
		// `results`, and shows an empty map as if there were no cases. That gap
		// is reported on the PR rather than asserted here, because this change
		// repairs tests and does not touch the view.
		await page.route(CASE_ROWS, (route) =>
			route.request().method() === 'GET'
				? route.abort('failed')
				: route.fallback(),
		)
		await openCaseMap(page)

		await expect(page.locator('.cases-on-map__notice')).toContainText(
			'Map data could not be loaded. Showing what is available.',
			{ timeout: 15_000 },
		)
		// Non-blocking: the sidebar and the map container are still there.
		await expect(page.getByRole('combobox', { name: 'Case type' })).toBeVisible()
		await expect(page.locator('.cases-on-map__summary')).toHaveText(
			'Showing 0 of 0 located cases',
		)
		await expect(page.locator('.cases-on-map__map')).toBeVisible()
	})
})
