/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared state for the processing-time (doorlooptijd) dashboard.
 *
 * The page is a manifest `type: "dashboard"` composed of five widgets. All five
 * derive from the SAME three collections (cases, case types, status types), so
 * the fetch and every derivation live here once instead of in a parent component
 * that hands props down — which is what forced the whole dashboard to be a
 * single full-grid widget in the first place.
 *
 * The derivations are thin: the arithmetic already lives in the pure helpers in
 * `utils/doorlooptijdHelpers.js` and `utils/dashboardHelpers.js`, and these
 * getters only feed them.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import { defineStore } from 'pinia'
import {
	computeWeeklyThroughput,
	getWooCases,
} from '../../utils/dashboardHelpers.js'
import {
	computeMonthlyTrend,
	computePerformanceTable,
	computeProcessingTimeDistribution,
	computeSlaCompliance,
	getAtRiskCases,
	parseDurationToDays,
} from '../../utils/doorlooptijdHelpers.js'
import { initializeStores } from '../store.js'
import { useObjectStore } from './object.js'

/**
 * Resolve a period preset into a `{ from, to }` date pair.
 *
 * @param {string} preset One of `3m`, `6m`, `12m`, `year`, `all`.
 * @return {{from: Date|null, to: Date}} The resolved range; `from` null = no bound.
 */
export function resolveRange(preset) {
	const now = new Date()
	let from
	switch (preset) {
		case '3m':
			from = new Date(now.getFullYear(), now.getMonth() - 3, 1)
			break
		case '6m':
			from = new Date(now.getFullYear(), now.getMonth() - 6, 1)
			break
		case 'year':
			from = new Date(now.getFullYear(), 0, 1)
			break
		case 'all':
			from = null
			break
		default:
			from = new Date(now.getFullYear(), now.getMonth() - 12, 1)
	}
	return { from, to: now }
}

/**
 * How many months of trend to plot for a period preset.
 *
 * @param {string} preset The period preset key.
 * @return {number} Month count.
 */
export function trendMonthsFor(preset) {
	switch (preset) {
		case '3m':
			return 3
		case '6m':
			return 6
		case 'year':
			return new Date().getMonth() + 1
		case 'all':
			return 24
		default:
			return 12
	}
}

export const useDoorlooptijdStore = defineStore('doorlooptijd', {
	state: () => ({
		allCases: [],
		caseTypes: [],
		statusTypes: [],
		loading: true,
		loaded: false,
		error: null,
		inflight: null,
	}),

	getters: {
		statusTypeMap: (s) => {
			const m = new Map()
			for (const st of s.statusTypes) m.set(st.id, st)
			return m
		},
		completedCases() {
			return this.allCases.filter(
				(c) => this.statusTypeMap.get(c.status)?.isFinal && c.endDate,
			)
		},
		openCases() {
			return this.allCases.filter(
				(c) => !this.statusTypeMap.get(c.status)?.isFinal,
			)
		},
		wooCases() {
			return getWooCases(this.openCases, this.caseTypes)
		},
		caseTypesWithSla: (s) =>
			s.caseTypes.filter(
				(ct) =>
					ct.processingDeadline
					&& parseDurationToDays(ct.processingDeadline),
			),
	},

	actions: {
		/**
		 * Fetch the three collections the whole dashboard derives from.
		 *
		 * Concurrent widgets share one request. `caseType` and `statusType` are
		 * only registered on the object store once initializeStores() has
		 * resolved the app config, and a widget can mount before App.vue's boot
		 * call finishes — the dashboard this replaced omitted that await, so its
		 * fetches raced the config and were swallowed by Promise.allSettled,
		 * silently yielding empty collections. initializeStores() is idempotent.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (this.loaded) return
			if (this.inflight) return this.inflight
			this.loading = true
			this.inflight = (async () => {
				try {
					await initializeStores()
					const objectStore = useObjectStore()
					const r = await Promise.allSettled([
						objectStore.fetchCollection('case', { _limit: 5000 }),
						objectStore.fetchCollection('caseType', { _limit: 100 }),
						objectStore.fetchCollection('statusType', { _limit: 500 }),
					])
					this.allCases =
						r[0].status === 'fulfilled' ? r[0].value || [] : []
					this.caseTypes =
						r[1].status === 'fulfilled' ? r[1].value || [] : []
					this.statusTypes =
						r[2].status === 'fulfilled' ? r[2].value || [] : []
					this.loaded = true
				} catch (err) {
					// Surfaced through state rather than the console: the widgets
					// render an empty state from it, and a console-only failure is
					// invisible to the user staring at a blank dashboard.
					this.error =
						err?.message || 'Could not load processing-time data.'
				} finally {
					this.loading = false
					this.inflight = null
				}
			})()
			return this.inflight
		},

		/**
		 * Completed cases inside a period, optionally scoped to one case type.
		 *
		 * A method rather than a getter because it takes the page's live filter
		 * values, which live in the dashboard's workspace context, not in here.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {Array<object>} Matching cases.
		 */
		filteredCompleted(preset, caseType = null) {
			let cases = this.completedCases
			const { from } = resolveRange(preset)
			if (from) {
				const fromStr = from.toISOString().slice(0, 10)
				cases = cases.filter(
					(c) => c.endDate && c.endDate.slice(0, 10) >= fromStr,
				)
			}
			if (caseType) cases = cases.filter((c) => c.caseType === caseType)
			return cases
		},

		/**
		 * Open cases, optionally scoped to one case type.
		 *
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {Array<object>} Matching cases.
		 */
		filteredOpen(caseType = null) {
			return caseType
				? this.openCases.filter((c) => c.caseType === caseType)
				: this.openCases
		},

		/**
		 * SLA compliance figures for a period + case type.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {object} Compliance block.
		 */
		slaData(preset, caseType) {
			return computeSlaCompliance(
				this.filteredCompleted(preset, caseType),
				this.caseTypes,
			)
		},

		/**
		 * Processing-time distribution for a period + case type.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {object} Distribution block.
		 */
		distributionData(preset, caseType) {
			return computeProcessingTimeDistribution(
				this.filteredCompleted(preset, caseType),
				this.caseTypes,
			)
		},

		/**
		 * Monthly trend. Deliberately NOT date-filtered: the trend defines its
		 * own window from the preset, so filtering first would truncate it.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {object} Trend block.
		 */
		trendData(preset, caseType) {
			const cases = caseType
				? this.completedCases.filter((c) => c.caseType === caseType)
				: this.completedCases
			return computeMonthlyTrend(cases, this.caseTypes, trendMonthsFor(preset))
		},

		/**
		 * Weekly throughput over the trailing 12 weeks of the range.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {object} Throughput block.
		 */
		throughputData(preset, caseType) {
			return computeWeeklyThroughput(
				this.filteredCompleted(preset, caseType),
				12,
			)
		},

		/**
		 * Open cases within 25% of their deadline.
		 *
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {Array<object>} At-risk cases.
		 */
		atRiskCases(caseType) {
			return getAtRiskCases(this.filteredOpen(caseType), this.caseTypes, 0.25)
		},

		/**
		 * Per-case-type performance table.
		 *
		 * @param {string}      preset     Period preset key.
		 * @param {string|null} [caseType] Case type to scope on.
		 * @return {Array<object>} Table rows.
		 */
		performanceData(preset, caseType) {
			return computePerformanceTable(
				this.filteredCompleted(preset, caseType),
				this.caseTypes,
			)
		},
	},
})
