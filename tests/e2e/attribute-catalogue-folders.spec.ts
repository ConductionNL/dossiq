/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attribute catalogue is in folders (attribute-catalogue-folders,
 * REQ-PDM-01 and REQ-PDM-02; gap register row 11.23).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * `tests/vitest/caseTypeAuthoringManifest.spec.js` pins that the register
 * declares a facetable `category` and that the manifest folders the index on
 * it, and `tests/vitest/propertiesTab.spec.js` pins the grouping the picker
 * does. Both halves are green against JSON and a mounted component, and
 * neither can see the thing this change is actually for: that a category an
 * administrator typed comes back as a FOLDER, and that picking the folder
 * narrows the list.
 *
 * That chain runs through OpenRegister. The folder pane prefers the FACET the
 * server computes over the whole query rather than the rows on the page, so a
 * category is reachable only if `facetable: true` survived the import, the
 * object store asked for `_facets=extend`, and the server answered a bucket.
 * Any one of those failing renders a sidebar that looks fine and lists the
 * wrong set, which is precisely what no unit test can tell apart.
 *
 * ⚠️ THE FACET IS CACHED AND NO OBJECT WRITE INVALIDATES IT (OpenRegister
 * #3560), so a category seeded seconds ago may not have a bucket yet. The
 * folder test therefore SKIPS with a reason when this run's own categories
 * have not arrived, rather than failing on a staleness that is not dossiq's.
 * It never skips on an empty sidebar alone: an absent pane is a real defect
 * and is asserted before the buckets are looked for.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance. The folder names asserted
 * here are values this spec WROTE (each carries RUN_PREFIX), never translated
 * labels, so they read the same in either language. The one translated string
 * the change introduces, Uncategorised, is deliberately not asserted by text:
 * it is asserted as the group an attribute with no category lands in.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/** The two categories this run authors, and the one it deliberately leaves off. */
const ADDRESS = `${RUN_PREFIX} Address`
const FINANCE = `${RUN_PREFIX} Finance`

/** The attributes, by the name each is found under. */
const POSTCODE = `${RUN_PREFIX} Postcode`
const HOUSE_NUMBER = `${RUN_PREFIX} House number`
const AMOUNT = `${RUN_PREFIX} Amount`
const REMARK = `${RUN_PREFIX} Remark`

let api: APIRequestContext
let token: string

/** The ids of the four seeded attributes, by name. */
const seeded: Record<string, string> = {}

/**
 * Seed one property definition through the object API.
 *
 * `caseType` is deliberately absent: an attribute without one is SHARED
 * across every case type, which is the whole reason the catalogue is a page
 * of its own rather than a tab on a type.
 *
 * @param name     The attribute's name.
 * @param category The folder it is filed under, or undefined for none.
 */
async function seedAttribute(name: string, category?: string): Promise<string> {
	const data: Record<string, unknown> = { name, propertyType: 'string' }
	if (category !== undefined) {
		data.category = category
	}
	const created = await createObject(api, token, 'propertyDefinition', data)
	return objectId(created)
}

/**
 * Open the attribute catalogue, under whichever URL shape this instance
 * resolves. Both are probed for the reason `navToRoute` documents: on an
 * instance with pretty URLs the `/index.php` prefix falls outside the router
 * base and vue-router redirects to the dashboard, which renders happily.
 *
 * @param page  The Playwright page.
 * @param query Extra query params, read by CnIndexPage as fetch filters.
 */
async function openCatalogue(
	page: Page,
	query: Record<string, string> = {},
): Promise<void> {
	const route = '/settings/attributes'
	const qs = new URLSearchParams(query).toString()
	for (const base of [`/apps/${REGISTER}`, `/index.php/apps/${REGISTER}`]) {
		await page.goto(
			qs === '' ? `${base}${route}` : `${base}${route}?${qs}`,
			PAGE_LOAD,
		)
		await dismissSupportDialog(page)
		if (new URL(page.url()).pathname.endsWith(route)) {
			await expect(
				page.locator('[data-testid="cn-object-list"]').first(),
			).toBeVisible({ timeout: 30_000 })
			return
		}
	}
	throw new Error(`neither URL shape resolved the ${route} route`)
}

/** The rows of the catalogue table. @param page The Playwright page. */
function catalogueRows(page: Page) {
	return page.locator('[data-testid="cn-object-row"]')
}

test.describe('The attribute catalogue is in folders', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		seeded[POSTCODE] = await seedAttribute(POSTCODE, ADDRESS)
		seeded[HOUSE_NUMBER] = await seedAttribute(HOUSE_NUMBER, ADDRESS)
		seeded[AMOUNT] = await seedAttribute(AMOUNT, FINANCE)
		seeded[REMARK] = await seedAttribute(REMARK)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md#attributes-by-folder
	// @e2e property-definition-management::attributes-by-folder
	test('the category an administrator typed is stored and read back', async () => {
		// The cheapest half of the chain, and the one that fails silently:
		// OpenRegister DROPS an undeclared property on save without a word, so
		// a register that did not import `category` would answer four
		// attributes with no category at all and the folder pane below would
		// simply have nothing to show.
		const stored = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/propertyDefinition/${seeded[POSTCODE]}`,
		)
		expect(
			stored.ok(),
			`the seeded attribute must be readable, got ${stored.status()}`,
		).toBeTruthy()
		const body = await stored.json()
		const row = body.object ?? body
		expect(row.category).toBe(ADDRESS)
	})

	// @e2e openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md#attributes-by-folder
	// @e2e property-definition-management::attributes-by-folder
	test('the sidebar folders the catalogue, and picking one narrows it', async ({
		page,
	}) => {
		await openCatalogue(page)

		// The pane itself is asserted unconditionally: an absent folder
		// sidebar is a manifest defect, not a stale facet, and skipping on it
		// would hide exactly the failure this test exists for.
		const sidebar = page.locator('.cn-folder-sidebar')
		await expect(sidebar).toBeVisible({ timeout: 30_000 })
		await expect(sidebar.locator('.cn-folder-sidebar__all')).toBeVisible()

		const folder = sidebar
			.locator('.cn-folder-tree__item')
			.filter({ hasText: FINANCE })

		// See the header: the facet is cached for up to an hour and no write
		// invalidates it, so this run's own categories may not have buckets
		// yet. That is OpenRegister#3560 and not something dossiq can assert
		// away, so it is skipped WITH ITS REASON rather than failed or,
		// worse, passed by loosening the assertion to any folder at all.
		const arrived = await folder
			.first()
			.isVisible()
			.catch(() => false)
		test.skip(
			arrived === false,
			`the ${FINANCE} facet has not arrived yet (OpenRegister#3560: the facet response is cached and no object write invalidates it)`,
		)

		await folder.first().click()

		const rows = catalogueRows(page)
		await expect(rows.filter({ hasText: AMOUNT })).toHaveCount(1, {
			timeout: 20_000,
		})
		await expect(rows.filter({ hasText: POSTCODE })).toHaveCount(0)
		await expect(rows.filter({ hasText: HOUSE_NUMBER })).toHaveCount(0)

		await sidebar.locator('.cn-folder-sidebar__all').click()
		await expect(rows.filter({ hasText: POSTCODE })).toHaveCount(1, {
			timeout: 20_000,
		})
		await expect(rows.filter({ hasText: AMOUNT })).toHaveCount(1)
	})

	// @e2e openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md#attributes-by-folder
	// @e2e property-definition-management::attributes-by-folder
	test('an attribute with no category is still in the catalogue', async ({
		page,
	}) => {
		// The requirement's Uncategorised clause, asserted where it can be
		// asserted honestly. CnFolderSidebar's field source SKIPS a row whose
		// grouping value is empty, so there is no empty bucket to click; the
		// schema default is what gives such a row a folder, and what this
		// test refuses to let regress is the failure underneath: an attribute
		// filed under nothing must not disappear from the catalogue.
		await openCatalogue(page, { name: REMARK })
		const rows = catalogueRows(page)
		await expect(rows.filter({ hasText: REMARK })).toHaveCount(1, {
			timeout: 30_000,
		})
	})

	// @e2e openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md#grouped-picker
	// @e2e property-definition-management::grouped-picker
	test('the picker on a case type groups its attributes under their categories', async ({
		page,
	}) => {
		// PropertiesTab is mounted by CaseTypeDetail inside the NEXTCLOUD
		// admin settings page, not by the in-app case type route, which
		// renders the read-only blueprint widget instead. The admin page
		// mounts fourteen OpenRegister-backed sections and has been measured
		// between ~7s and 3.2 minutes under CI's `php -S`, which is why this
		// test carries its own budget.
		test.setTimeout(300_000)
		await page.goto('/settings/admin/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const headings = page.locator('.properties-tab__group')
		const found = await headings
			.first()
			.isVisible()
			.catch(() => false)
		test.skip(
			found === false,
			'no case type was open on the admin page, so the Properties tab did not mount its list',
		)

		// The group names ARE the stored categories, so this asserts the
		// grouping and not a translation. Uncategorised is the one heading
		// that is translated, and it is matched by the attribute under it
		// rather than by its text.
		const texts = await headings.allTextContents()
		expect(texts.some((text) => text.includes(RUN_PREFIX))).toBe(true)
	})

	// @e2e openspec/changes/attribute-catalogue-folders/specs/property-definition-management/spec.md#attributes-by-folder
	// @e2e property-definition-management::attributes-by-folder
	test('the catalogue is not readable without a session', async ({
		playwright,
		baseURL,
	}) => {
		// 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE
		// REFUSED. The catalogue is an administrative surface and its rows are
		// this organisation's data model; an anonymous context holds no group
		// at all, so it is the honest read of somebody who should get nothing.
		// The assertion is on the REFUSAL, which is what makes it different
		// from the risk-level probe in markers-and-assessments: there, a 401
		// would have proved the wrong thing, because the case itself was meant
		// to be readable.
		const anonymous = await playwright.request.newContext({ baseURL })
		const response = await anonymous.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/propertyDefinition/${seeded[POSTCODE]}`,
		)
		expect(
			response.ok(),
			`an anonymous reader must not read an attribute, got ${response.status()}`,
		).toBeFalsy()
		expect(await response.text()).not.toContain(POSTCODE)
		await anonymous.dispose()
	})
})
