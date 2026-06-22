<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="kpi-widget">
		<KpiRangePills :value="range" @input="onRange" />
		<CnStatsBlock
			:title="t('procest', 'Open Cases')"
			:count="count"
			:count-label="t('procest', 'cases')"
			:icon="FolderOpen"
			variant="primary"
			horizontal
			show-zero-count
			:loading="loading"
			:route="{ path: '/cases' }" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import { getCases, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { isInRange } from '../../utils/dateRange.js'
import KpiRangePills from '../../components/KpiRangePills.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OpenCasesKpiWidget',
	components: {
		CnStatsBlock,
		KpiRangePills,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			FolderOpen,
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
		 * Count open cases created within the selected range.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, statusTypes] = await Promise.all([getCases(), getStatusTypes()])
				this.count = splitCases(cases, statusTypes).openCases
					.filter(c => isInRange(c.startDate, this.range)).length
			} catch (err) {
				console.error('OpenCasesKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
