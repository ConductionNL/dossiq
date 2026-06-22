<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="kpi-widget">
		<KpiRangePills :value="range" @input="onRange" />
		<CnStatsBlock
			:title="t('procest', 'Completed')"
			:count="count"
			:count-label="countLabel"
			:icon="CheckCircle"
			variant="success"
			horizontal
			show-zero-count
			:loading="loading"
			:route="{ path: '/cases' }" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import { getCases, getStatusTypes } from '../../services/dashboardData.js'
import { computeKpis } from '../../utils/dashboardHelpers.js'
import { isInRange } from '../../utils/dateRange.js'
import KpiRangePills from '../../components/KpiRangePills.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'CompletedKpiWidget',
	components: {
		CnStatsBlock,
		KpiRangePills,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			CheckCircle,
			count: 0,
			avgDays: null,
			range: 'month',
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
		 * Switch the date range and recompute (data is cached, so no refetch).
		 *
		 * @param {string} range New range id.
		 */
		onRange(range) {
			this.range = range
			this.load()
		},
		/**
		 * Count cases closed within the selected range + average processing time.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, statusTypes] = await Promise.all([getCases(), getStatusTypes()])
				const finalIds = new Set(
					statusTypes.filter(st => st.isFinal).map(st => st.id),
				)
				const completed = cases.filter(
					c => finalIds.has(c.status) && isInRange(c.endDate, this.range),
				)
				const kpis = computeKpis([], completed, [])
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
