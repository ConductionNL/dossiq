import type { APIRequestContext } from '@playwright/test'

/**
 * The case list filters on a case type's own fields.
 *
 * A handler working permits does not search for a word. They search for a
 * permit whose declared construction cost is over a hundred thousand euro.
 * That value is not on the case: dossiq stores each declared field as a
 * `caseProperty` row pointing back at it, and until openregister shipped
 * `query-related-schema-rows` there was no query that could ask about it. A
 * handler exported the list and filtered it in a spreadsheet.
 *
 * 🔴 THE CASE THAT FAILS THE FILTER IS ASSERTED ABSENT, NOT JUST THE ONE THAT
 * PASSES. A filter openregister does not understand narrows NOTHING and
 * answers the whole register, which looks exactly like a working page to
 * anyone checking that the case they expected is listed. So every filter test
 * below names a case that must NOT be there, and it is seeded to be there
 * without the filter.
 *
 * 🔴 THE URL IS NOT THE TRANSPORT, WHICH IS WHY THE DEEP-LINK TESTS BELOW ARE
 * REAL TESTS. `CnIndexPage` builds its filters with `resolveQueryFilters()`,
 * which skips the underscore namespace, so a `_related` key in the address
 * bar is dropped before the fetch. The bar hands each block to the list
 * through `sidebarState.onFilterChange` as well, and replays the route's
 * blocks on mount. A run where the address bar carries `_related` and the
 * list is not narrowed is that hop missing, not openregister refusing.
 *
 * 🔴 A REFUSAL IS NOT AN EMPTY RESULT. openregister refuses a malformed
 * `_related` block with a sentence rather than running it, but the object
 * store records the refusal and returns `[]`, so `CnIndexPage` draws its empty
 * state over it. The last test drives a refused block and asserts the notice,
 * not the empty state.
 *
 * THE CASES ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs.
 *
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	FIXTURE_SCHEMAS,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/#/cases`

let api: APIRequestContext
let token: string
let caseTypeId = ''
let costDefinitionId = ''

/** The three cases, by the key the tests know them under. */
const cases: Record<string, string> = {}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)

	const caseType = await ensureCaseType(api, token)
	caseTypeId = caseType.id

	// A filterable definition, and a second one that declares nothing, so the
	// bar can be asserted to offer exactly one.
	const cost = await createObject(api, token, 'propertyDefinition', {
		name: `${RUN_PREFIX}-bouwkosten`,
		propertyType: 'number',
		caseType: caseTypeId,
		filterable: true,
	})
	costDefinitionId = objectId(cost)

	await createObject(api, token, 'propertyDefinition', {
		name: `${RUN_PREFIX}-intern`,
		propertyType: 'number',
		caseType: caseTypeId,
	})

	for (const [key, value] of [
		['expensive', 150000],
		['cheap', 90000],
	] as const) {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} ${key}`,
			caseType: caseTypeId,
		})
		cases[key] = objectId(seeded)

		await createObject(api, token, 'caseProperty', {
			case: cases[key],
			propertyDefinition: costDefinitionId,
			value: String(value),
		})
	}
})

test.afterAll(async () => {
	// `propertyDefinition` is NOT in FIXTURE_SCHEMAS, so it is named here. A
	// definition left behind is not inert: it is offered as a filter on that
	// case type to every handler on the instance, for ever.
	await cleanupRunObjects(api, token, ['propertyDefinition', ...FIXTURE_SCHEMAS])
	await api.dispose()
})

test.describe('a case type says which of its fields are worth filtering on', () => {
	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-a-definition-that-declares-nothing-is-not-offered
	test('the schema stores filterable, so the declaration reaches the instance', async () => {
		const res = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/propertyDefinition/${costDefinitionId}`,
			{ headers: { requesttoken: token } },
		)
		expect(
			res.ok(),
			`reading the definition answered ${res.status()}`,
		).toBeTruthy()
		const stored = await res.json()

		expect(
			stored.filterable,
			'filterable did not survive the write, which is what an undeclared property looks like: the save answers 200 and the bar then offers nothing',
		).toBe(true)
	})

	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-a-definition-that-declares-nothing-is-not-offered
	// @e2e case-search-and-lists::the-bar-offers-the-declared-field-and-not-the-silent-one
	test('the bar offers the declared field and not the silent one', async ({
		page,
	}) => {
		await page.goto(`${APP_URL}?caseType=${caseTypeId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const bar = page.getByTestId('case-type-field-filters')
		await expect(bar).toBeVisible()
		await expect(bar).toContainText(`${RUN_PREFIX}-bouwkosten`)
		await expect(
			bar,
			'a definition that declares nothing was offered as a filter',
		).not.toContainText(`${RUN_PREFIX}-intern`)
	})

	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-clearing-the-case-type-clears-its-field-filters
	test('no case type picked renders no bar at all', async ({ page }) => {
		await page.goto(APP_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByTestId('case-type-field-filters')).toHaveCount(0)
	})
})

test.describe('the case list filters on a case type own fields', () => {
	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-two-fields-narrow-to-the-cases-that-satisfy-both
	// @e2e case-search-and-lists::a-field-filter-keeps-the-matching-case-and-drops-the-other
	test('a cost filter lists the expensive case and NOT the cheap one', async ({
		page,
	}) => {
		// Unfiltered first: both cases are here, so a later absence is the
		// filter working rather than a fixture that never landed.
		await page.goto(`${APP_URL}?caseType=${caseTypeId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByText(`${RUN_PREFIX} expensive`)).toBeVisible()
		await expect(page.getByText(`${RUN_PREFIX} cheap`)).toBeVisible()

		await page
			.getByTestId(`field-filter-${costDefinitionId}-from`)
			.fill('100000')

		await expect(page.getByText(`${RUN_PREFIX} expensive`)).toBeVisible()
		await expect(
			page.getByText(`${RUN_PREFIX} cheap`),
			'the case that fails the filter is still listed, so the filter narrowed nothing and the list is the whole register',
		).toHaveCount(0)
	})

	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-clearing-the-case-type-clears-its-field-filters
	test('clearing the case type clears the field filters with it', async ({
		page,
	}) => {
		await page.goto(`${APP_URL}?caseType=${caseTypeId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page
			.getByTestId(`field-filter-${costDefinitionId}-from`)
			.fill('100000')

		await expect
			.poll(() => page.url(), {
				message: 'the filter never reached the address bar',
			})
			.toContain('_related')

		await page.getByRole('button', { name: 'All cases' }).click()

		await expect
			.poll(() => page.url(), {
				message:
					'the field filter survived the case type it belongs to, so the list is narrowed by a field these cases do not have',
			})
			.not.toContain('_related')
		await expect(page.getByTestId('case-type-field-filters')).toHaveCount(0)
	})

	// @e2e openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md#scenario-a-refused-filter-is-not-an-empty-result
	test('a refused block says so instead of showing an empty list', async ({
		page,
	}) => {
		// A block naming a definition that is not a uuid is malformed, and
		// openregister refuses it rather than running it as a literal.
		await page.goto(
			`${APP_URL}?caseType=${caseTypeId}&_related[caseProperty][case][propertyDefinition]=not-a-definition&_related[caseProperty][case][value][gte]=x`,
			PAGE_LOAD,
		)
		await dismissSupportDialog(page)

		await expect(
			page.getByTestId('case-type-field-filters-refusal'),
		).toBeVisible()
	})
})
