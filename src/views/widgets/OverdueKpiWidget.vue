<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="kpi-widget">
		<KpiRangePills :value="range" @input="onRange" />
		<CnStatsBlock
			:title="t('procest', 'Overdue')"
			:count="count"
			:count-label="t('procest', 'overdue')"
			:icon="AlertCircle"
			:variant="count > 0 ? 'error' : 'success'"
			horizontal
			show-zero-count
			:loading="loading"
			:route="{ path: '/cases' }" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { getCases, getCaseTypes, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { getOverdueCases } from '../../utils/dashboardHelpers.js'
import { isInRange } from '../../utils/dateRange.js'
import KpiRangePills from '../../components/KpiRangePills.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OverdueKpiWidget',
	components: {
		CnStatsBlock,
		KpiRangePills,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			AlertCircle,
			count: 0,
			range: 'all',
			loading: true,
		}
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
		 * Count open cases created within the range that are past their deadline.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, caseTypes, statusTypes] = await Promise.all([
					getCases(), getCaseTypes(), getStatusTypes(),
				])
				const { openCases } = splitCases(cases, statusTypes)
				const inRange = openCases.filter(c => isInRange(c.startDate, this.range))
				this.count = getOverdueCases(inRange, caseTypes).length
			} catch (err) {
				console.error('OverdueKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
