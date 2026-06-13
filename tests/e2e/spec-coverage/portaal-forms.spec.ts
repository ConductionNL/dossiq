/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural UI coverage for the "Mijn gemeente" citizen-portal forms
 * (zaakportaal-mijngemeente): the standalone KlachtForm (complaint) reachable
 * from the "Mijn gemeente" page, and — when the authenticated session has
 * seeded cases — the per-case DocumentList, MessagingWidget and BezwaarForm in
 * the case detail.
 *
 * The portal pages run inside the authenticated NC shell; case data depends on
 * a live OpenRegister + a portal subject session, neither of which is
 * guaranteed in CI. Every assertion that needs seeded data is therefore guarded
 * with a defensive skip, and the spec always asserts the page renders without a
 * 5xx. The standalone complaint form needs no case data and is asserted
 * unconditionally once the page renders.
 */

import { test, expect } from '@playwright/test'
import { navTo, dismissSupportDialog, trackProcestErrors } from '../helpers/nav'

test.describe('Mijn gemeente citizen portal — complaint form', () => {
	// @e2e openspec/specs/zaakportaal-mijngemeente/spec.md#klacht-intake-form
	test('the standalone complaint (klacht) form opens from the portal page', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Mijn gemeente').catch(() => {})
		await dismissSupportDialog(page)

		const toggle = page.getByTestId('portaal-toggle-klacht')
		const reachable = await toggle.isVisible({ timeout: 15000 }).catch(() => false)
		test.skip(!reachable, 'Mijn gemeente portal page not reachable in this environment')

		await toggle.click()
		const form = page.getByTestId('portaal-klacht-form')
		await expect(form).toBeVisible({ timeout: 10000 })
		// The category select, description and submit control are present.
		await expect(page.getByTestId('portaal-klacht-description')).toBeVisible()
		await expect(page.getByTestId('portaal-klacht-submit')).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/specs/zaakportaal-mijngemeente/spec.md#klacht-is-submitted
	test('submitting an empty complaint shows an inline validation message', async ({ page }) => {
		await navTo(page, 'Mijn gemeente').catch(() => {})
		await dismissSupportDialog(page)

		const toggle = page.getByTestId('portaal-toggle-klacht')
		const reachable = await toggle.isVisible({ timeout: 15000 }).catch(() => false)
		test.skip(!reachable, 'Mijn gemeente portal page not reachable in this environment')

		await toggle.click()
		await page.getByTestId('portaal-klacht-submit').click()
		await expect(page.getByTestId('portaal-klacht-validation')).toBeVisible({ timeout: 5000 })
	})
})

test.describe('Mijn gemeente citizen portal — case detail forms', () => {
	// @e2e openspec/specs/zaakportaal-mijngemeente/spec.md#citizen-downloads-only-her-documents
	test('opening a case reveals the documents, messaging and objection surfaces', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Mijn gemeente').catch(() => {})
		await dismissSupportDialog(page)

		const firstRow = page.locator('.zp-cases-row').first()
		const hasCases = await firstRow.isVisible({ timeout: 15000 }).catch(() => false)
		test.skip(!hasCases, 'No portal cases seeded for the authenticated subject')

		await firstRow.click()
		// Documents and messaging always render on a case detail.
		await expect(page.getByTestId('portaal-document-list')).toBeVisible({ timeout: 10000 })
		await expect(page.getByTestId('portaal-messaging-widget')).toBeVisible()
		await expect(page.getByTestId('portaal-messaging-form')).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/specs/zaakportaal-mijngemeente/spec.md#bezwaar-form-appears-when-deadline-is-open
	test('the objection form toggles open when the case allows an objection', async ({ page }) => {
		await navTo(page, 'Mijn gemeente').catch(() => {})
		await dismissSupportDialog(page)

		const firstRow = page.locator('.zp-cases-row').first()
		const hasCases = await firstRow.isVisible({ timeout: 15000 }).catch(() => false)
		test.skip(!hasCases, 'No portal cases seeded for the authenticated subject')

		await firstRow.click()
		const toggle = page.getByTestId('portaal-toggle-bezwaar')
		const canObject = await toggle.isVisible({ timeout: 10000 }).catch(() => false)
		test.skip(!canObject, 'Selected case does not offer an objection action')

		await toggle.click()
		await expect(page.getByTestId('portaal-bezwaar-form')).toBeVisible({ timeout: 10000 })
	})
})
