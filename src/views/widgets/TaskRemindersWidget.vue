<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow"
		@click.native.capture="onRowNav">
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
import { imagePath } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
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
			default: '',
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
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		items() {
			const overdueItems = this.reminders.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days overdue', { days: item.daysOverdue }),
				avatarUrl: imagePath('procest', 'app-dark.svg'),
				targetUrl: `/index.php/apps/procest/#/tasks/${item.id}`,
			}))
			const dueSoonItems = this.reminders.dueSoon.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: item.daysRemaining === 0
					? t('procest', 'Due today')
					: t('procest', '{days} days remaining', { days: item.daysRemaining }),
				avatarUrl: imagePath('procest', 'app-dark.svg'),
				targetUrl: `/index.php/apps/procest/#/tasks/${item.id}`,
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
		 * Handle showing a task.
		 *
		 * @param {object} item The task item to show
		 * @return {void}
		 */
		/**
		 * @param item
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		onShow(item) {
			window.location.href = `/index.php/apps/procest/#/tasks/${item.id}`
		},
		/**
		 * Intercept a plain row click so it navigates in the SAME tab.
		 * NcDashboardWidget renders item.targetUrl as a target="_blank" link
		 * (kept for accessibility / ctrl-click); this capture handler rewrites
		 * a plain left-click into a same-path hash change (no reload, same tab).
		 *
		 * @param {MouseEvent} e The captured click event.
		 * @return {void}
		 */
		onRowNav(e) {
			const a = e.target.closest('a[href]')
			const href = a && a.getAttribute('href')
			if (href && href.includes('/apps/procest/#/')) {
				e.preventDefault()
				window.location.href = href
			}
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
