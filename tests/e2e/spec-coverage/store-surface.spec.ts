/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the store surface (ADR-080).
 *
 * The scenario worth driving a browser for is the OFFLINE one, and it is the
 * one the ADR turns on: with no registry configured the page must render
 * dossiq's own templates and make NO outbound request. "Renders something"
 * is not the assertion — a page that quietly called a registry and fell back
 * on the error would look identical. So the network is watched, and the
 * absence of the call is asserted directly.
 *
 * The install allowlist is a server-side boundary and is proven by
 * StoreControllerTest, including a negative control that widens the list and
 * watches the refusal tests fail. Those scenarios carry `@e2e exclude` in the
 * spec: a browser cannot see which schema a write went to.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, navTo } from '../helpers/nav.ts'

test.describe('Store surface', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load; the neighbouring specs set the same explicit budget.
	test.setTimeout(300_000)

	// ✅ THE REFUSAL THAT STOOD HERE IS LIFTED, because the missing half is now
	// asserted. It said this test proved only clause 2 of REQ-DSS-006's
	// scenario, the footer placement, and that anchoring a two-clause scenario
	// to a one-clause test would read as coverage of the icon as well. It also
	// said what was needed: a run, to pin the selector the deployed nav renders
	// the glyph with rather than guess it from the source. That run happened.
	//
	// WHAT THE GLYPH IS, ON SCREEN. `CnAppNav` gives each entry a
	// `data-testid="cn-nav-entry-<id>"`, and inside it renders
	// `mdiIconComponent(item)` when the name resolves in the registry
	// `registerIcons()` filled from `src/icons.js`. A `vue-material-design-icons`
	// component renders `class="material-design-icon store-outline-icon"`, so
	// the icon the manifest declares is readable off the DOM by that class.
	//
	// 🔴 WHY A WRONG NAME IS NOT AN ERROR, which is what makes this worth
	// asserting at all: `isUnresolvedIcon()` catches any PascalCase name the
	// registry does not hold and `CnAppNav` renders `HelpCircleOutline`
	// instead, so a typo, a rename or an icon dropped from `src/icons.js` ships
	// a generic question mark in the navigation and nothing anywhere reports
	// it.
	//
	// ⚠️ AND A SECOND ASSERTION HERE WOULD HAVE BEEN DECORATION. This carried
	// `expect('.help-circle-outline-icon').toHaveCount(0)` beside the positive,
	// described as the load-bearing half. It is not, and the mutation below
	// showed it: CnAppNav's icon slot is `v-if mdiIconComponent` /
	// `v-else-if isUnresolvedIcon` / `v-else CnMenuItemIcon`, three mutually
	// exclusive branches, so "the StoreOutline glyph is absent" and "the
	// fallback is present" are ONE event rather than two. The positive throws
	// first and the negative never ran. What it was actually good for — naming
	// the fallback in the failure — is kept by reading the rendered class list
	// in a single assertion, so the diagnostic lands in the message instead of
	// in a check that cannot fire.
	//
	// ✅ MUTATION CHECK RUN 2026-09-12, with `tests/e2e/helpers/mutate-bundle.ts`
	// against a private disposable instance. The manifest's declared name was
	// rewritten on the way to the browser, leaving the `src/icons.js` registry
	// intact, which is the defect this clause guards:
	//
	//   find    /"label":"Store","icon":"StoreOutline"/
	//   replace '"label":"Store","icon":"StoreOutlineXX"'
	//   red on  Expected substring: "store-outline-icon"
	//           Received string:    "material-design-icon help-circle-outline-icon"
	//
	// The footer-placement clause below stayed green throughout, which is why
	// it could never have stood in for this one.
	//
	// @e2e openspec/specs/dossiq-store-surface/spec.md#the-entry-carries-the-tier-a-glyph
	test('the store entry carries the StoreOutline glyph and sits between Documentation and Reports', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		await expect(
			nav.getByText(/^\s*Store\s*$/i),
			'the navigation must offer a Store entry',
		).toHaveCount(1, { timeout: 30_000 })

		// CLAUSE 1: the glyph the manifest declares, read off the entry itself.
		const entry = nav.locator('[data-testid="cn-nav-entry-StoreMenu"]')
		await expect(
			entry,
			'the Store entry must render under the id the manifest gives it',
		).toHaveCount(1, { timeout: 30_000 })
		await expect(
			entry.locator('.material-design-icon').first(),
			'the Store entry must render an icon at all',
		).toBeAttached({ timeout: 30_000 })
		// ONE assertion, reading the glyph the entry actually rendered. A
		// second `toHaveCount(0)` on `.help-circle-outline-icon` was here and
		// is gone: the branches are exclusive, so it could never fail on its
		// own, and written after this line it never even ran. Reading the class
		// list instead keeps the single claim and puts the fallback in the
		// failure message, which is the part that was worth having.
		await expect
			.poll(
				async () =>
					(
						await entry
							.locator('.material-design-icon')
							.evaluateAll((nodes) =>
								nodes.map((node) => node.className),
							)
					).join(' '),
				{
					timeout: 30_000,
					message:
						'the Store entry must render the StoreOutline glyph the manifest '
						+ 'declares; `help-circle-outline-icon` here means the name did '
						+ 'not resolve in the registry and CnAppNav fell back',
				},
			)
			.toContain('store-outline-icon')

		// Order, not merely presence. The entry was placed at order 92
		// deliberately, and an entry that exists in the wrong place is the
		// defect a presence-only assertion cannot see.
		const labels = await nav
			.locator('a, li')
			.allInnerTexts()
			.then((texts) => texts.map((entry) => entry.trim()))

		const documentation = labels.findIndex((label) =>
			/^Documentation$/i.test(label),
		)
		const store = labels.findIndex((label) => /^Store$/i.test(label))
		const reports = labels.findIndex((label) => /^Reports$/i.test(label))

		expect(documentation, 'Documentation must be in the footer').toBeGreaterThan(
			-1,
		)
		expect(store, 'Store must be in the footer').toBeGreaterThan(-1)
		expect(reports, 'Reports must be in the footer').toBeGreaterThan(-1)
		expect(store, 'Store must follow Documentation').toBeGreaterThan(
			documentation,
		)
		expect(store, 'Store must precede Reports').toBeLessThan(reports)
	})

	// @e2e dossiq-store-surface::an-unconfigured-instance-stays-offline
	// @e2e dossiq-store-surface::the-page-still-renders
	//
	// The citation named the spec FILE and no requirement, so gate-19 credited
	// it to nothing. It proves both of REQ-DSS-002's scenarios, clause for
	// clause, so it now says which:
	//
	//   "the response outcome MUST be `not_configured`"  -> store-not-configured
	//   "no outbound HTTP request MUST be made"          -> external toEqual([])
	//   "MUST render dossiq's built-in templates rather  -> store-builtin
	//    than an error"                                     and store-page
	//
	// Two anchors rather than one because they are two scenarios and this test
	// carries both; splitting the test would leave each half proving less.
	test('an unconfigured instance renders the built-in templates and calls no registry', async ({
		page,
	}, testInfo) => {
		// The comparison host comes from the CONFIGURED base URL, not from
		// page.url(). Requests fire while the page is still about:blank, whose
		// host is the empty string, so comparing against the live URL counts
		// every same-origin asset as external and the assertion fails for a
		// reason that has nothing to do with the store.
		const baseHost = new URL(String(testInfo.project.use.baseURL)).host

		// Watch every request the page makes BEFORE navigating, so a call made
		// during load is caught rather than missed.
		const external: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			// Same-origin traffic is the app itself. What must not happen is a
			// call to somebody else's registry.
			if (url.startsWith('http') === true && new URL(url).host !== baseHost) {
				external.push(url)
			}
		})

		await navTo(page, /^Store$/)
		await dismissSupportDialog(page).catch(() => {})

		await expect(
			page.locator('[data-testid="store-page"]'),
			'the store page must render',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.locator('[data-testid="store-not-configured"]'),
			'an instance with no registry must say so rather than showing an error',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.locator('[data-testid="store-builtin"]'),
			'the built-in templates are the fallback surface',
		).toBeVisible({ timeout: 30_000 })

		expect(
			external,
			'an unconfigured store must make NO outbound request',
		).toEqual([])
	})

	// @e2e exclude No requirement in dossiq-store-surface covers deep-link
	// routing. REQ-DSS-002's two scenarios (the offline answer and the
	// built-in templates) are proven by the test above, and REQ-DSS-006's
	// placement by the first one. This is a routing regression guard and
	// claims no requirement.
	test('the store page is reachable by direct link', async ({ page }) => {
		// Relabelling or moving the menu entry must not move the ROUTE.
		//
		// A PATH, not `#/store`. dossiq runs on createWebHistory, where a hash
		// deep link navigates NOWHERE and throws nothing: the shell renders the
		// Dashboard and an assertion on "some page rendered" would pass against
		// the wrong page entirely. The sidebar link's own href is this path.
		await page.goto('/index.php/apps/dossiq/store')

		await expect(
			page.locator('[data-testid="store-page"]'),
			'the store page must render for a deep link',
		).toBeVisible({ timeout: 60_000 })
	})
})
