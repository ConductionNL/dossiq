/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Coverage for surfaces `page-topology-cleanup` RETIRED, and for what replaced
 * them.
 *
 * A retirement is the one change nothing else in the suite can catch. Every
 * other spec asserts that something renders; delete a page and those specs stay
 * green by simply not running. So the risk is the opposite of the usual one —
 * not "the page broke", but "the page is still there, or the thing that was
 * supposed to replace it never arrived and nobody noticed".
 *
 * These tests therefore assert BOTH halves: the old surface no longer renders
 * its own view, and the replacement is reachable. Asserting only the first half
 * would pass just as happily on a build where the capability vanished entirely.
 */

import { test, expect } from '@playwright/test'
import { navToRoute } from '../helpers/nav'

// NO trackDossiqErrors HERE, deliberately.
//
// A retired route falls through to the app root, so a console-error assertion
// on it grades the DASHBOARD's network traffic, not the retirement — and the
// dashboard legitimately 404s on every case/task fetch against an instance
// whose register is not seeded. That made the first version of this spec fail
// for a reason that had nothing to do with what it was testing. The specs that
// own the dashboard assert its console cleanliness; these assert that a view is
// gone and its replacement is present.

test.describe('Retired: automatic-actions settings page (C2)', () => {
	// The `automaticAction` objects this page administered were never executed
	// by anything — SideEffectDispatcher runs a separate vocabulary keyed on an
	// inline type. They migrate to OpenRegister flows via
	// `occ dossiq:actions:migrate-to-flows`.
	//
	// @e2e openspec/changes/page-topology-cleanup/proposal.md
	test('the retired route no longer renders an automatic-actions view', async ({
		page,
	}) => {
		await navToRoute(page, '/settings/automatic-actions')

		// The create control the page used to own. Asserting on THIS rather than
		// on a heading is deliberate: a heading can be absent because a page is
		// still loading, but the create control only exists when the retired
		// index view is actually mounted.
		await expect(
			page.getByRole('button', { name: 'Add Automatic Action' }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
		)
	})

	test('the settings menu deeplinks to OpenRegister flows instead', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// href, not a click: the target is another app, and following it would
		// make this a test of OpenRegister's Flows page rather than of dossiq's
		// menu. The hash is the part that matters — OpenRegister's router is
		// hash-based, and a link written without it lands on the app root.
		const link = page.locator('a[href="/apps/openregister/#/flows"]')
		await expect(link).toHaveCount(1)
	})
})

test.describe('Retired: besluitvorming agenda pages (D1)', () => {
	// decidiq owns agenda-building and meetings, and surfaces them on a case
	// through the `decidesk-decisions` integration leaf.
	//
	// @e2e openspec/changes/page-topology-cleanup/proposal.md
	test('the agenda compiler route no longer renders its view', async ({
		page,
	}) => {
		await navToRoute(page, '/besluitvorming/agenda')

		// The compiler's own control. A "no error" assertion alone would pass on
		// a build where the page still renders perfectly well.
		await expect(
			page.getByRole('heading', { name: /Agenda ?compiler|Agendacompiler/i }),
		).toHaveCount(0)
		// What DOES happen: the router falls through to the app root.
		await expect(page).toHaveURL(/\/apps\/dossiq\/?$/)
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
		)
	})

	test('the vergadering detail route no longer renders its view', async ({
		page,
	}) => {
		await navToRoute(page, '/besluitvorming/vergaderingen/does-not-exist')

		await expect(page).toHaveURL(/\/apps\/dossiq\/?$/)
		await expect(page.locator('body')).not.toContainText(
			'Internal Server Error',
		)
	})

	test('decidiq registers the decisions leaf that replaces them', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// The half that matters. Without it, "the agenda pages are gone" is
		// indistinguishable from "besluitvorming was deleted": the leaf is what
		// carries the capability now, and it is registered by decidiq's init
		// script on every page, not by dossiq.
		const leaf = await page.evaluate(() => {
			const registry = (
				window as unknown as {
					OCA?: { OpenRegister?: { integrations?: { list?: () => unknown[] } } }
				}
			).OCA?.OpenRegister?.integrations
			if (!registry?.list) {
				return null
			}

			const entries = registry.list() as Array<{ id?: string; tab?: unknown }>
			const found = entries.find((entry) => entry.id === 'decidesk-decisions')
			return found ? { id: found.id, hasTab: found.tab !== undefined } : null
		})

		// A null result means the registry itself is absent — report that as its
		// own failure rather than letting it read as "the leaf is missing".
		expect(leaf, 'OpenRegister integration registry not present').not.toBeNull()
		expect(leaf).toEqual({ id: 'decidesk-decisions', hasTab: true })
	})
})
