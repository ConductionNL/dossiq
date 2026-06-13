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
		@retry="load" />
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
	},
}
</script>
