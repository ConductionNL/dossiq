// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared, lightly-cached data fetcher for the manifest-driven Dashboard.
//
// The dashboard widgets (4 KPI cards + 2 charts + 6 list panels) each
// render independently from the manifest. To avoid duplicate REST calls
// per page load (every KPI card needs cases + statusTypes, etc.), this
// module memoises one in-flight promise per dataset for a short TTL.
//
// Call `refreshDashboardData()` to drop the cache and bump the reactive
// refresh signal (used by the "Refresh" header action); widgets watch
// the signal via dashboardRefreshMixin and re-run their load().

import Vue from 'vue'
import { useObjectStore } from '../store/modules/object.js'
import { initializeStores } from '../store/store.js'

const CACHE_TTL_MS = 5 * 60 * 1000
const cache = new Map()

// Reactive refresh signal. Dashboard widgets watch `refreshSignal.token`
// (via dashboardRefreshMixin) and refetch when it bumps, so they refresh
// in place without relying on a remount.
const refreshSignal = Vue.observable({ token: 0 })

/**
 * The reactive refresh signal. Read `.token` inside a computed to make a
 * component re-evaluate when the dashboard is refreshed.
 *
 * @return {{ token: number }} The observable signal.
 */
export function getDashboardRefreshSignal() {
	return refreshSignal
}

/**
 * Get a cached dataset, fetching once and reusing the result across
 * widgets that mount during the same render pass. Entries expire after
 * CACHE_TTL_MS to keep the dashboard reasonably fresh without an
 * explicit refresh.
 *
 * @param {string} key Unique cache key (e.g. 'case', 'task:mine').
 * @param {Function} fetcher Promise-returning fetcher invoked on miss.
 * @return {Promise<Array>} The cached or freshly fetched data.
 */
function cached(key, fetcher) {
	const entry = cache.get(key)
	const now = Date.now()
	if (entry && (now - entry.timestamp) < CACHE_TTL_MS) {
		return entry.promise
	}
	const promise = fetcher().catch(err => {
		// On error, drop the cache so the next mount retries.
		cache.delete(key)
		throw err
	})
	cache.set(key, { promise, timestamp: now })
	return promise
}

/**
 * Fetch a collection through the shared object store, making sure the
 * object-type registry is initialised first (widgets can mount before
 * App.vue's async created() resolves).
 *
 * @param {string} type Object type slug (case, caseType, …).
 * @param {object} params Query parameters.
 * @return {Promise<Array>} Array of object records.
 */
async function fetchCollection(type, params = {}) {
	await initializeStores()
	const objectStore = useObjectStore()
	const results = await objectStore.fetchCollection(type, params)
	return results || []
}

/** @return {Promise<Array>} All cases (open and closed, capped). */
export function getCases() {
	return cached('case', () => fetchCollection('case', { _limit: 1000 }))
}

/** @return {Promise<Array>} All case types. */
export function getCaseTypes() {
	return cached('caseType', () => fetchCollection('caseType', { _limit: 100 }))
}

/** @return {Promise<Array>} All status types. */
export function getStatusTypes() {
	return cached('statusType', () => fetchCollection('statusType', { _limit: 500 }))
}

/** @return {Promise<Array>} Active/available tasks assigned to the current user. */
export function getMyTasks() {
	const uid = window.OC?.currentUser || ''
	if (!uid) return Promise.resolve([])
	return cached('task:mine', async () => {
		const tasks = await fetchCollection('task', {
			'_filters[assignee]': uid,
			_limit: 200,
		})
		return tasks.filter(t => t.status === 'available' || t.status === 'active')
	})
}

/**
 * Split a raw case list into open cases and cases completed this month,
 * using statusType.isFinal — the same open/closed semantics the list
 * widgets use (unknown status counts as open).
 *
 * @param {object[]} cases Raw case records.
 * @param {object[]} statusTypes All status types.
 * @return {{ openCases: object[], completedThisMonth: object[] }} Split sets.
 */
export function splitCases(cases, statusTypes) {
	const finalIds = new Set(
		(statusTypes || []).filter(st => st.isFinal).map(st => st.id),
	)
	const month = new Date().toISOString().slice(0, 7)
	const openCases = []
	const completedThisMonth = []
	for (const c of (cases || [])) {
		if (finalIds.has(c.status)) {
			if (c.endDate && c.endDate.slice(0, 7) === month) {
				completedThisMonth.push(c)
			}
		} else {
			openCases.push(c)
		}
	}
	return { openCases, completedThisMonth }
}

/**
 * Drop all cached datasets and bump the refresh signal so every mounted
 * dashboard widget re-runs its load().
 */
export function refreshDashboardData() {
	cache.clear()
	refreshSignal.token++
}
