/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural UI coverage for the procest administrative settings pages.
 * Each renders an OpenRegister-backed index with a view-specific primary
 * create control. Every test navigates to the page's route and asserts that
 * it renders its OWN distinct create control — proving the route resolves to
 * the right view, not a stale one — while guarding against a 5xx render and
 * procest-origin console errors.
 *
 * See the note above SETTINGS_PAGES for why these navigate by route rather
 * than by clicking a nav label.
 */

import { test, expect } from '@playwright/test'
import { navToRoute, trackProcestErrors } from '../helpers/nav'

// name (for the test title), the ROUTE the settings page lives at, and the
// view-specific create control it must render.
//
// WHY THESE NAVIGATE BY ROUTE, NOT BY NAV LABEL
// ---------------------------------------------
// These tests used to expand the collapsible "Settings" nav group and click
// the entry by its rendered label. Every one of them failed on CI because the
// labels they clicked are Dutch/legacy strings the app no longer renders — the
// settings menu was translated to English ("Parafeerroutes" is now "Approval
// routes", "Kaartlagen" is "Map layers", "Tenants" is "Organisations",
// "Automatische acties" is "Automatic actions", "Handhavingsstrategie" is
// "Enforcement strategy"). Because those leaves also sit inside a COLLAPSED
// group they are `display:none`, so the click blocked on actionability rather
// than failing on a missing label, and the whole 60s test budget was consumed
// before a bare timeout named an element instead of the cause.
//
// Routes are the stable contract here — they are declared in src/manifest.json
// and are what the nav entries themselves link to — so navigating to them
// directly tests the same view without coupling every assertion to the current
// translation of a menu string. Each page's OWN create control still proves
// the route resolved to the right view rather than a stale one.
//
// Control labels below were measured against a CI runner (2026-08-04).
const SETTINGS_PAGES: Array<{ name: string, route: string, addBtn: string }> = [
	// CaseType settings form (Save control) — the /settings root.
	{ name: 'Case Types', route: '/settings', addBtn: 'Save' },
	// Leges (the municipal-fee engine — verordeningen, articles, calculations)
	// was retired from Procest in Wave 1 of the case-model consolidation
	// (ADR-003). Fees are now Pipelinq products referenced from a case type's
	// productsOrServices; Procest owns no fee settings entries.
	{ name: 'Approval routes', route: '/settings/parafeerroutes', addBtn: 'Add Endorsement Route' },
	{ name: 'Automatic actions', route: '/settings/automatic-actions', addBtn: 'Add Automatic Action' },
	{ name: 'Enforcement strategy', route: '/settings/lhs-matrices', addBtn: 'Add LHS Matrix' },
	{ name: 'LHS recommendations', route: '/settings/lhs-recommendations', addBtn: 'Add LHS Recommendation' },
	{ name: 'Partner organisations', route: '/settings/partners', addBtn: 'Add Partner organization' },
	{ name: 'Map layers', route: '/settings/wms-layers', addBtn: 'Add WMS/WFS Layer' },
	{ name: 'Workflow definitions', route: '/settings/workflow-definitions', addBtn: 'Add Workflow Template' },
	{ name: 'Organisations', route: '/settings/tenants', addBtn: 'Add Tenant' },
	// The standalone "Status history" (StatusRecords) settings page was retired
	// by retire-status-history-page — change history is now the CaseDetail
	// audit-trail surface, not a page/menu item. Entry removed accordingly.
	{ name: 'Case locations', route: '/settings/locations', addBtn: 'Add Case Location' },
]

for (const { name, route, addBtn } of SETTINGS_PAGES) {
	test.describe(`Settings · ${name}`, () => {
		// @e2e openspec/specs/admin-settings/spec.md#settings-page-renders-distinct-control
		test(`${name} settings page renders its own "${addBtn}" control`, async ({ page }) => {
			const errors = trackProcestErrors(page)
			await navToRoute(page, route)
			await expect(page.getByRole('button', { name: addBtn, exact: true }).first())
				.toBeVisible({ timeout: 15000 })
			await expect(page.locator('body')).not.toContainText('Internal Server Error')
			expect(errors, errors.join('\n')).toEqual([])
		})
	})
}
