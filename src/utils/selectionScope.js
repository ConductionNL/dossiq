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

import { translate as t } from '@nextcloud/l10n'

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
 * 🔴 THIS LIST DENIES BY NAME, NOT BY THE `_` PREFIX, and that is the whole
 * reason it is a list. It is also how a bug got in: while applying a saved
 * view wrote its sort as `_sortKey`/`_sortOrder`, neither name was here, so
 * "Select all 400 cases matching this search" handed `searchObjects()` two
 * keys matching no column as FILTERS, on the query that decides what four
 * hundred cases get reassigned. It closed when that spelling was retired in
 * favour of `_order`, which was already denied — not by an entry added here.
 * Do not add the retired names back: they would suggest the prefix is handled,
 * and it is not.
 *
 * 🔴 NEITHER IS `_element[…]` (or `_related[…]`), FOR THE SAME REASON. A filter
 * on a case type's own field narrows the result set exactly as a search term
 * does: it is openregister's array-element query over the answers in each
 * case's `properties[]`, and it resolves inside the same `searchObjects()` call. Dropping
 * it would hand the job every case in the register while the button said
 * "Select all 43 cases matching this filter". This list is a DENY list, so an
 * `_element` key already survives; the point of saying so here is that it must
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
 * What CnBulkJobProgress says about a bulk act over cases, in dossiq's words.
 *
 * The library says "items"; a handler reads "cases", and these sentences keep
 * the Dutch dossiq already had. Each is a template the component fills.
 *
 * @return {object} The labels.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function caseJobLabels() {
	return {
		statePreviewed: t('dossiq', 'Nothing has been written yet. This is what would happen to {total} cases.'),
		stateRunning: t('dossiq', 'Running. {processed} of {total} cases done.'),
		stateCancelling: t('dossiq', 'Stopping after the case it is on.'),
		stateCancelled: t('dossiq', 'Stopped. {processed} of {total} cases were done first.'),
		stateFailed: t('dossiq', 'The act stopped on an error after {processed} of {total} cases.'),
		stateCompleted: t('dossiq', 'Finished. {processed} of {total} cases done.'),
		empty: t('dossiq', 'No cases with this outcome.'),
		commit: t('dossiq', 'Apply to {total} cases'),
		retry: t('dossiq', 'Run the cases it did not reach'),
		rowsFailed: t('dossiq', 'The cases with this outcome could not be read.'),
		retryFailed: t('dossiq', 'The rest of the cases could not be run.'),
	}
}

/**
 * What CnSelectionScope says about a selection of cases, in dossiq's words.
 *
 * @return {object} The labels.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function caseScopeLabels() {
	return {
		page: t('dossiq', '{count} cases on this page are selected.'),
		result: t('dossiq', 'All {total} cases matching this search are selected.'),
		widen: t('dossiq', 'Select all {total} cases matching this search'),
		narrow: t('dossiq', 'Go back to the {count} on this page'),
	}
}
