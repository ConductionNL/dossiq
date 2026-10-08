/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The work navigation group.
 *
 * "Work queue" is now "My work", and it gathers the five surfaces a handler
 * actually works from: the queue of cases nobody has picked up, the cases
 * assigned to them, every case, their tasks, and the workflow board. "Cases" is
 * no longer a separate top-level entry — it
 * lives in the group as "All cases". It was briefly "All issues"; dossiq talks
 * about cases everywhere else, and one surface calling them issues was the only
 * place the vocabulary broke.
 *
 * The page that used to be labelled "My work" is now "Assigned to me". Both
 * could not keep that name once the GROUP took it, and a sidebar reading
 * "My work > My work" says nothing about what the inner entry holds.
 *
 * Entries are asserted by PRESENCE, not visibility: they sit inside a
 * collapsible group and are rendered but hidden until it is expanded, so a
 * visibility assertion fails on a perfectly correct menu.
 *
 * WHAT THIS FILE CITES, AND WHAT IT DOES NOT. All four tests used to cite
 * `openspec/specs/my-work/spec.md` with no anchor, and that spec is about the
 * personal case index, not about the navigation. One test now proves a
 * scenario and cites it: add-work-queue's "The group offers all five
 * surfaces". The other three guard real regressions that no canonical
 * scenario states, so each carries a reason-bearing `@e2e exclude` instead of
 * a citation it cannot back.
 *
 * MUTATION POINT, NOT YET RUN. The mutation runs were refused by the
 * permission system on 2026-09-11. Client-side: in `src/menu-layout.json`,
 * remove `"WorkflowBoard": "WorkGroup"` from `relocations`, and Workflow board
 * renders at the top level. Expected red: `the My work group must hold
 * exactly Queue, Assigned to me, All cases, Tasks and Workflow board`.
 */

import { expect, test } from '@playwright/test'

test.describe('Work navigation', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load; the admin-settings spec in this suite documents the same
	// variability and sets an explicit budget rather than trusting a multiplier.
	test.setTimeout(300_000)

	// @e2e openspec/specs/add-work-queue/spec.md#the-group-offers-all-five-surfaces
	test('the work group is named after the work, and holds all five surfaces', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })
		const group = nav.locator('li.app-navigation-entry--collapsible', {
			has: page.locator('.app-navigation-entry__name', {
				hasText: /^\s*(My work|Mijn werk)\s*$/i,
			}),
		})
		await expect(group, 'the navigation must carry a My work group').toHaveCount(
			1,
			{ timeout: 30_000 },
		)

		// The scenario is about MEMBERSHIP, so the entries are read from inside
		// the group rather than from the whole menu. A label that exists
		// anywhere in the navigation used to satisfy this test, so Workflow
		// board relocated to the top level, or a sixth surface landing inside
		// the group, both passed. Each child's own name is read in order, and the
		// list must be exactly the five the scenario names.
		const children = await group.evaluate((li) =>
			Array.from(li.querySelectorAll('ul li')).map((child) =>
				(
					child.querySelector('.app-navigation-entry__name')?.textContent
					?? ''
				).trim(),
			),
		)
		const canonical: Array<[RegExp, string]> = [
			[/^(Queue|Werkvoorraad)$/i, 'Queue'],
			[/^(Assigned to me|Aan mij toegewezen)$/i, 'Assigned to me'],
			[/^(All cases|Alle zaken)$/i, 'All cases'],
			[/^(Tasks|Taken)$/i, 'Tasks'],
			[/^(Workflow board|Werkbord)$/i, 'Workflow board'],
		]
		const named = children.map(
			(label) =>
				canonical.find(([pattern]) => pattern.test(label))?.[1] ?? label,
		)
		expect(
			named,
			'the My work group must hold exactly Queue, Assigned to me, All cases, Tasks and Workflow board',
		).toEqual([
			'Queue',
			'Assigned to me',
			'All cases',
			'Tasks',
			'Workflow board',
		])
	})

	// @e2e exclude No canonical scenario states that these labels are retired.
	// nav-dedup REQ-PNDG-001 covers the All cases relabel, but its scenario
	// still requires a "Cases" group container, which the My work relocation
	// dissolved and which this test asserts is gone. A regression guard only.
	test('the retired labels are gone', async ({ page }) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		// A half-applied rename leaves the old label alongside the new one, and
		// only this assertion would catch it.
		await expect(
			nav.getByText(/^\s*(Work queue|Werkvoorraad)\s*$/i),
			'the old group label must not survive',
		).toHaveCount(0)
		await expect(
			nav.getByText(/^\s*(Cases|Zaken)\s*$/i),
			'"Cases" must not survive alongside "All cases"',
		).toHaveCount(0)
		await expect(
			nav.getByText(/^\s*(All issues)\s*$/i),
			'"All issues" must not survive alongside "All cases"',
		).toHaveCount(0)
	})

	// @e2e exclude The scenario this proves, "Contacts is a top-level entry",
	// lives in the unarchived contacts-domain change, which gate-19 cannot
	// read, and names tests/e2e/contacts-domain.spec.ts as its proof. That
	// Contacts is not a sixth work surface is asserted above, by the exact
	// five-member list.
	test('Contacts is a top-level domain, not a sixth work surface', async ({
		page,
	}) => {
		// The work group gathers the five surfaces a handler WORKS from. A
		// contact is not one of them: it is a thing you look up, and
		// contacts-domain spends one of ADR-097's top-level slots saying so.
		// Landing inside the group instead would be invisible on screen — the
		// group renders the entry either way — and would quietly make the
		// group six, which is the decision this asserts.
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		const contacts = nav.locator('a[href$="/contacts"]')
		await expect(contacts, 'Contacts must be in the navigation').toHaveCount(1)
		// Visible WITHOUT expanding the group: a leaf inside "My work" is
		// `display:none` until the group is opened.
		await expect(contacts).toBeVisible({ timeout: 30_000 })
	})

	// @e2e exclude No scenario states that /cases answers a deep link; the
	// cases index itself is cited by the case-management and case-list-lenses
	// tests. A routing regression guard only.
	test('the cases page stays reachable by direct link', async ({ page }) => {
		// Relabelling and relocating a menu entry must not move its ROUTE.
		// Bookmarks, shared links and the other specs in this suite all target
		// /cases, and none of them would notice until they broke.
		//
		// A PATH, not `#/cases`. dossiq runs on createWebHistory: a hash deep
		// link navigates NOWHERE and throws nothing, so this went to the app
		// root and rendered the DASHBOARD. `[data-testid="cn-page"]` is present
		// there too, so the test passed for years while proving nothing about
		// /cases. The heading assertion below is the other half of the fix: a
		// page-shell locator alone cannot tell these two pages apart.
		await page.goto('/index.php/apps/dossiq/cases')

		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the cases page must still render for a deep link',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.getByRole('heading', { name: /^(Cases|Zaken)$/i }).first(),
			'the deep link must land on the CASES page, not whatever the shell defaults to',
		).toBeVisible({ timeout: 30_000 })
	})
})
