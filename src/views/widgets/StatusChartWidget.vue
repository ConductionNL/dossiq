<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Self-fetching wrapper around the prop-driven StatusChart so the
	manifest Dashboard's widget grid can render it via widgetKey.
-->
<template>
	<StatusChart
		:status-data="statusData"
		:loading="loading"
		:error="error"
		@retry="load"
		@bar-click="onBarClick" />
</template>

<script>
import StatusChart from '../dashboard/StatusChart.vue'
import { getCases, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { aggregateByStatus } from '../../utils/dashboardHelpers.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'StatusChartWidget',
	components: {
		StatusChart,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			statusData: [],
			loading: true,
			error: null,
		}
	},
	methods: {
		/**
		 * Aggregate open cases by status for the bar chart.
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const [cases, statusTypes] = await Promise.all([getCases(), getStatusTypes()])
				const { openCases } = splitCases(cases, statusTypes)
				this.statusData = aggregateByStatus(openCases, statusTypes)
			} catch (err) {
				console.error('StatusChartWidget fetch error:', err)
				this.error = t('procest', 'Could not load case data')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate (same tab) to the cases index pre-filtered by the clicked
		 * status. A status bar can span several statusType ids (same-named
		 * statuses across case types are merged), so the deep-link uses an
		 * array `status` query → `status[]=a&status[]=b` IN match. Falls back
		 * to an unfiltered /cases when no ids resolved.
		 *
		 * @param {{ name: string, count: number, statusIds: string[] }} item The clicked status row.
		 * @return {void}
		 */
		onBarClick(item) {
			if (!this.$router || !item) return
			const ids = Array.isArray(item.statusIds) ? item.statusIds : []
			const query = ids.length ? { status: ids } : {}
			this.$router.push({ name: 'Cases', query }).catch(() => {})
		},
	},
}
</script>
