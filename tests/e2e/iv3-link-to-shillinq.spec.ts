/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * case-costs-reported-per-iv3-taakveld task 7: the Reports page links to
 * Shillinq for costs per taakveld (REQ-IV3-CLASS-03). The card opens Shillinq;
 * without Shillinq it says the report is in Shillinq and shows no link and no
 * figure; and the page shows no money figure per taakveld or case type.
 *
 * THE CARD. `src/manifest.json` page `Reports` (`/reports`, type `reports`)
 * declares card `TaskFieldCosts`, "Costs per task field are in Shillinq"
 * ("Kosten per taakveld staat in Shillinq"), with `href`
 * `/index.php/apps/shillinq/`, `requiresApp: shillinq` and `missingText`
 * "This report is in Shillinq. Shillinq is not installed." nextcloud-vue's
 * `CnReportsPage` draws the page as a panel (`cn-reports-panel`), one
 * `cn-report-card` per card; a card whose app is missing draws its
 * `missingText` as `cn-report-unavailable` and no link at all.
 *
 * EACH HALF RUNS WHERE IT CAN BE TRUE. Whether Shillinq is enabled is asked of
 * Nextcloud's app list, not guessed from a 404. The "opens Shillinq" test
 * skips where Shillinq is absent; the "explains itself" test is precisely the
 * absent case, so it skips where Shillinq is present. The no-money test runs
 * on every instance.
 *
 * Seeds nothing, so there is nothing to clean up.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { provisioningContext } from './helpers/auth.ts'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The Reports page. */
const REPORTS_URL = `/apps/${REGISTER}/reports`

/** The card's title, in either locale. */
const CARD_TITLE = /(Costs per task field are in Shillinq|Kosten per taakveld staat in Shillinq)/

/** The card's sentence while Shillinq is missing, in either locale. */
const MISSING = /(This report is in Shillinq\. Shillinq is not installed\.|Dit rapport staat in Shillinq\. Shillinq is niet geïnstalleerd\.)/

/** An amount of money, the way the page would format one in either locale. */
const MONEY = /(€\s?\d|\d\s?€|\bEUR\s?\d|\d\s?EUR\b)/

/** Whether Shillinq is enabled on the instance. */
let shillinq = false

/**
 * Whether a Nextcloud app is enabled, read from the provisioning API.
 *
 * @param api   An administrator's context: the app list is admin-only.
 * @param appId The app id as `appinfo/info.xml` declares it.
 *
 * @return Whether it is enabled.
 */
async function appEnabled(api: APIRequestContext, appId: string): Promise<boolean> {
	const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	expect(
		res.ok(),
		`The enabled-apps list answered ${res.status()}, so whether ${appId} is installed is unknown.`,
	).toBeTruthy()
	const apps = ((await res.json())?.ocs?.data?.apps ?? []) as string[]

	return apps.includes(appId)
}

/**
 * Open the Reports page and wait for its cards.
 *
 * @param page The page.
 *
 * @return The Shillinq card.
 */
async function openReports(page: Page): Promise<Locator> {
	await page.goto(REPORTS_URL, PAGE_LOAD)
	await dismissSupportDialog(page)
	await expect(page.getByTestId('cn-report-card').first(), 'the Reports page draws its cards').toBeVisible({ timeout: 30_000 })
	const card = page.getByTestId('cn-report-card').filter({ hasText: CARD_TITLE })
	await expect(card, 'the Reports page has the Shillinq card').toHaveCount(1)

	return card
}

test.describe('Costs per taakveld are in Shillinq', () => {
	test.setTimeout(120_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const admin = await provisioningContext(playwright, String(baseURL))
		shillinq = await appEnabled(admin, 'shillinq')
		await admin.dispose()
	})

	// @e2e openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#scenario-the-card-opens-shillinq
	test('the card opens Shillinq', async ({ page }) => {
		test.skip(!shillinq, 'Shillinq is not enabled on this instance; the card has no link to follow.')
		trackDossiqErrors(page)
		const card = await openReports(page)

		const link = card.getByRole('link', { name: CARD_TITLE })
		await expect(link).toHaveAttribute('href', /\/apps\/shillinq\//)
		await Promise.all([
			page.waitForURL(/\/apps\/shillinq\//, { timeout: 45_000 }),
			link.click(),
		])
		await expect(page, 'the reader lands in Shillinq, not on a dossiq page').not.toHaveURL(new RegExp(`/apps/${REGISTER}/`))
	})

	// @e2e openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#scenario-without-shillinq-the-card-explains-itself
	test('without Shillinq the card says so, with no link and no figure', async ({ page }) => {
		test.skip(shillinq, 'Shillinq is enabled on this instance; this scenario is the instance without it.')
		trackDossiqErrors(page)
		const card = await openReports(page)

		await expect(card.getByTestId('cn-report-unavailable')).toHaveText(MISSING)
		await expect(card.locator('a'), 'the card links nowhere while Shillinq is missing').toHaveCount(0)
		expect((await card.textContent()) ?? '', 'the card shows no figure').not.toMatch(MONEY)
	})

	// @e2e openspec/changes/case-costs-reported-per-iv3-taakveld/specs/iv3-taakveld-2023-refinement/spec.md#scenario-without-shillinq-the-card-explains-itself
	test('the Reports page shows no money figure per taakveld or case type', async ({ page }) => {
		trackDossiqErrors(page)
		// A cost figure would have to be read from somewhere: dossiq's retired
		// Iv3 route or a sum over a cost field. Neither may be asked for.
		const costReads: string[] = []
		page.on('request', (request) => {
			if (/\/api\/reports\/iv3|taakveld|iv3TaskField.*(sum|cost)/i.test(request.url())) {
				costReads.push(request.url())
			}
		})

		await openReports(page)
		const reports = page.locator('#content, main').first()
		const text = (await reports.textContent()) ?? ''
		expect(text, 'no amount of money anywhere on the Reports page').not.toMatch(MONEY)
		expect(costReads, 'the page asks for no cost per taakveld').toEqual([])

		const retired = await page.request.get(`/index.php/apps/${REGISTER}/api/reports/iv3/taakvelden`)
		// The SPA catch-all answers any unrouted GET with the page shell, so
		// "retired" means no JSON answer, not necessarily a 404.
		const answeredData = retired.ok() && (retired.headers()['content-type'] ?? '').includes('json')
		expect(answeredData, 'dossiq serves no taakveld list or Iv3 report of its own').toBe(false)
	})
})
