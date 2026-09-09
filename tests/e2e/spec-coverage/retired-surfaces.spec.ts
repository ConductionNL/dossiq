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

import { expect, test } from '@playwright/test'
import { navToRoute } from '../helpers/nav.ts'

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
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('flows are authored in this app, not behind a deep link', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// UPDATED BY ADR-110. This test used to assert the opposite — that the
		// settings menu carried `a[href="/apps/openregister/#/flows"]`, a link
		// out to another app's list. That deep link is gone: a flow is
		// app-specific (a dossiq flow operates on cases), so the authoring
		// surface is now `/flows` and `/flows/:id` in this app, on the shared
		// canvas over the same single engine (ADR-065).
		//
		// Asserting BOTH halves on purpose. The absence check alone would pass
		// just as happily on a build where the capability vanished entirely,
		// which is exactly what ADR-044 Decision 5 forbids.
		await expect(
			page.locator('a[href="/apps/openregister/#/flows"]'),
		).toHaveCount(0)

		await expect(page.locator('a[href$="/apps/dossiq/flows"]')).toHaveCount(1)
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
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('the vergadering detail route no longer renders its view', async ({
		page,
	}) => {
		await navToRoute(page, '/besluitvorming/vergaderingen/does-not-exist')

		await expect(page).toHaveURL(/\/apps\/dossiq\/?$/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('decidiq registers the decisions leaf that replaces them', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// The half that matters. Without it, "the agenda pages are gone" is
		// indistinguishable from "besluitvorming was deleted": the leaf is what
		// carries the capability now, and it is registered by decidiq's init
		// script on every page, not by dossiq.
		// FOUR OUTCOMES, not three. The previous version read the ABSENCE OF THE
		// REGISTRY as "the decision app is not installed", and those are two
		// different apps: the registry is OpenRegister's, installed by its
		// `openregister-integration-global` bundle on every page. That bundle
		// was never built in CI (openregister gitignores `/js/`, and the shared
		// workflow cloned it without building), so the registry was absent on
		// every run and this test skipped on every run, reporting a fact about
		// openregister's packaging in the words of decidiq's absence. A skip
		// renders exactly like a pass, so it read as covered for as long as it
		// existed.
		//
		// ConductionNL/.github#722 builds that bundle, and the moment it did,
		// this test started running and failing — correctly, because decidiq
		// really is not installed here. So the guard now asks the question it
		// was always meant to ask, of the right app.
		//
		// `OC.appswebroots` is how Nextcloud itself resolves an installed app,
		// and it is already this suite's probe for exactly this (see
		// dutch-value-l10n.spec.ts). BOTH ids are accepted: the fleet rename is
		// in flight and `lib/Support/FleetAppId.php` maps
		// `decidiq => ['decidiq', 'decidesk']`, so naming only one would make
		// this skip on whichever side of the rename the instance is on.
		const probe = await page.evaluate(() => {
			const oc = (
				window as unknown as {
					OC?: { appswebroots?: Record<string, string> }
				}
			).OC
			const roots = oc?.appswebroots ?? {}
			const decisionsApp =
				['decidiq', 'decidesk'].find((id) => id in roots) ?? null

			const registry = (
				window as unknown as {
					OCA?: {
						OpenRegister?: { integrations?: { list?: () => unknown[] } }
					}
				}
			).OCA?.OpenRegister?.integrations
			if (!registry?.list) {
				return { decisionsApp, registry: false as const }
			}

			const entries = registry.list() as Array<{ id?: string; tab?: unknown }>
			const found = entries.find((entry) => entry.id === 'decidesk-decisions')
			return {
				decisionsApp,
				registry: true as const,
				ids: entries.map((entry) => entry.id).filter(Boolean),
				leaf: found
					? { id: found.id, hasTab: found.tab !== undefined }
					: null,
			}
		})

		// THE ONLY ENVIRONMENT FACT THAT EARNS A SKIP: the app that registers
		// the leaf is not on this instance. dossiq's CI installs openregister
		// and nothing else, so this is the branch CI takes — but it now says so
		// about the app it actually measured.
		test.skip(
			probe.decisionsApp === null,
			'neither `decidiq` nor `decidesk` is installed on this instance, so nothing can register the decisions leaf',
		)

		// The decisions app IS installed. An absent registry is now a REAL
		// failure and must not be skipped past: it means OpenRegister shipped
		// without its integration bundle, and every integration leaf in this
		// app is silently rendering nothing. That is the condition that hid
		// this test for its whole life, so it fails loudly rather than quietly.
		expect(
			probe.registry,
			`\`${probe.decisionsApp}\` is installed but OpenRegister's integration registry is absent, so no integration leaf can render. Its \`openregister-integration-global\` bundle is missing — an unbuilt openregister checkout serves nothing to register the providers with.`,
		).toBe(true)

		// Registry present: now a missing leaf IS a real finding, and the message
		// can name what was actually registered instead of guessing.
		expect(
			probe.leaf,
			`decidesk-decisions leaf not registered; registry holds: ${JSON.stringify(probe.ids)}`,
		).not.toBeNull()
		expect(probe.leaf).toEqual({ id: 'decidesk-decisions', hasTab: true })
	})
})
