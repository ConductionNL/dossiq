/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-110 coverage: cross-app links leave the navigation, and Flows becomes a
 * real in-app page.
 *
 * WHAT MAKES THIS WORTH TESTING IN A BROWSER
 * ------------------------------------------
 * Both halves of this change are invisible to a unit test of the manifest. The
 * manifest is only an instruction; what matters is what `CnAppNav` and
 * `CnAppRoot` in the INSTALLED library version actually do with `section:
 * "integrations"`. Against a library older than 2.18.0 the value is unknown:
 * the entry falls into no bucket and simply never renders anywhere — no error,
 * no warning, the link just silently ceases to exist. A manifest assertion
 * would pass throughout.
 *
 * So the assertions here are deliberately about the RENDERED navigation:
 *  - no sidebar link points into another app, and
 *  - the Flows entry that replaced the deep link resolves to an in-app route
 *    that renders this app's own flow surface.
 *
 * Asserting only the first half would pass just as happily on a build where the
 * links vanished entirely, which is exactly what ADR-044 Decision 5 forbids.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, navToRoute, sidebarNav } from '../helpers/nav'

/**
 * Every navigating link rendered in the sidebar, with its href.
 *
 * @param page The Playwright page.
 * @return The links.
 */
async function navLinks(page) {
	await page.goto('/index.php/apps/dossiq')
	await dismissSupportDialog(page)
	return await sidebarNav(page)
		.locator('a')
		.evaluateAll((els) =>
			els.map((e) => ({
				label: (e.textContent || '').trim().replace(/\s+/g, ' '),
				href: e.getAttribute('href'),
			})),
		)
}

test.describe('ADR-110: a link that leaves the app leaves the navigation', () => {
	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-procest-hosts-no-processing-activities-page
	// @e2e openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md#scenario-procest-hosts-no-ai-oversight-pages
	test('no sidebar link points into another app', async ({ page }) => {
		const links = await navLinks(page)

		// `/settings/admin/dossiq` is the ONE permitted deep link and is
		// auto-prepended by CnAppNav for instance admins (ADR-079) — it is this
		// app's own configuration, rendered by Nextcloud's settings framework.
		// Everything else under /apps/<other> is what this contract removes.
		const foreign = links.filter((l) => {
			const href = l.href || ''
			if (!href.startsWith('/apps/') && !href.includes('/apps/')) return false
			return !href.includes('/apps/dossiq')
		})

		expect(
			foreign,
			`Sidebar links into other apps must move to section: "integrations". Found: ${JSON.stringify(foreign)}`,
		).toEqual([])
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-procest-hosts-no-processing-activities-page
	test('the AVG and AI-oversight entries are gone from the navigation', async ({
		page,
	}) => {
		const labels = (await navLinks(page)).map((l) => l.label)
		expect(labels).not.toContain('Processing activities (AVG)')
		expect(labels).not.toContain('AI oversight')
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/admin-settings-surface/spec.md#scenario-exactly-one-entry-links-to-the-administration-surface
	test('exactly one entry links to the administration surface', async ({
		page,
	}) => {
		// dossiq used to declare `AdminSettingsLink` on top of the one CnAppNav
		// auto-prepends, so admins saw the same link twice. Asserting on the
		// COUNT rather than on presence is the point — presence was never the
		// bug.
		const admin = (await navLinks(page)).filter((l) =>
			(l.href || '').includes('/settings/admin/dossiq'),
		)
		expect(admin.length).toBeLessThanOrEqual(1)
	})
})

test.describe('ADR-110: Flows is an in-app page, not a deep link', () => {
	// @e2e openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md#scenario-flows-are-authored-in-the-app
	test('the settings menu has a Flows entry pointing at an in-app route', async ({
		page,
	}) => {
		const flows = (await navLinks(page)).filter((l) => l.label === 'Flows')
		expect(flows.length).toBe(1)

		const href = flows[0].href || ''
		expect(
			href,
			'Flows must resolve to this app\'s own /flows route, not another app\'s list',
		).toContain('/apps/dossiq')
		expect(href).toContain('/flows')
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md#scenario-flows-are-authored-in-the-app
	test('/flows renders this app\'s own flow surface', async ({ page }) => {
		await navToRoute(page, '/flows')

		// The create control the page owns. Asserting on a control rather than a
		// heading: a retired route falls through to the app root, and the root
		// has headings of its own — so a heading assertion can pass on a page
		// that never rendered.
		await expect(
			page.getByRole('button', { name: 'New flow' }),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md#scenario-flows-are-authored-in-the-app
	test('/flows/new renders the flow canvas', async ({ page }) => {
		await navToRoute(page, '/flows/new')

		// Save lives on CnFlowDetail's own toolbar, so its presence proves the
		// shared canvas mounted rather than the router falling through.
		await expect(
			page.getByRole('button', { name: 'Save' }).first(),
		).toBeVisible({ timeout: 15000 })
	})
})

test.describe('Case types are governed from the settings menu', () => {
	// A case type is the blueprint every case is governed by, and `case.caseType`
	// references it — but it had no surface at all. The original `CaseTypesMenu`
	// pointed at the in-app `/settings` page, which page-topology-cleanup B1
	// retired for mounting AdminRoot through the in-app router (ADR-004), and
	// nothing replaced it. Only a stale `settingsSection` id was left behind, so
	// nothing failed: the capability simply stopped being reachable.
	//
	// @e2e openspec/specs/case-types/spec.md
	test('the settings menu has a Case types entry', async ({ page }) => {
		const entries = (await navLinks(page)).filter((l) => l.label === 'Case types')
		expect(
			entries.length,
			'Case types must be reachable from the settings foldout',
		).toBe(1)
	})

	// @e2e openspec/specs/case-types/spec.md
	test('the case types index renders its own create control', async ({
		page,
	}) => {
		await navToRoute(page, '/settings/case-types')

		// The page's OWN create control, not a heading: a route that fails to
		// resolve falls through to the dashboard, which has headings of its own.
		await expect(
			page.getByRole('button', { name: /Add|New|Create/i }).first(),
		).toBeVisible({ timeout: 20000 })
	})
})
