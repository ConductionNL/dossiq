<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'No task reminders')">
				<template #icon>
					<ClipboardCheckOutline />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getTaskDueReminders } from '../../utils/dashboardHelpers.js'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'

export default {
	name: 'TaskRemindersWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		ClipboardCheckOutline,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			reminders: { overdue: [], dueSoon: [] },
			itemMenu: {
				show: {
					text: t('procest', 'View task'),
					icon: 'icon-confirm',
				},
			},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		items() {
			const overdueItems = this.reminders.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days overdue', { days: item.daysOverdue }),
				avatarUrl: '/apps-extra/procest/img/app-dark.svg',
			}))
			const dueSoonItems = this.reminders.dueSoon.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: item.daysRemaining === 0
					? t('procest', 'Due today')
					: t('procest', '{days} days remaining', { days: item.daysRemaining }),
				avatarUrl: '/apps-extra/procest/img/app-dark.svg',
			}))
			return [...overdueItems, ...dueSoonItems].slice(0, 5)
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle showing a task.
		 *
		 * @param {object} item The task item to show
		 * @return {void}
		 */
		onShow(item) {
			window.location.href = `/index.php/apps/procest/#/tasks/${item.id}`
		},
		/**
		 * Fetch task data and compute due reminders.
		 *
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const currentUser = OC?.currentUser || ''
				const tasks = await this.objectStore.fetchCollection('task', {
					'_filters[assignee]': currentUser,
					_limit: 100,
				})
				const activeTasks = (tasks || []).filter(t =>
					t.status === 'available' || t.status === 'active',
				)
				this.reminders = getTaskDueReminders(activeTasks)
			} catch (err) {
				console.error('[TaskRemindersWidget] Failed to fetch data:', err)
				this.reminders = { overdue: [], dueSoon: [] }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
