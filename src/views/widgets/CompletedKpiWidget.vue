<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<CnStatsBlock
		:title="t('procest', 'Completed This Month')"
		:count="count"
		:count-label="countLabel"
		:icon="CheckCircle"
		variant="success"
		horizontal
		show-zero-count
		:loading="loading"
		:route="{ path: '/cases' }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import { getCases, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { computeKpis } from '../../utils/dashboardHelpers.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'CompletedKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			CheckCircle,
			count: 0,
			avgDays: null,
			loading: true,
		}
	},
	computed: {
		countLabel() {
			return this.avgDays !== null
				? t('procest', 'cases · avg {days} days', { days: this.avgDays })
				: t('procest', 'cases')
		},
	},
	methods: {
		/**
		 * Count cases completed this month + average processing time.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, statusTypes] = await Promise.all([getCases(), getStatusTypes()])
				const { openCases, completedThisMonth } = splitCases(cases, statusTypes)
				const kpis = computeKpis(openCases, completedThisMonth, [])
				this.count = kpis.completedCount
				this.avgDays = kpis.avgDays
			} catch (err) {
				console.error('CompletedKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
