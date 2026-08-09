<template>
	<CnDataTable :rows="items"
		:columns="columns"
		:loading="loading"
		hide-header
		borderless
		:empty-text="t('procest', 'No task reminders')"
		@row-click="onRowClick">
		<template #footer>
			<a class="cn-data-table__view-all"
				:href="viewAllUrl"
				@click.prevent="onViewAll">
				{{ t('procest', 'View all') }} →
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { getTaskDueReminders } from '../../utils/dashboardHelpers.js'
import { SIGNAL_COLUMNS, navigateTo } from './signalTable.js'

export default {
	name: 'TaskRemindersWidget',
	components: {
		CnDataTable,
	},
	props: {
		title: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: false,
			reminders: { overdue: [], dueSoon: [] },
			columns: SIGNAL_COLUMNS,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * Real destination URL for the "View all" link (gate-32: an `<a>`
		 * with a real `href` is a genuine link, not a mouse-only click
		 * target).
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		viewAllUrl() {
			return generateUrl('/apps/procest/tasks')
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		items() {
			const overdueItems = this.reminders.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days overdue', { days: item.daysOverdue }),
				targetUrl: generateUrl(`/apps/procest/tasks/${item.id}`),
			}))
			const dueSoonItems = this.reminders.dueSoon.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: item.daysRemaining === 0
					? t('procest', 'Due today')
					: t('procest', '{days} days remaining', { days: item.daysRemaining }),
				targetUrl: generateUrl(`/apps/procest/tasks/${item.id}`),
			}))
			return [...overdueItems, ...dueSoonItems].slice(0, 5)
		},
	},
	async mounted() {
		// Ensure object types are registered before fetching. App.vue's
		// async created() does not block child mounting, so this widget can
		// mount before initializeStores() has resolved; the same applies
		// when the widget runs standalone on the Nextcloud Dashboard.
		await initializeStores()
		this.fetchData()
	},
	methods: {
		/**
		 * Navigate to a clicked task in the same tab.
		 *
		 * @param {object} row The clicked row (a shaped task item).
		 * @return {void}
		 */
		onRowClick(row) {
			navigateTo(row.targetUrl)
		},
		/**
		 * Navigate to the full tasks list.
		 *
		 * @return {void}
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/procest/tasks'))
		},
		/**
		 * Fetch task data and compute due reminders.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		async fetchData() {
			this.loading = true
			try {
				const currentUser = getCurrentUser()?.uid || ''
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
