/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioural UI coverage for the operational views that sit alongside the
 * case lists: the workflow board (the Cases list in board view), the case map
 * (the Cases list in map view), the
 * Transfers index, the Subsidies / Grant-schemes intake lists and the
 * Features & roadmap page. Each is reached via its sidebar nav entry and
 * asserted on its distinct rendered surface (heading / empty-state /
 * primary control) independently of seeded data.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	dismissSupportDialog,
	navToRoute,
	trackDossiqErrors,
} from '../helpers/nav.ts'

test.describe('Workflow board', () => {
	/**
	 * Open the workflow board: the Cases list in board view.
	 *
	 * @param page The page.
	 */
	async function openBoard(page: Page): Promise<void> {
		await page.goto('/index.php/apps/dossiq/cases?_view=board')
		await dismissSupportDialog(page)
	}

	// @e2e exclude DASH-V1-006a (columns in status order, with counts) is
	// proven by `tests/e2e/workflows/case-lifecycle.spec.ts`, which seeds its
	// own status types. This is the board's shell, on its route, without a
	// 500: a status column or the library's "cannot back a board" notice.
	test('workflow board renders a status column or its notice', async ({
		page,
	}) => {
		await openBoard(page)
		await expect(page.locator('[data-testid="cn-board-view"]')).toBeVisible({
			timeout: 30000,
		})
		await expect(
			page
				.locator(
					'[data-testid="cn-board-column"], [data-testid="cn-board-unusable"]',
				)
				.first(),
		).toBeVisible({ timeout: 30000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// @e2e exclude A console-error check cannot prove the columns, their
	// order or their counts; case-lifecycle.spec.ts does. The board reads
	// the statusType and caseType collections, and an unregistered object
	// type used to log dossiq console errors on every load.
	test('workflow board loads without dossiq console errors', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await openBoard(page)
		await expect(page.locator('[data-testid="cn-board-view"]')).toBeVisible({
			timeout: 30000,
		})
		await page.waitForTimeout(1500)
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Case map', () => {
	/**
	 * The map is the Cases index in its map view. This proves the navigation
	 * half of OVERVIEW-01a: the map address renders a Leaflet map rather than
	 * falling through to the dashboard. Which cases it plots is asserted
	 * against fixed rows in gis-integration.spec.ts. The index map plots the
	 * current page of rows, not every located case, so "showing all case
	 * locations" over a large register is not claimed here.
	 */
	// @e2e openspec/specs/case-map-overview/spec.md#scenario-overview-01a-display-all-cases-on-map
	test('the case map renders the Cases index in map view', async ({ page }) => {
		await navToRoute(page, '/cases')
		await page.goto(new URL('?_view=map', page.url()).href)
		await dismissSupportDialog(page)
		await expect(page.locator('.leaflet-container').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

// Transfers list page removed — cases are transferred from their detail page
// (the TransferDetail route is kept for deep links). See
// feat(nav): streamline work queue.

test.describe('Subsidies are a view of the case list', () => {
	// There are no case-type-specific pages (Ruben, 20 October 2026): the
	// Subsidies list is the Cases page's default view `subsidies`, narrowed to
	// the subsidie case type, and the old /subsidies address redirects there.
	// @e2e exclude No canonical spec covers the subsidies list. The subsidie
	// specs describe the keten (voorschot, termijnen, verplichting,
	// vaststelling) and the settlement of case costs, none of them the list
	// of aanvragen. This test pins the redirect and the view until one is
	// written.
	test('/subsidies opens the subsidies view of the case list', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await navToRoute(page, '/subsidies')
		await expect(page).toHaveURL(/\/cases\?savedView=default(:|%3A)subsidies/, {
			timeout: 30_000,
		})
		// The view titles the page, so the reader still sees which list this is.
		await expect(
			page.getByRole('heading', { name: /^Subsidies$/i }).first(),
		).toBeVisible({ timeout: 30_000 })
		// A subsidie-aanvraag IS a case, so the create control is the case one.
		await expect(
			page.getByRole('button', { name: /^(Add (Item|Case)|New case)$/ }).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Grant schemes are administered as case types', () => {
	// UPDATED BY subsidieregeling-is-a-casetype. This used to assert that
	// /subsidieregelingen rendered its own index. A grant scheme IS a case type
	// — four of its properties were caseType fields under another name — so the
	// bespoke page retired and schemes are administered on the Case types index.
	//
	// Asserting BOTH halves. The absence check alone would pass just as happily
	// on a build where the capability vanished entirely, which is what ADR-044
	// Decision 5 forbids.
	//
	// @e2e openspec/specs/case-types/spec.md#the-retired-route-falls-through-rather-than-erroring
	test('the retired scheme index is gone and Case types took it over', async ({
		page,
	}) => {
		await navToRoute(page, '/subsidieregelingen')
		await expect(
			page.getByRole('button', { name: 'Add Subsidieregeling' }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')

		await navToRoute(page, '/settings/case-types')
		await expect(
			page.getByRole('button', { name: /Add|New|Create/i }).first(),
		).toBeVisible({ timeout: 20000 })
	})
})

test.describe('Features & roadmap page', () => {
	// @e2e openspec/specs/features-roadmap/spec.md#features-page-renders-controls
	test('features & roadmap renders heading and its action controls', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await navToRoute(page, '/features-roadmap')
		await expect(
			page.getByRole('heading', { name: 'Features' }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByRole('button', { name: 'Show roadmap' }),
		).toBeVisible()
		await expect(
			// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
			// suggestion modal (team decision 2026-09-04: the forge is where the
			// conversation happens), and the CTA is an anchor to the forge's
			// feature-request issue form now. An `<a href>` has role `link`.
			page.getByRole('link', { name: 'Suggest feature' }),
		).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
