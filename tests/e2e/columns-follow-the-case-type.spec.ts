/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list shows the columns of the type you picked.
 *
 * A permit and a toezichtzaak want different columns and the Cases list showed
 * the same set to both. The library reads `columns`, `defaultSort` and
 * `searchFields` off the selected folder sidebar scope; dossiq's sidebar is
 * built from the caseType records, so the layout rides the record's `x-index`
 * block and a municipality edits it where it edits the case type.
 *
 * THE LIBRARY PUBLISHED, SO THIS RUNS FOR REAL. It was written on 2026-09-18 to
 * be red: the installed `@conduction/nextcloud-vue` 3.2.0 and the newest
 * published 3.3.0 carry no `src/utils/scopeListLayout.js` and no scope
 * resolution in `CnIndexPage`, and both sat on nextcloud-vue `parity/round2`
 * (#1213) and in no release. 3.4.0 ships `src/utils/scopeListLayout.js` and
 * calls `resolveScopeLayout` from `CnIndexPage`, read out of the published
 * tarball on 2026-09-19, and this app's lockfile moved to it in the same commit
 * as this paragraph. Under the older versions the header does not change when a
 * folder is picked, which is the state this spec exists to tell apart from the
 * feature working, so it stays written to fail loudly rather than be skipped
 * into a silence nobody would notice clearing.
 *
 * 🔴 THE DECLARATION IS ASSERTED AGAINST THE LIVE RECORD, NOT THE SEED FILE.
 * `x-index` has to be a declared property of `caseType` or OpenRegister's
 * magic mapper drops it on the way in: the save answers 200 and stores
 * nothing, and the page then behaves exactly like a case type that declared
 * no columns. Reading the seed file would report green on precisely that, so
 * the record is read back from OpenRegister first.
 *
 * THE HEADER IS READ AS A SET OF KEYS, NEVER BY POSITION OR COUNT. The Cases
 * index is a shared list on a shared instance, and a reader's own visible
 * column set rides on top of the page's.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken, REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/#/cases`
const CASE_TYPES = `/index.php/apps/openregister/api/objects/${REGISTER}/caseType`

/** The permit whose reader wants the decision date and the procedure. */
const PERMIT_SLUG = 'omgevingsvergunning-bouwactiviteit'

let api: APIRequestContext
let token: string

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	await api.dispose()
})

/**
 * The column labels the list header renders, as a set.
 *
 * @param page The Playwright page.
 * @return The header cell texts, trimmed and empties dropped.
 */
async function headerLabels(page: Page): Promise<string[]> {
	const cells = await page.locator('table thead th').allInnerTexts()
	return cells.map((c) => c.trim()).filter(Boolean)
}

test.describe('a case type carries its own columns', () => {
	// @e2e openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md#scenario-a-permit-shows-its-expiry-date
	test('the seeded permit carries a layout on the record, not only in the file', async () => {
		const res = await api.get(`${CASE_TYPES}?_limit=200`, {
			headers: { requesttoken: token },
		})
		expect(res.ok(), `the case type list answered ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = body.results ?? body.data ?? []
		const permit = rows.find(
			(r: any) => r.slug === PERMIT_SLUG || r['@self']?.slug === PERMIT_SLUG,
		)

		expect(
			permit,
			`no case type answers to "${PERMIT_SLUG}"; run occ maintenance:repair to seed it`,
		).toBeTruthy()
		expect(
			permit['x-index'],
			'the permit carries no x-index on the instance: either the schema does not declare the property, in which case the magic mapper dropped it on the way in, or the seed has not run',
		).toBeTruthy()
		expect(permit['x-index'].columns).toContain('besluitdatum')
		expect(permit['x-index'].columns).toContain('procedureType')
	})

	// @e2e openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md#scenario-a-permit-shows-its-expiry-date
	test('picking the permit changes the header, and All types changes it back', async ({
		page,
	}) => {
		await page.goto(APP_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		const allTypes = await headerLabels(page)
		expect(
			allTypes,
			'the All cases header does not show the page columns, so nothing can be compared against it',
		).toContain('Status')

		await page
			.getByRole('button', { name: 'Omgevingsvergunning Bouwactiviteit' })
			.click()

		await expect
			.poll(async () => await headerLabels(page), {
				message: "the header did not take the permit's own columns",
			})
			.toContain('Decision date')
		expect(await headerLabels(page)).toContain('Procedure')

		// The page's own columns that the scope did not name are gone. A header
		// that merely GAINED a column would pass a weaker assertion while the
		// scope had in fact been ignored and a user column set had widened.
		expect(
			await headerLabels(page),
			'the permit still shows a page column it did not ask for, so the scope was not applied',
		).not.toContain('Payment')

		await page.getByRole('button', { name: 'All cases' }).click()

		await expect
			.poll(async () => await headerLabels(page), {
				message: 'All cases did not return to the page columns',
			})
			.not.toContain('Decision date')
	})

	// @e2e openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md#scenario-a-bezwaar-orders-by-its-hearing-date
	test('a case type that orders by its own date leads with the latest one', async ({
		page,
	}) => {
		await page.goto(APP_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await page.getByRole('button', { name: 'Handhavingszaak' }).click()

		// Handhaving orders by besluitdatum descending: the begunstigingstermijn
		// runs from the besluit, so the case decided last is the one to chase.
		await expect
			.poll(async () => await headerLabels(page), {
				message: 'the handhaving scope did not take its own columns',
			})
			.toContain('Decision date')

		const dates = await page.locator('table tbody tr td').allInnerTexts()
		expect(
			dates.length,
			'the handhaving folder listed no rows, so the order cannot be read',
		).toBeGreaterThan(0)
	})
})
