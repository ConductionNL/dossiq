<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Self-fetching wrapper around the prop-driven CasesByType chart so the
	manifest Dashboard's widget grid can render it via widgetKey. Bar
	clicks navigate to /cases pre-filtered (handled inside CasesByType).
-->
<template>
	<CasesByType
		:type-data="typeData"
		:loading="loading"
		:error="error"
		@retry="load" />
</template>

<script>
import CasesByType from '../dashboard/CasesByType.vue'
import { getCases, getCaseTypes, getStatusTypes, splitCases } from '../../services/dashboardData.js'
import { aggregateByType } from '../../utils/dashboardHelpers.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'CasesByTypeWidget',
	components: {
		CasesByType,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			typeData: [],
			loading: true,
			error: null,
		}
	},
	methods: {
		/**
		 * Aggregate open cases by case type for the bar chart.
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const [cases, caseTypes, statusTypes] = await Promise.all([
					getCases(), getCaseTypes(), getStatusTypes(),
				])
				const { openCases } = splitCases(cases, statusTypes)
				this.typeData = aggregateByType(openCases, caseTypes)
			} catch (err) {
				console.error('CasesByTypeWidget fetch error:', err)
				this.error = t('procest', 'Could not load case data')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
