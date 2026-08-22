/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared wiring for the processing-time dashboard widgets.
 *
 * Each widget is a thin wrapper: it reads the page's filters out of the reactive
 * `cnWorkspaceContext` CnDashboardPage provides, asks the shared store for the
 * derived block it needs, and hands that to the existing presentational
 * component unchanged. The sub-components (DeadlineKpiRow, ComplianceCharts,
 * DeadlineCaseTable, CaseTypeBreakdown, WooDeadlinePanel) are untouched by this
 * change — they were already well factored; only their host was wrong.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import { useDoorlooptijdStore } from '../../../store/modules/doorlooptijd.js'

export const dtWidgetMixin = {
	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	computed: {
		/** @return {object} The shared processing-time store. */
		dtStore() {
			return useDoorlooptijdStore()
		},
		/** @return {string} Period preset from the page header. */
		dtPreset() {
			return this.workspace?.period || '12m'
		},
		/** @return {string|null} Case type from the page header. */
		dtCaseType() {
			return this.workspace?.caseType || null
		},
		/** @return {boolean} Whether the shared collections are still loading. */
		dtLoading() {
			return this.dtStore.loading
		},
	},

	mounted() {
		this.dtStore.load()
	},

	methods: {
		/**
		 * Route to a case's detail page.
		 *
		 * @param {string} id Case id.
		 * @return {void}
		 */
		openCase(id) {
			this.$router.push({ name: 'CaseDetail', params: { id } })
		},
	},
}
