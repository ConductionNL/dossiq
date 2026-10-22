/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqPostvak: the mailbox has a Filter button that opens the index side
 * panel, and its one filter is the channel. The channel is a parameter of the
 * triage endpoint (`sourceConfig.searchFields.channel`), so choosing one must
 * narrow the SERVER's answer: the page asks `/api/intake/triage?channel=…`
 * again, rather than hiding rows it already holds.
 *
 * `postvakFilterPanel.spec.js` proves the manifest declares the panel. Only a
 * browser shows that the panel's select reaches the endpoint with the right
 * parameter, which is the half a manifest test cannot see.
 *
 * Read-only: it seeds nothing and writes nothing, so it needs no fixtures and
 * holds on any instance with the intake role for the signed-in user.
 *
 * Board: openspec/specs/intake-from-a-channel/screens.md (DqPostvak). No
 * scenario names the panel yet, so the test carries no @e2e anchor.
 */

import { expect, test } from '@playwright/test'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The triage endpoint the mailbox reads. */
const TRIAGE = '/apps/dossiq/api/intake/triage'

test.describe('The mailbox filter panel', () => {
	test.setTimeout(120_000)

	test('the Filter button opens a channel filter that narrows the triage answer', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		const firstLoad = page.waitForRequest(
			(request) => request.url().includes(TRIAGE),
			{ timeout: 45_000 },
		)
		await page.goto(`/apps/${REGISTER}/postvak`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await firstLoad

		const filterButton = page.getByTestId('cn-actions-bar-filter-button')
		await expect(filterButton, 'the board draws a Filter button').toBeVisible({ timeout: 30_000 })
		await filterButton.click()

		const panel = page.locator('.cn-index-sidebar-panel')
		await expect(panel).toBeVisible({ timeout: 20_000 })
		const group = panel.locator('.cn-index-sidebar__filter-group').filter({
			has: page.locator('.cn-index-sidebar__filter-label', { hasText: /^(Channel|Kanaal)$/ }),
		})
		await expect(group, 'the panel holds the channel filter').toHaveCount(1)

		const narrowed = page.waitForRequest(
			(request) => request.url().includes(TRIAGE)
				&& new URL(request.url()).searchParams.get('channel') === 'portal',
			{ timeout: 30_000 },
		)
		await group.locator('.cn-index-sidebar__select input').first().click()
		await page.getByRole('option', { name: /^(Portal|Portaal)$/ }).click()
		await narrowed

		expect(errors, 'no dossiq errors while filtering').toEqual([])
	})
})
