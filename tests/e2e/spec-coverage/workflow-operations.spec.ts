/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural UI coverage for the operational views that sit alongside the
 * case lists: the Workflow Board (kanban of statuses), the Case Map, the
 * Transfers index, the Subsidies / Grant-schemes intake lists and the
 * Features & roadmap page. Each is reached via its sidebar nav entry and
 * asserted on its distinct rendered surface (heading / empty-state /
 * primary control) independently of seeded data.
 */

import { test, expect } from '@playwright/test'
import { navTo, trackProcestErrors } from '../helpers/nav'

test.describe('Workflow Board page', () => {
	// @e2e openspec/specs/workflow-board/spec.md#workflow-board-renders-kanban-shell
	test('workflow board renders its heading and a status/empty surface', async ({ page }) => {
		await navTo(page, 'Workflow Board')
		// The board view renders its own header h2 inside `.workflow-board__header`
		// (the page also has a dashboard-wrapper title + widget title with the
		// same text, so scope to the board's own header).
		await expect(page.locator('.workflow-board__header h2'))
			.toBeVisible({ timeout: 15000 })
		// With no status types configured the board shows its guidance
		// empty-state; with statuses it renders columns. Accept either, never
		// an error render.
		await expect(
			page.locator('main').getByText(/No workflow statuses configured|To Do|In Progress|Done/).first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Case Map page', () => {
	// @e2e openspec/specs/case-map/spec.md#case-map-renders-map-surface
	test('case map renders its heading and an interactive map surface', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Map')
		await expect(page.getByRole('heading', { name: 'Case map' }))
			.toBeVisible({ timeout: 15000 })
		// Leaflet renders a tile/zoom container — assert the map pane exists
		// rather than any specific marker (data-independent).
		await expect(page.locator('.leaflet-container, [class*="map"]').first())
			.toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Transfers index page', () => {
	// @e2e openspec/specs/case-transfer/spec.md#transfers-index-renders-list-shell
	test('transfers index renders the list shell with a create control', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Transfers')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Subsidies intake page', () => {
	// @e2e openspec/specs/subsidy-intake/spec.md#subsidies-index-renders-list-shell
	test('subsidies index renders the subsidy intake list shell', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Subsidies')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		// Subsidy-specific create control distinguishes this from other lists.
		await expect(page.getByRole('button', { name: 'Add Subsidieaanvraag' })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Grant schemes page', () => {
	// @e2e openspec/specs/subsidy-intake/spec.md#grant-schemes-index-renders-list-shell
	test('grant schemes index renders its list shell', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Grant schemes')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Features & roadmap page', () => {
	// @e2e openspec/specs/features-roadmap/spec.md#features-page-renders-controls
	test('features & roadmap renders heading and its action controls', async ({ page }) => {
		const errors = trackProcestErrors(page)
		await navTo(page, 'Features & roadmap')
		await expect(page.getByRole('heading', { name: 'Features' }).first())
			.toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: 'Show roadmap' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Suggest feature' })).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
