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
 * The affordance itself is the library's CnSelectionScope (tested in its
 * tests/components/CnSelectionScope.spec.js); dossiq owns which rows a scope
 * hands the job and the case wording.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildSelection,
	caseScopeLabels,
	readListFilters,
	readLocationFilters,
	SCOPE_PAGE,
	SCOPE_RESULT,
} from '../../src/utils/selectionScope.js'

/**
 * Fill a label template the way CnSelectionScope does.
 *
 * @param {string} template The template.
 * @param {object} vars The values.
 * @return {string} The sentence.
 */
function fill(template, vars) {
	return template.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}

describe('the case wording of a selection', () => {
	const labels = caseScopeLabels()

	it('says twenty-five on this page, with the number in the sentence', () => {
		expect(fill(labels.page, { count: 25, total: 400 })).toBe('25 cases on this page are selected.')
	})

	it('says all four hundred matching the search when the scope is widened', () => {
		expect(fill(labels.result, { count: 25, total: 400 })).toBe('All 400 cases matching this search are selected.')
	})

	it('names the page count, not the total, while the scope is the page', () => {
		// The mutation this catches: a page sentence reading `total`. The
		// payload would still be right and the handler would still read 400.
		expect(labels.page).not.toContain('{total}')
	})

	it('offers the whole result and the way back in words', () => {
		expect(fill(labels.widen, { total: 400 })).toBe('Select all 400 cases matching this search')
		expect(fill(labels.narrow, { count: 25 })).toBe('Go back to the 25 on this page')
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
		expect(
			readLocationFilters('?caseType=bezwaar&_search=dakkapel&_page=3'),
		).toEqual({
			caseType: 'bezwaar',
			_search: 'dakkapel',
		})
	})

	it('hands the job the term along with the filters', () => {
		expect(
			buildSelection({
				scope: SCOPE_RESULT,
				selectedIds: ['1', '2'],
				filters: readLocationFilters(
					'?status=open&_search=dakkapel&_order=title',
				),
			}),
		).toEqual({ query: { status: 'open', _search: 'dakkapel' } })
	})
})
