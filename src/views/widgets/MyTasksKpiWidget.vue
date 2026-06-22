<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="kpi-widget">
		<KpiRangePills :value="range" @input="onRange" />
		<CnStatsBlock
			:title="t('procest', 'My Tasks')"
			:count="count"
			:count-label="countLabel"
			:icon="ClipboardCheckOutline"
			variant="primary"
			horizontal
			show-zero-count
			:loading="loading"
			:route="{ path: '/my-work' }" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { getMyTasks } from '../../services/dashboardData.js'
import { isInRange } from '../../utils/dateRange.js'
import KpiRangePills from '../../components/KpiRangePills.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'MyTasksKpiWidget',
	components: {
		CnStatsBlock,
		KpiRangePills,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			ClipboardCheckOutline,
			count: 0,
			dueToday: 0,
			range: 'all',
			loading: true,
		}
	},
	computed: {
		countLabel() {
			return this.dueToday > 0
				? t('procest', 'tasks · {n} due today', { n: this.dueToday })
				: t('procest', 'tasks')
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
		 * Count active/available tasks due within the selected range.
		 */
		async load() {
			this.loading = true
			try {
				const tasks = await getMyTasks()
				const inRange = tasks.filter(task => isInRange(task.dueDate, this.range))
				this.count = inRange.length
				const today = new Date().toISOString().slice(0, 10)
				this.dueToday = inRange.filter(
					task => task.dueDate && task.dueDate.slice(0, 10) === today,
				).length
			} catch (err) {
				console.error('MyTasksKpiWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
