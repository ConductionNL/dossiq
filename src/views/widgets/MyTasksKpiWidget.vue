<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
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
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { getMyTasks } from '../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'MyTasksKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			ClipboardCheckOutline,
			count: 0,
			dueToday: 0,
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
		 * Count active/available tasks assigned to the current user.
		 */
		async load() {
			this.loading = true
			try {
				const tasks = await getMyTasks()
				this.count = tasks.length
				const today = new Date().toISOString().slice(0, 10)
				this.dueToday = tasks.filter(
					t => t.dueDate && t.dueDate.slice(0, 10) === today,
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
