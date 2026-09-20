// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The filters have to leave the browser, and the route query does not take
 * them.
 *
 * 🔴 THIS IS THE ASSERTION THE FEATURE SHIPPED WITHOUT. The bar compiled the
 * right blocks, wrote them onto the route, and stopped there. `CnIndexPage`
 * turns `$route.query` into fetch filters through `resolveQueryFilters()`,
 * which skips every key beginning with an underscore because that namespace
 * is the library's own, so not one `_related` key ever reached openregister.
 * The list answered the whole register under a URL that said it was filtered,
 * which is the exact failure the bar's refusal notice was written to prevent,
 * arriving through a quieter door: nothing is refused, so nothing is said.
 *
 * The second channel is `sidebarState.onFilterChange`, the one the facet
 * sidebar already uses, because `useListView.buildParams()` copies its keys
 * into the request verbatim. These assertions watch that call.
 *
 * @spec openspec/changes/archive/2026-09-20-case-type-fields-filter-the-case-list/specs/case-search-via-or-unified-search/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaseTypeFieldFilters from '../../src/components/search/CaseTypeFieldFilters.vue'

vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })

/** A case type's filterable field, in the shape the definitions store answers. */
const COST = {
	'@self': { uuid: 'pd-1' },
	name: 'bouwkosten',
	propertyType: 'number',
	filterable: true,
}

/** A second one, so two filled fields can be asserted as two numbered rows. */
const DISTRICT = {
	'@self': { uuid: 'pd-4' },
	name: 'stadsdeel',
	propertyType: 'string',
	enumValues: ['Noord', 'Zuid'],
	filterable: true,
}

let onFilterChange
let replace

/**
 * Mount the bar with a list that records what it is asked to filter on.
 *
 * @param {object} [query] The route query the page was opened with.
 * @return {object} The wrapper.
 */
function mountBar(query = {}) {
	return mount(CaseTypeFieldFilters, {
		global: {
			provide: { sidebarState: { onFilterChange } },
			mocks: {
				$route: { query },
				$router: { replace },
			},
			stubs: { NcNoteCard: true },
		},
	})
}

describe('the compiled blocks reach the list', () => {
	beforeEach(() => {
		onFilterChange = vi.fn()
		replace = vi.fn()
	})

	it('hands one filled field to the list, not only to the URL', async () => {
		const wrapper = mountBar()
		wrapper.vm.definitions = [COST]
		await wrapper.vm.$nextTick()

		wrapper.vm.setValue(COST, { gte: '100000' })

		expect(
			onFilterChange.mock.calls.map(([call]) => call),
			'the route query alone is dropped by resolveQueryFilters, so a bar that only pushes the URL filters nothing',
		).toContainEqual({
			key: '_related[caseProperty][case][propertyDefinition]',
			values: ['pd-1'],
		})
		expect(onFilterChange.mock.calls.map(([call]) => call)).toContainEqual({
			key: '_related[caseProperty][case][value][gte]',
			values: ['100000'],
		})
	})

	it('numbers two filled fields after the foreign key', async () => {
		const wrapper = mountBar()
		wrapper.vm.definitions = [COST, DISTRICT]
		await wrapper.vm.$nextTick()

		wrapper.vm.setValue(COST, { gte: '100000' })
		wrapper.vm.setValue(DISTRICT, { eq: 'Noord' })

		const keys = onFilterChange.mock.calls.map(([call]) => call.key)

		expect(
			keys,
			'openregister reads _related[caseProperty][0][case] as a foreign key called 0 and refuses the query',
		).toContain('_related[caseProperty][case][0][propertyDefinition]')
		expect(keys).toContain('_related[caseProperty][case][1][propertyDefinition]')
	})

	it('takes a filter off the list when the handler empties the field', async () => {
		const wrapper = mountBar()
		wrapper.vm.definitions = [COST]
		await wrapper.vm.$nextTick()

		wrapper.vm.setValue(COST, { gte: '100000' })
		onFilterChange.mockClear()
		wrapper.vm.setValue(COST, { gte: '' })

		expect(
			onFilterChange.mock.calls.map(([call]) => call),
			'a key left on the list would keep narrowing a list whose box is empty',
		).toContainEqual({
			key: '_related[caseProperty][case][propertyDefinition]',
			values: [],
		})
	})

	it('replays a shared link, which the page would otherwise fetch unfiltered', async () => {
		const wrapper = mountBar({
			caseType: 'ct-1',
			'_related[caseProperty][case][propertyDefinition]': 'pd-1',
			'_related[caseProperty][case][value][gte]': '100000',
		})
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(onFilterChange.mock.calls.map(([call]) => call)).toContainEqual({
			key: '_related[caseProperty][case][value][gte]',
			values: ['100000'],
		})
	})

	it('says nothing to a page that has not wired a list', async () => {
		const wrapper = mount(CaseTypeFieldFilters, {
			global: {
				provide: { sidebarState: null },
				mocks: { $route: { query: {} }, $router: { replace } },
				stubs: { NcNoteCard: true },
			},
		})
		wrapper.vm.definitions = [COST]
		await wrapper.vm.$nextTick()

		// No throw, and the route still records what was asked for, so the
		// intent is not lost in silence.
		expect(() => wrapper.vm.setValue(COST, { gte: '1' })).not.toThrow()
		expect(replace).toHaveBeenCalled()
	})
})
