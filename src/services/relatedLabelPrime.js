/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list's Case type and Status labels load beside the list, not after it.
 *
 * The labels are looked up by the formatters the first time a cell asks, and a
 * cell asks only once the list's rows have arrived: a second request wave on
 * every visit to Cases. Starting the lookups when the Cases route is entered
 * (and once the stores are up, for a first load that lands on Cases) sends
 * them beside the list request.
 *
 * @module services/relatedLabelPrime
 * @spec openspec/specs/case-management/spec.md
 */

import { primeRelatedLabels } from './formatters.js'

/** The routes whose list shows the related labels. */
export const LABEL_LIST_ROUTES = ['Cases']

/**
 * Whether a route is a list that shows the related labels.
 *
 * @spec openspec/specs/case-management/spec.md
 * @param {object|null|undefined} route A vue-router route.
 * @return {boolean} True for the case list.
 */
export function isLabelListRoute(route) {
	return Boolean(route && LABEL_LIST_ROUTES.includes(route.name))
}

/**
 * Start the label lookups when the route is the case list.
 *
 * @spec openspec/specs/case-management/spec.md
 * @param {object|null|undefined} route A vue-router route.
 * @param {function(): Array<string>} [prime] The lookup starter, for tests.
 * @return {Array<string>} The types whose lookup started.
 */
export function primeForRoute(route, prime = primeRelatedLabels) {
	return isLabelListRoute(route) ? prime() : []
}

/**
 * Start the label lookups on every navigation to the case list.
 *
 * @spec openspec/specs/case-management/spec.md
 * @param {object} router The app's router.
 * @param {function(): Array<string>} [prime] The lookup starter, for tests.
 * @return {void}
 */
export function installRelatedLabelPrime(router, prime = primeRelatedLabels) {
	router.afterEach((to) => {
		primeForRoute(to, prime)
	})
}
