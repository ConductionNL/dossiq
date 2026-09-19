/**
 * What a selection actually holds, said in words.
 *
 * A handler who believes they selected four hundred cases and selected
 * twenty-five is about to be surprised, and so is a handler who believes the
 * opposite. Neither surprise is recoverable once the act has run, so the scope
 * is stated before the act rather than reported after it (D-5).
 *
 * The two scopes are `page`, the rows a handler ticked, and `result`, every row
 * matching the search. Moving from one to the other is a separate, deliberate
 * act, because a checkbox that silently means "and the three hundred and
 * seventy-five you cannot see" is the failure this exists to prevent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

/** The rows the handler ticked. */
export const SCOPE_PAGE = 'page'

/** Every row matching the search. */
export const SCOPE_RESULT = 'result'

/**
 * The query keys a case list carries that are NOT filters.
 *
 * Paging and sorting describe the page, not the result set, so carrying them
 * into a whole-result selection would narrow it back down to the page it is
 * meant to escape.
 *
 * 🔴 `_search` IS NOT ONE OF THEM, AND USED TO BE. A search term describes the
 * result set as squarely as a case type does. Dropping it handed the job every
 * case in the register while the button said "Select all 400 cases matching
 * this search", which is the exact surprise this module was written to
 * prevent, arriving through the module itself.
 *
 * It resolves server-side: `BulkSelectionResolver::resolveQuery()` passes the
 * stored query straight into `ObjectService::searchObjects()`, the same call
 * the list itself makes.
 *
 * 🔴 NEITHER IS `_related[…]`, FOR THE SAME REASON. A filter on a case type's
 * own field narrows the result set exactly as a search term does: it is
 * openregister's related-row query over the `caseProperty` rows that hang off
 * each case, and it resolves inside the same `searchObjects()` call. Dropping
 * it would hand the job every case in the register while the button said
 * "Select all 43 cases matching this filter". This list is a DENY list, so a
 * `_related` key already survives; the point of saying so here is that it must
 * keep surviving, and the test that checks it is what makes adding it to this
 * list a change somebody has to defend.
 *
 * @type {Array<string>}
 */
const NOT_A_FILTER = [
	'_page',
	'_limit',
	'_offset',
	'_order',
	'_sort',
	'page',
	'limit',
	'offset',
	'view',
]

/**
 * The filters a case list is currently narrowed by.
 *
 * @param {object} [routeQuery] The current route query.
 *
 * @return {object} The filters, with paging and sorting dropped.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function readListFilters(routeQuery) {
	const filters = {}

	Object.entries(routeQuery || {}).forEach(([key, value]) => {
		if (
			NOT_A_FILTER.includes(key)
			|| value === undefined
			|| value === null
			|| value === ''
		) {
			return
		}
		filters[key] = value
	})

	return filters
}

/**
 * The filters the case list is narrowed by right now, read off the address bar.
 *
 * The list keeps its filters in the query string, which is how the CSV export
 * already finds them. A bulk handler is called with the ticked rows and
 * nothing else, so this is the only place the whole result set is reachable
 * from.
 *
 * @param {string} [search] A query string, defaulting to the current one.
 *
 * @return {object} The filters.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function readLocationFilters(search) {
	const raw = search === undefined ? globalThis.location?.search || '' : search
	const query = {}

	new URLSearchParams(raw).forEach((value, key) => {
		query[key] = value
	})

	return readListFilters(query)
}

/**
 * The selection to hand the job, for the scope the handler chose.
 *
 * @param {object} choice              What the handler picked.
 * @param {string} choice.scope        SCOPE_PAGE or SCOPE_RESULT.
 * @param {Array}  choice.selectedIds  The ticked rows.
 * @param {object} [choice.filters]    The list's current filters.
 *
 * @return {object} `{ids: [...]}` or `{query: {...}}`.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function buildSelection({ scope, selectedIds, filters }) {
	if (scope === SCOPE_RESULT) {
		return { query: filters || {} }
	}

	return { ids: (selectedIds || []).map(String) }
}

/**
 * Whether the whole result can honestly be offered.
 *
 * Offering "select all 400" without knowing that there are 400 is the exact
 * surprise this module exists to prevent, so an unknown total means the offer
 * is not made.
 *
 * @param {object} state           What is known.
 * @param {number} state.pageCount How many rows the handler ticked.
 * @param {number} [state.total]   How many rows match the search, when known.
 *
 * @return {boolean} True when the whole result is a real, countable thing.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function canOfferWholeResult({ pageCount, total }) {
	const matching = Number(total)

	return (
		Number.isFinite(matching) === true
		&& matching > 0
		&& matching > Number(pageCount || 0)
	)
}

/**
 * The sentence a handler reads about their own selection.
 *
 * It names the number and the scope in words, in that order, because the
 * number is what a handler checks and the scope is what they get wrong.
 *
 * @param {object}   state           What is known.
 * @param {string}   state.scope     SCOPE_PAGE or SCOPE_RESULT.
 * @param {number}   state.pageCount How many rows the handler ticked.
 * @param {number}   [state.total]   How many rows match the search.
 * @param {(app: string, text: string, vars?: object) => string} translate A `t`-shaped translator.
 *
 * @return {string} The sentence.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function describeScope({ scope, pageCount, total }, translate) {
	const t = translate || ((app, text) => text)

	if (scope === SCOPE_RESULT) {
		return t('dossiq', 'All {total} cases matching this search are selected.', {
			total: Number(total || 0),
		})
	}

	return t('dossiq', '{count} cases on this page are selected.', {
		count: Number(pageCount || 0),
	})
}

/**
 * The label on the button that widens the selection.
 *
 * @param {object}   state         What is known.
 * @param {number}   state.total   How many rows match the search.
 * @param {(app: string, text: string, vars?: object) => string} translate A `t`-shaped translator.
 *
 * @return {string} The label.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function widenLabel({ total }, translate) {
	const t = translate || ((app, text) => text)

	return t('dossiq', 'Select all {total} cases matching this search', {
		total: Number(total || 0),
	})
}
