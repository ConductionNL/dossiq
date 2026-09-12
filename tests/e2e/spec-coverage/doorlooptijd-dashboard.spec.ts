/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for doorlooptijd-dashboard spec.
 * Each test is tagged with the scenario it covers.
 *
 * WHY NEITHER TEST SKIPS ANY MORE. Both used to wrap their one assertion in a
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
 *  - The empty-state test needed "zero cases in the system", and a CI
 *    instance never has that: the register import seeds cases
 *    (`lib/Settings/case_flow_seed_data.json`), and three other workers seed
 *    their own while this file runs. Deleting every case to get there is not
 *    an option: `case` is archival, so only an occ purge removes one, and it
 *    would pull rows out from under the other workers. So the test answers
 *    the dashboard's case read with an empty list at the network boundary,
 *    which is what the page sees on an instance with no cases. The service
 *    worker passes OpenRegister object reads straight through
 *    (`public/service-worker.js`), so `page.route` sees them. The test then
 *    checks the empty answer was actually the one the page read, so an empty
 *    state reached some other way (an unregistered object type, a failed
 *    fetch) cannot pass it.
 *
 * Both assert the English and the Dutch wording, because nothing pins the
 * locale of the instance under test.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken } from '../helpers/fixtures.ts'
import { navToRoute } from '../helpers/nav.ts'

/** The dashboard page's title, rendered by CnDashboardPage as its `<h2>`. */
const HEADING = /^(Processing time|Doorlooptijd)$/

/** DtKpiWidget's no-cases empty state, in either locale. */
const NO_CASES =
	/No case data available for processing time analysis\.|Geen zaakgegevens beschikbaar voor doorlooptijdanalyse\./

/**
 * The register and case schema the app's object store reads cases from.
 *
 * Read from the same endpoint `settingsStore.fetchSettings()` reads, because
 * the store builds its case URL from exactly these two values, and either can
 * be a slug or a numeric id depending on the instance.
 *
 * @param api An authenticated request context.
 * @return The register and schema segments of the case collection URL.
 */
async function caseCollection(
	api: APIRequestContext,
): Promise<{ register: string; schema: string }> {
	const token = await getRequestToken(api)
	const response = await api.get('/index.php/apps/dossiq/api/settings', {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
	expect(
		response.ok(),
		`GET /api/settings -> ${response.status()}: the dashboard reads the same`,
	).toBeTruthy()
	const body = await response.json()
	const config = body?.config ?? body ?? {}
	const register = String(config.register ?? '')
	const schema = String(config.case_schema ?? '')
	expect(
		register !== '' && schema !== '',
		'The app config must name a register and a case schema, or the dashboard has no case collection to read.',
	).toBeTruthy()
	return { register, schema }
}

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

	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#no-cases-exist
	test('shows empty state when no case data is available', async ({ page }) => {
		const { register, schema } = await caseCollection(page.request)
		const casePath = `/apps/openregister/api/objects/${register}/${schema}`

		// Only the COLLECTION read: a path with an id after the schema is a
		// single object, and nothing on this page reads one.
		let emptyAnswers = 0
		await page.route(
			(url) => url.pathname.endsWith(casePath),
			async (route) => {
				if (route.request().method() !== 'GET') {
					await route.fallback()
					return
				}
				emptyAnswers += 1
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						results: [],
						total: 0,
						page: 1,
						pages: 1,
					}),
				})
			},
		)

		await navToRoute(page, '/doorlooptijd')

		// The shell renders independently of whether case data is present.
		await expect(
			page.getByRole('heading', { name: HEADING, level: 2 }),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText(NO_CASES).first()).toBeVisible({
			timeout: 15000,
		})
		expect(
			emptyAnswers,
			`The empty state must come from the empty case list served for ${casePath}, not from a read that never happened.`,
		).toBeGreaterThan(0)

		// No broken charts or error states.
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(
			page.locator('.toast-error, .toastify.toast-error'),
		).toHaveCount(0)
	})
})
