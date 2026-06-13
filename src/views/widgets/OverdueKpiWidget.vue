<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
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
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { getCases, getCaseTypes, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { getOverdueCases } from '../../utils/dashboardHelpers.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OverdueKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			AlertCircle,
			count: 0,
			loading: true,
		}
	},
	methods: {
		/**
		 * Count open cases past their processing deadline.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, caseTypes, statusTypes] = await Promise.all([
					getCases(), getCaseTypes(), getStatusTypes(),
				])
				const { openCases } = splitCases(cases, statusTypes)
				this.count = getOverdueCases(openCases, caseTypes).length
			} catch (err) {
				console.error('OverdueKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
