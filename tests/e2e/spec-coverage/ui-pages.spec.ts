/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for the rendering UI pages of Procest.
 *
 * Each test drives a real browser against a live page and asserts the
 * rendered shell (heading / view toggle / create button / filter controls).
 * These shells render independently of OpenRegister returning data — the
 * data-dependent rows themselves stay covered by their own (excluded)
 * data-seeded scenarios. Every test is annotated to the gate-visible
 * `#### Scenario:` it proves.
 *
 * Navigation: deep-link `page.goto('/apps/procest/<route>')` resets the
 * Vue history-mode router to Dashboard, so we navigate via the sidebar
 * nav link (client-side) after landing on a route that resolves.
 */

import { test, expect } from '@playwright/test'
import { navTo, dismissSupportDialog } from '../helpers/nav'

test.describe('Dashboard page render', () => {

	// @e2e openspec/specs/dashboard/spec.md#dashboard-page-renders-heading-and-widget-grid
	test('dashboard renders heading and widget grid shell', async ({ page }) => {
		await navTo(page, 'Dashboard')
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 }))
			.toBeVisible({ timeout: 15000 })
		// Widget grid container renders — either real widget headings or the
		// "Widget not available" placeholder shells. Assert at least one tile.
		await expect(
			page.locator('main').getByText(/Widget not available|Cases by Status|My Work/).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// @e2e openspec/specs/signalering-widgets/spec.md#default-layout-includes-signalering-row
	test('dashboard default layout renders the three signalering widget cards', async ({ page }) => {
		await navTo(page, 'Dashboard')
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 }))
			.toBeVisible({ timeout: 15000 })
		// The default manifest dashboard layout (pages[0]) places the three
		// signalering widgets as titled cards in the widget grid. Each renders its
		// title frame (showTitle: true) independently of whether the underlying
		// case/task data is present, so the third-row layout is browser-verifiable.
		const main = page.locator('main')
		await expect(main.getByText('Deadline Alerts', { exact: true }).first())
			.toBeVisible({ timeout: 10000 })
		await expect(main.getByText('Task Due Reminders', { exact: true }).first())
			.toBeVisible()
		await expect(main.getByText('Stalled Cases', { exact: true }).first())
			.toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Cases index page render', () => {

	// @e2e openspec/specs/case-management/spec.md#cases-index-page-renders-list-shell
	test('cases index renders list shell', async ({ page }) => {
		await navTo(page, 'Cases')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Voorstellen page render', () => {

	// @e2e openspec/specs/case-management/spec.md#voorstellen-page-renders-heading-and-create-control
	test('voorstellen page renders heading and create control', async ({ page }) => {
		await navTo(page, 'Voorstellen')
		// The page renders either the custom "B&W Voorstellen" view (heading +
		// "Nieuw voorstel") or the generic index shell (Add button) depending on
		// the deployed build — accept either rendered shell, never an error.
		const customHeading = page.getByRole('heading', { name: /Voorstellen/ }).first()
		const addBtn = page.getByRole('button', { name: /Nieuw voorstel|^Add / }).first()
		await expect(customHeading.or(addBtn)).toBeVisible({ timeout: 15000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Work Queue page render', () => {

	// @e2e openspec/specs/signalering-widgets/spec.md#work-queue-page-renders-kpi-strip-and-filters
	test('work queue renders heading, KPI strip and filters', async ({ page }) => {
		await navTo(page, 'Work Queue')
		await expect(page.getByRole('heading', { name: 'Work Queue', level: 2 }))
			.toBeVisible({ timeout: 15000 })
		const kpis = page.locator('.werkvoorraad__kpis')
		await expect(kpis.getByText('Open Cases', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Overdue', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Completed This Week', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Unassigned', { exact: true })).toBeVisible()
		await expect(page.getByRole('button', { name: /All/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Unassigned/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Overdue/ })).toBeVisible()
	})
})

test.describe('Doorlooptijd page render', () => {

	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#doorlooptijd-page-renders-heading
	test('doorlooptijd renders processing-time analytics heading', async ({ page }) => {
		// No sidebar nav entry targets this route in the deployed build, so deep-link
		// directly — without the /index.php prefix, which the history-mode router
		// resets to the Dashboard. Dismiss the support dialog before interacting.
		await page.goto('/apps/procest/doorlooptijd')
		await dismissSupportDialog(page)
		await expect(page.getByRole('heading', { name: 'Processing Time Analytics', level: 2 }))
			.toBeVisible({ timeout: 15000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Bezwaren index page render', () => {

	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#bezwaren-index-page-renders-list-shell
	test('bezwaren index renders list shell', async ({ page }) => {
		await navTo(page, 'Bezwaren')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Advice index page render', () => {

	// @e2e openspec/specs/advice-management/spec.md#advice-index-page-renders-list-shell
	test('advice index renders list shell', async ({ page }) => {
		await navTo(page, 'Advice')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
