/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared wiring + formatting for the deadline-monitoring dashboard widgets.
 *
 * The page's case-type filter lands in the reactive `cnWorkspaceContext` that
 * CnDashboardPage provides, so a widget reads its scope from there rather than
 * owning a filter of its own.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import { useTermijnDashboardStore } from '../../store/modules/termijnDashboard.js'

export const tdWidgetMixin = {
	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	computed: {
		/**
		 * @return {object} The shared deadline-monitoring store.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		tdStore() {
			return useTermijnDashboardStore()
		},
		/**
		 * @return {string|null} Case type selected in the page header.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		tdCaseType() {
			return this.workspace?.caseType || null
		},
	},

	methods: {
		/**
		 * Format a number as a one-decimal percentage.
		 *
		 * @param {number|string} v Raw value.
		 * @return {string} e.g. `92.4 %`.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		percent(v) {
			return `${(Number(v) || 0).toFixed(1)} %`
		},
		/**
		 * Format a number as euros in Dutch locale.
		 *
		 * @param {number|string} v Raw value.
		 * @return {string} e.g. `€ 1.234,00`.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		euro(v) {
			return (Number(v) || 0).toLocaleString('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			})
		},
	},
}
