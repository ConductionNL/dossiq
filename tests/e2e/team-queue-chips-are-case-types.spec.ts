/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqTeamwachtrij: the team queue (the `team-queue` view of the Cases
 * list) opens with the case types as its chips, "Alle typen 11 / Woo-verzoek
 * 4 / …", not the case list's lenses (Alles, Mijn zaken, …). With nothing
 * selected the bulk band is not drawn.
 *
 * `teamQueueToolbar.spec.js` proves the view declares `quickFilters: []` and
 * `bulkBandAlways: false`. Whether an empty `quickFilters` actually gives way
 * to the generated `quickFiltersFrom` tabs is nextcloud-vue's behaviour, and
 * only a browser on a real Cases page shows the two agree.
 *
 * Read-only: it opens the view and seeds nothing.
 */

import { expect, test } from '@playwright/test'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/**
 * The view's chips need nextcloud-vue behaviour that is not released yet.
 * Set DOSSIQ_E2E_UNRELEASED_NCV=1 on an instance built against those
 * branches to run it before the release.
 */
const NEEDS_RELEASE = 'needs nextcloud-vue fix/index-empty-quick-filters-take-generated (b522773a9) '
	+ 'and feat/saved-view-bulk-band-override (ae6ae7848), batch 24t1b, not in 2.127.0. '
	+ 'Drop this fixme when dossiq\'s lock carries that release.'

test.describe('The team queue opens with its case types as chips', () => {
	test.setTimeout(120_000)

	// @e2e openspec/specs/add-work-queue/spec.md#the-queue-narrows-by-case-type
	test('the chips are the case types, not the case list lenses, and no bulk band shows', async ({ page }) => {
		test.fixme(process.env.DOSSIQ_E2E_UNRELEASED_NCV !== '1', NEEDS_RELEASE)
		const errors = trackDossiqErrors(page)
		await page.goto(`/apps/${REGISTER}/cases?savedView=default:team-queue`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const bar = page.locator('.cn-quick-filter-bar').first()
		await expect(bar).toBeVisible({ timeout: 30_000 })
		await expect(
			bar.getByRole('tab', { name: /^(All types|Alle typen)\b/ }),
			'the first chip is every case type',
		).toBeVisible({ timeout: 30_000 })
		await expect(
			bar.getByRole('tab', { name: /^(Mine|Mijn zaken)\b/ }),
			'the case list lenses are set aside in this view',
		).toHaveCount(0)

		await expect(page.getByTestId('cn-bulk-band')).toBeHidden()
		expect(errors, 'no dossiq errors on the team queue').toEqual([])
	})
})
