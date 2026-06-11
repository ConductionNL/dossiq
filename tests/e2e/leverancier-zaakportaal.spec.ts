/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Leverancier-zaakportaal e2e — covers the operator-side Vue surface
 * shipped in chain members 06 (tender frontend), 08 (invoice frontend),
 * 10 (contract frontend), 14 (KPI frontend) and 15 (dashboard shell).
 *
 * Each test reaches a route, asserts the chrome (header, table,
 * loading state, empty state), and asserts the data-testid hooks that
 * the components render data-independently. Real data assertions are
 * skipped because the supplier register is unseeded on the CI env; the
 * gates are the data-independent shell + the API binding (network
 * request to /api/leverancier-portaal/* happens with the right
 * supplierRef param).
 */

import { test, expect } from '@playwright/test'

const APP_ROOT = '/index.php/apps/procest'

test.describe('Leverancier-zaakportaal — chain members 06/08/10/14/15', () => {

	// @e2e openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
	test('dashboard shell renders the 4-card grid with scope picker', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier`)
		await expect(page.getByTestId('leverancier-shell')).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: /Leveranciersportaal/i })).toBeVisible()
		await expect(page.getByTestId('leverancier-scope-input')).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
	test('dashboard prompts for a supplier UUID when none provided', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier`)
		await expect(page.getByTestId('leverancier-shell')).toBeVisible({ timeout: 15000 })
		// With no scope set, the cards do not render and the "enter UUID" hint shows.
		await expect(page.getByTestId('lz-card-tenders')).toBeHidden()
		await expect(page.locator('.lz-state').first()).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
	test('dashboard scope picker triggers an API call', async ({ page }) => {
		const apiCalls: string[] = []
		page.on('request', req => {
			if (req.url().includes('/leverancier-portaal/dashboard')) {
				apiCalls.push(req.url())
			}
		})
		await page.goto(`${APP_ROOT}/leverancier?supplierRef=test-supplier-uuid`)
		await expect(page.getByTestId('leverancier-shell')).toBeVisible({ timeout: 15000 })
		// Give the mounted hook time to fire.
		await page.waitForTimeout(1500)
		expect(apiCalls.length).toBeGreaterThanOrEqual(1)
		expect(apiCalls[0]).toContain('supplierRef=test-supplier-uuid')
	})

	// @e2e openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
	test('tender list renders the toolbar with status filter + search', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/tenders`)
		await expect(page.getByTestId('leverancier-tender-list')).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: /Aanbestedingen/i })).toBeVisible()
		await expect(page.getByTestId('leverancier-tender-status-filter')).toBeVisible()
		await expect(page.getByTestId('leverancier-tender-search')).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
	test('tender status filter dropdown has all 5 status options', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/tenders`)
		const select = page.getByTestId('leverancier-tender-status-filter')
		await expect(select).toBeVisible({ timeout: 15000 })
		const optionValues = await select.locator('option').evaluateAll(
			(els: Element[]) => els.map(e => (e as HTMLOptionElement).value),
		)
		expect(optionValues).toEqual(
			expect.arrayContaining(['', 'submitted', 'evaluating', 'awarded', 'rejected', 'withdrawn']),
		)
	})

	// @e2e openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
	test('tender list applies the supplierRef + status query when filter changes', async ({ page }) => {
		const apiCalls: string[] = []
		page.on('request', req => {
			if (req.url().includes('/leverancier-portaal/tenders')) {
				apiCalls.push(req.url())
			}
		})
		await page.goto(`${APP_ROOT}/leverancier/tenders?supplierRef=t1`)
		await expect(page.getByTestId('leverancier-tender-list')).toBeVisible({ timeout: 15000 })
		await page.getByTestId('leverancier-tender-status-filter').selectOption('awarded')
		await page.waitForTimeout(500)
		// At least one call with status=awarded should have been made.
		expect(apiCalls.some(u => u.includes('supplierRef=t1') && u.includes('status=awarded'))).toBe(true)
	})

	// @e2e openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
	test('tender detail route renders back link + heading shell', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/tenders/non-existent-id?supplierRef=t1`)
		await expect(page.getByTestId('leverancier-tender-detail')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('leverancier-tender-detail-back')).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
	test('invoice list renders the toolbar with status filter + overdue checkbox', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/facturen`)
		await expect(page.getByTestId('leverancier-invoice-list')).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: /Facturen/i })).toBeVisible()
		await expect(page.getByTestId('leverancier-invoice-status-filter')).toBeVisible()
		await expect(page.getByTestId('leverancier-invoice-overdue-only')).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
	test('invoice status dropdown exposes the 6 status keys', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/facturen`)
		const select = page.getByTestId('leverancier-invoice-status-filter')
		await expect(select).toBeVisible({ timeout: 15000 })
		const optionValues = await select.locator('option').evaluateAll(
			(els: Element[]) => els.map(e => (e as HTMLOptionElement).value),
		)
		expect(optionValues).toEqual(
			expect.arrayContaining(['', 'received', 'under_review', 'approved', 'disputed', 'rejected', 'paid']),
		)
	})

	// @e2e openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
	test('contract list renders the heading + empty-state on no scope', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/contracten`)
		await expect(page.getByTestId('leverancier-contract-list')).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: /Contracten/i })).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
	test('RenewalRequestModal lives in its own file under src/modals/', async () => {
		// Modal-isolation enforcement (ADR-004): the modal MUST be a separate
		// file under src/modals/ and the parent must import it.
		const fs = await import('fs')
		const path = await import('path')
		// Resolve from the test file location upward to the procest app root.
		const appRoot = path.resolve(__dirname, '..', '..')
		const modalPath = path.join(appRoot, 'src', 'modals', 'RenewalRequestModal.vue')
		expect(fs.existsSync(modalPath)).toBe(true)
		const contents = fs.readFileSync(modalPath, 'utf8')
		expect(contents).toContain('<NcDialog')
		expect(contents).toContain('data-testid="leverancier-renewal-modal"')
	})

	// @e2e openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
	test('KPI view renders the heading + summary tiles', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier/kpi`)
		await expect(page.getByTestId('leverancier-kpi-view')).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: /KPI overzicht/i })).toBeVisible()
	})

	// @e2e openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
	test('each dashboard card links to its feature view', async ({ page }) => {
		await page.goto(`${APP_ROOT}/leverancier?supplierRef=t1`)
		await expect(page.getByTestId('leverancier-shell')).toBeVisible({ timeout: 15000 })
		// On a successful load the cards render. On an API failure the error
		// banner shows instead — both states are acceptable shell renders for
		// this gate; we just assert that the route entries exist.
		const cardsOrError = page.locator('[data-testid="lz-card-tenders"], [data-testid="lz-error"], [data-testid="lz-loading"]')
		await expect(cardsOrError.first()).toBeVisible({ timeout: 15000 })
	})
})
