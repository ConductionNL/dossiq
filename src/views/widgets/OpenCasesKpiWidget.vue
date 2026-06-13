<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
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
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import { getCases, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OpenCasesKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			FolderOpen,
			count: 0,
			loading: true,
		}
	},
	methods: {
		/**
		 * Count cases whose status is not final.
		 */
		async load() {
			this.loading = true
			try {
				const [cases, statusTypes] = await Promise.all([getCases(), getStatusTypes()])
				this.count = splitCases(cases, statusTypes).openCases.length
			} catch (err) {
				console.error('OpenCasesKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
