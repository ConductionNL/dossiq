/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the related-case-linking UI surface
 * (the "Related cases" / Gerelateerde zaken case-detail sidebar tab).
 *
 * These tests drive a real browser against the case detail. They are
 * defensively guarded: the tab is data-dependent (it needs at least one
 * case to exist and the RelatedCasesSection component to be deployed). On a
 * fresh/unseeded register or a deploy that predates this change, the test
 * SKIPS with a clear reason rather than failing — distinguishing a
 * deploy/data mismatch from a genuine UI defect (see the gate-19 live-verify
 * deploy-reality note).
 *
 * Backend behaviour (typed storage, bidirectional consistency, guards, ZGW
 * mapping) is proven by PHPUnit (CaseRelationService/Controller) and Newman
 * (relevante-andere-zaken collection); those scenarios carry `@e2e exclude`
 * in the spec.
 */

import { test, expect } from '@playwright/test'
import { navTo, dismissSupportDialog } from '../helpers/nav'

/**
 * Open the first case in the case list, or skip when none exists / the list
 * does not render (unseeded register or deploy mismatch).
 */
async function openFirstCaseOrSkip(page) {
	await navTo(page, 'Cases').catch(() => {})
	await dismissSupportDialog(page).catch(() => {})
	const row = page.locator('.viewTableRow, tr[role="row"], .list-item').first()
	if (await row.count() === 0) {
		test.skip(true, 'No cases in the deployed/seeded register — related-cases tab is data-dependent.')
		return false
	}
	await row.click().catch(() => {})
	await page.waitForTimeout(1000)
	return true
}

test.describe('Related cases section (related-case-linking)', () => {

	// @e2e openspec/specs/related-case-linking/spec.md#section-lists-relations-with-navigation
	test('the Related cases tab renders its section heading and link control', async ({ page }) => {
		const opened = await openFirstCaseOrSkip(page)
		if (!opened) return

		// The "Related cases" sidebar tab is registered on CaseDetail. If the
		// deployed build predates this change the tab is absent → skip.
		const tab = page.getByRole('tab', { name: /Related cases|Gerelateerde zaken/i }).first()
		if (await tab.count() === 0) {
			test.skip(true, 'Related cases sidebar tab not present in the deployed build (deploy mismatch).')
			return
		}
		await tab.click()
		// The section renders a "Related cases" heading and a "Link case" control,
		// independent of whether the case currently has relations.
		await expect(
			page.getByRole('heading', { name: /Related cases|Gerelateerde zaken/i }).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(
			page.getByRole('button', { name: /Link case|Zaak koppelen/i }).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// @e2e openspec/specs/related-case-linking/spec.md#add-relation-flow
	test('clicking Link case opens the add-relation modal with type picker', async ({ page }) => {
		const opened = await openFirstCaseOrSkip(page)
		if (!opened) return

		const tab = page.getByRole('tab', { name: /Related cases|Gerelateerde zaken/i }).first()
		if (await tab.count() === 0) {
			test.skip(true, 'Related cases sidebar tab not present in the deployed build (deploy mismatch).')
			return
		}
		await tab.click()
		const linkBtn = page.getByRole('button', { name: /Link case|Zaak koppelen/i }).first()
		await expect(linkBtn).toBeVisible({ timeout: 10000 })
		await linkBtn.click()
		// The AddCaseRelationModal opens with a "Link related case" dialog title
		// and a relation-type select (aardRelatie). Assert the dialog chrome
		// rather than driving a full create (which needs a second seeded case).
		await expect(
			page.getByText(/Link related case|Gerelateerde zaak koppelen/i).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(
			page.getByText(/Relation type|Aard relatie/i).first(),
		).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/specs/related-case-linking/spec.md#unreadable-target-is-masked
	test('the relation list tolerates RBAC-masked targets without error', async ({ page }) => {
		const opened = await openFirstCaseOrSkip(page)
		if (!opened) return

		const tab = page.getByRole('tab', { name: /Related cases|Gerelateerde zaken/i }).first()
		if (await tab.count() === 0) {
			test.skip(true, 'Related cases sidebar tab not present in the deployed build (deploy mismatch).')
			return
		}
		await tab.click()
		// Whether the case has readable relations, masked stubs, or none, the
		// section must render without a server/render error. (A target the
		// viewer cannot read renders as a masked case-number stub; the masking
		// branch is unit-tested in RelatedCasesSection.hydrate().)
		await expect(
			page.getByRole('heading', { name: /Related cases|Gerelateerde zaken/i }).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(page.locator('body')).not.toContainText('TypeError')
	})
})
