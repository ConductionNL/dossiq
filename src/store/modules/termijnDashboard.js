/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared state for the deadline-monitoring (termijnbewaking) dashboard.
 *
 * The page is a manifest `type: "dashboard"` composed of three widgets, and the
 * KPI figures plus the case-type filter options are needed by more than one of
 * them. One store means one `/api/termijn/dashboard/kpi` call for the whole
 * page instead of one per widget.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * The quarter the current date falls in, as `YYYY-Qn`.
 *
 * @return {string} e.g. `2026-Q3`.
 */
export function currentQuarter() {
	const d = new Date()
	return `${d.getFullYear()}-Q${Math.floor(d.getMonth() / 3) + 1}`
}

export const useTermijnDashboardStore = defineStore('termijnDashboard', {
	state: () => ({
		kpi: null,
		quarterly: null,
		annual: null,
		/** Case types offered by the header filter. */
		zaaktypeOptions: [],
		loading: false,
		error: null,
		loadedCaseType: undefined,
	}),

	actions: {
		/**
		 * Load the headline KPI block, optionally scoped to one case type.
		 *
		 * @param {object}      opts            Query.
		 * @param {string|null} [opts.caseType] Case type to scope on.
		 * @param {boolean}     [opts.force]    Refetch even if already loaded.
		 * @return {Promise<void>}
		 */
		async loadKpi({ caseType = null, force = false } = {}) {
			if (!force && this.loadedCaseType === caseType && this.kpi) return
			this.loading = true
			this.error = null
			this.loadedCaseType = caseType
			try {
				const params = {}
				if (caseType) params.case_type = caseType
				const res = await axios.get(generateUrl('/apps/procest/api/termijn/dashboard/kpi'), { params })
				this.kpi = res.data || null
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load KPI')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load one quarter's report.
		 *
		 * Also backfills `zaaktypeOptions`. The dashboard this replaced populated
		 * the case-type filter ONLY here, and never called this on mount — so the
		 * filter rendered empty until a quarterly report happened to be loaded.
		 * `ensureZaaktypeOptions()` closes that; this stays as the richer source.
		 *
		 * @param {string} periode Quarter as `YYYY-Qn`.
		 * @return {Promise<void>}
		 */
		async loadQuarterly(periode) {
			if (!periode) return
			this.error = null
			try {
				const res = await axios.get(generateUrl('/apps/procest/api/termijn/reports/kwartaal'),
					{ params: { periode } })
				this.quarterly = res.data
				if (this.quarterly?.perType) {
					this.zaaktypeOptions = Object.keys(this.quarterly.perType).map((k) => ({ id: k, label: k }))
				}
			} catch (e) {
				this.error = e?.response?.data?.message || e.message
					|| t('procest', 'Failed to load quarterly report')
			}
		},

		/**
		 * Load one year's dwangsom audit.
		 *
		 * @param {number} jaar Calendar year.
		 * @return {Promise<void>}
		 */
		async loadAnnual(jaar) {
			this.error = null
			try {
				const res = await axios.get(generateUrl('/apps/procest/api/termijn/reports/jaarrekening'),
					{ params: { jaar } })
				this.annual = res.data
			} catch (e) {
				this.error = e?.response?.data?.message || e.message
					|| t('procest', 'Failed to load annual audit')
			}
		},

		/**
		 * Make sure the case-type filter has options without requiring the user
		 * to load a quarterly report first. Falls back silently: an empty filter
		 * behaves the same as choosing no filter.
		 *
		 * @return {Promise<void>}
		 */
		async ensureZaaktypeOptions() {
			if (this.zaaktypeOptions.length) return
			await this.loadQuarterly(currentQuarter())
		},
	},
})
