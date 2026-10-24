/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for doorlooptijd-dashboard spec.
 * Each test is tagged with the scenario it covers.
 *
 * WHY THE TEST DOES NOT SKIP. The tests used to wrap their one assertion in a
 * try/catch that turned a miss into `test.skip`, so on CI the file executed
 * nothing, and gate-19 still accepted it as the anchor for both scenarios.
 *
 *  - The heading test waited for "Processing Time Analytics". That string was
 *    the `<h2>` of `DoorlooptijdDashboard.vue`, which page-topology-cleanup
 *    (A3) deleted: the page is now a manifest `type: "dashboard"` and its
 *    heading is the page TITLE, "Processing time", rendered by CnDashboardPage
 *    as its one `<h2>`. The old string is on no page, so the wait always timed
 *    out and the catch read that as "the shell did not mount in this
 *    environment". It asserts the heading the page renders now.
 *    `spec-coverage/ui-pages.spec.ts` asserts the same heading.
 *
 *  - The empty-state test asserted the no-cases text of DtKpiWidget, which no
 *    manifest mounted any more, so it tested nothing live. The widget and the
 *    test are gone.
 *
 * The heading test asserts the English and the Dutch wording, because nothing
 * pins the locale of the instance under test.
 */

import { expect, test } from '@playwright/test'
import { navToRoute } from '../helpers/nav.ts'

/** The dashboard page's title, rendered by CnDashboardPage as its `<h2>`. */
const HEADING = /^(Processing time|Doorlooptijd)$/

test.describe('Doorlooptijd Dashboard spec coverage', () => {
	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#doorlooptijd-page-renders-heading
	//
	// 🔴 THE SPEC WAS THE STALE HALF, AND HAS BEEN CORRECTED. This measured as
	// verified on 2026-09-11 and as smoke on 2026-09-12. The reason was not a
	// weaker test: the scenario named a "Processing Time Analytics" heading the
	// page has not rendered for some time, so nothing here could ever have
	// proven the clause, and a heading plus the absence of a 500 is what a page
	// shell renders too. `src/manifest.json` is the authority on the page's
	// title and reads `Processing time`; the scenario now says that, and says
	// the body must render its widgets, which is the half that separates a
	// dashboard from a shell.
	//
	// ✅ MUTATION CHECK RUN 2026-09-12, with `tests/e2e/helpers/mutate-bundle.ts`.
	//
	//   find    /"id":"Doorlooptijd","route":"\/doorlooptijd","type":"dashboard","title":"Processing time"/
	//   replace '"id":"Doorlooptijd","route":"/doorlooptijd","type":"dashboard","title":"Verwerkingstijd-x"'
	//   red on  "the page must name itself as the manifest titles it"
	test('renders the processing time page heading on navigation', async ({
		page,
	}) => {
		await navToRoute(page, '/doorlooptijd')
		await expect(
			page.getByRole('heading', { name: HEADING, level: 2 }),
			'the page must name itself as the manifest titles it',
		).toBeVisible({ timeout: 15000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')

		// AND the body renders its widgets. Without this the scenario is
		// satisfied by page chrome over an empty main, which is what "smoke"
		// meant when this citation was re-measured. Counted rather than named:
		// the widget set is manifest-driven and moves with the dashboard, and
		// the claim is that the body is populated at all.
		await expect(
			page.locator('.cn-dashboard-grid .grid-stack-item-content').first(),
			'the dashboard body must render its widgets, not page chrome alone',
		).toBeVisible({ timeout: 20000 })
	})
})
