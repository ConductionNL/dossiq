// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which "all" a handler actually has.
 *
 * A handler who ticks the header checkbox on a list of four hundred selects
 * twenty-five. Both ways of being wrong about that are unrecoverable once the
 * act has run, so the assertions here are about the SENTENCE and about the
 * whole-result act being SEPARATE, not about the selection object alone: a
 * correct payload behind a sentence that says the wrong number is exactly the
 * failure this requirement names.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// See bulkProgress.spec.js: jsdom environment setup dominates this file's wall
// clock, and under load it can spend the default 5s budget on its own.
vi.setConfig({ testTimeout: 30000, hookTimeout: 30000 })
import {
	buildSelection,
	canOfferWholeResult,
	describeScope,
	readListFilters,
	readLocationFilters,
	SCOPE_PAGE,
	SCOPE_RESULT,
} from '../../src/utils/selectionScope.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
}))

function t(app, text, vars) {
	return String(text).replace(/\{(\w+)\}/g, (match, key) =>
		vars && key in vars ? String(vars[key]) : match,
	)
}

describe('describeScope', () => {
	it('says twenty-five on this page, with the number in the sentence', () => {
		expect(
			describeScope({ scope: SCOPE_PAGE, pageCount: 25, total: 400 }, t),
		).toBe('25 cases on this page are selected.')
	})

	it('says all four hundred matching the search when the scope is widened', () => {
		expect(
			describeScope({ scope: SCOPE_RESULT, pageCount: 25, total: 400 }, t),
		).toBe('All 400 cases matching this search are selected.')
	})

	it('names the page count, not the total, while the scope is the page', () => {
		// The mutation this catches: reading `total` in both branches. The
		// payload would still be right and the handler would still read 400.
		expect(
			describeScope({ scope: SCOPE_PAGE, pageCount: 25, total: 400 }, t),
		).not.toContain('400')
	})
})

describe('canOfferWholeResult', () => {
	it('offers the whole result when there is more of it than the page', () => {
		expect(canOfferWholeResult({ pageCount: 25, total: 400 })).toBe(true)
	})

	it('withholds the offer when the total is unknown', () => {
		// Offering "select all 400" without knowing there are 400 is the same
		// surprise the affordance exists to prevent.
		expect(canOfferWholeResult({ pageCount: 25, total: 0 })).toBe(false)
		expect(canOfferWholeResult({ pageCount: 25 })).toBe(false)
		expect(canOfferWholeResult({ pageCount: 25, total: NaN })).toBe(false)
	})

	it('withholds the offer when the page already is the whole result', () => {
		expect(canOfferWholeResult({ pageCount: 25, total: 25 })).toBe(false)
	})
})

describe('buildSelection', () => {
	it('sends the ticked ids for a page scope', () => {
		expect(
			buildSelection({
				scope: SCOPE_PAGE,
				selectedIds: ['a', 'b'],
				filters: { status: 'open' },
			}),
		).toEqual({ ids: ['a', 'b'] })
	})

	it('sends the search itself for a whole-result scope, not the ids', () => {
		expect(
			buildSelection({
				scope: SCOPE_RESULT,
				selectedIds: ['a', 'b'],
				filters: { status: 'open' },
			}),
		).toEqual({ query: { status: 'open' } })
	})
})

describe('readListFilters', () => {
	it('drops paging and sorting, which describe the page and not the result', () => {
		expect(
			readListFilters({
				caseType: 'bezwaar',
				status: 'open',
				_page: '2',
				_limit: '25',
				_order: 'title',
			}),
		).toEqual({ caseType: 'bezwaar', status: 'open' })
	})

	it('drops empty values, which would narrow the result to nothing', () => {
		expect(readListFilters({ caseType: '', status: 'open' })).toEqual({
			status: 'open',
		})
	})

	it('reads the address bar when given a query string', () => {
		expect(readLocationFilters('?caseType=bezwaar&_page=3')).toEqual({
			caseType: 'bezwaar',
		})
	})

	// 🔴 A SEARCH TERM NARROWS THE RESULT, SO IT IS A FILTER.
	// It sat in NOT_A_FILTER beside the paging keys, so widening a selection
	// to the whole result dropped it and the job acted on every case in the
	// register while the button read "Select all 400 cases matching this
	// search". `BulkSelectionResolver::resolveQuery()` passes the stored query
	// into `ObjectService::searchObjects()`, so `_search` resolves there
	// exactly as it does on the list.
	// @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
	it('keeps the search term, which narrows the result', () => {
		expect(
			readListFilters({
				caseType: 'bezwaar',
				_search: 'dakkapel AND NOT geweigerd',
				_page: '2',
				_limit: '25',
			}),
		).toEqual({ caseType: 'bezwaar', _search: 'dakkapel AND NOT geweigerd' })
	})

	it('reads the search term off the address bar too', () => {
		expect(readLocationFilters('?caseType=bezwaar&_search=dakkapel&_page=3')).toEqual({
			caseType: 'bezwaar',
			_search: 'dakkapel',
		})
	})

	it('hands the job the term along with the filters', () => {
		expect(
			buildSelection({
				scope: SCOPE_RESULT,
				selectedIds: ['1', '2'],
				filters: readLocationFilters('?status=open&_search=dakkapel&_order=title'),
			}),
		).toEqual({ query: { status: 'open', _search: 'dakkapel' } })
	})
})

describe('BulkSelectionScope', () => {
	/**
	 * Mount the affordance.
	 *
	 * @param {object} props The props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountScope(props) {
		const { default: BulkSelectionScope } =
			await import('../../src/components/bulk/BulkSelectionScope.vue')

		return mount(BulkSelectionScope, { props })
	}

	it('states the number and the scope, and offers the whole result separately', async () => {
		const wrapper = await mountScope({
			selectedIds: ['a', 'b'],
			total: 400,
			scope: SCOPE_PAGE,
		})

		expect(wrapper.get('[data-testid="bulk-selection-sentence"]').text()).toBe(
			'2 cases on this page are selected.',
		)
		expect(wrapper.get('[data-testid="bulk-selection-widen"]').text()).toBe(
			'Select all 400 cases matching this search',
		)
	})

	it('widening is a second act that the parent has to accept', async () => {
		const wrapper = await mountScope({
			selectedIds: ['a', 'b'],
			total: 400,
			scope: SCOPE_PAGE,
		})

		await wrapper.get('[data-testid="bulk-selection-widen"]').trigger('click')

		// It EMITS rather than switching itself. A component that changed its
		// own scope would widen the selection without the parent, and therefore
		// without the act, knowing.
		expect(wrapper.emitted('update:scope')).toEqual([[SCOPE_RESULT]])
		expect(wrapper.get('[data-testid="bulk-selection-sentence"]').text()).toBe(
			'2 cases on this page are selected.',
		)
	})

	it('does not offer the whole result when the total is unknown', async () => {
		const wrapper = await mountScope({
			selectedIds: ['a', 'b'],
			total: 0,
			scope: SCOPE_PAGE,
		})

		expect(wrapper.find('[data-testid="bulk-selection-widen"]').exists()).toBe(
			false,
		)
	})

	it('offers the way back once the whole result is selected', async () => {
		const wrapper = await mountScope({
			selectedIds: ['a', 'b'],
			total: 400,
			scope: SCOPE_RESULT,
		})

		expect(wrapper.get('[data-testid="bulk-selection-sentence"]').text()).toBe(
			'All 400 cases matching this search are selected.',
		)

		await wrapper.get('[data-testid="bulk-selection-narrow"]').trigger('click')
		expect(wrapper.emitted('update:scope')).toEqual([[SCOPE_PAGE]])
	})
})
