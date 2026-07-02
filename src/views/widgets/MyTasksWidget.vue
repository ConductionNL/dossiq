<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow"
		@click.native.capture="onRowNav">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'No tasks found')">
				<template #icon>
					<ClipboardCheckOutline />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'

export default {
	name: 'MyTasksWidget',
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
			tasks: [],
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
			return this.tasks.map((task) => ({
				id: task.id,
				mainText: task.title || t('procest', 'Unnamed task'),
				subText: task.dueDate
					? t('procest', 'Deadline: {date}', { date: task.dueDate.slice(0, 10) })
					: t('procest', 'No deadline'),
				avatarUrl: imagePath('procest', 'app-dark.svg'),
				targetUrl: generateUrl(`/apps/procest/tasks/${task.id}`),
			}))
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
			window.location.href = generateUrl(`/apps/procest/tasks/${item.id}`)
		},
		/**
		 * Intercept a plain row click so it navigates in the SAME tab.
		 * NcDashboardWidget renders item.targetUrl as a target="_blank" link
		 * (kept for accessibility / ctrl-click); this capture handler rewrites
		 * a plain left-click into a same-tab navigation to the history-mode
		 * task route so the in-app router resolves the detail page.
		 *
		 * @param {MouseEvent} e The captured click event.
		 * @return {void}
		 */
		onRowNav(e) {
			const a = e.target.closest('a[href]')
			const href = a && a.getAttribute('href')
			if (href && href.includes('/apps/procest/')) {
				e.preventDefault()
				window.location.href = href
			}
		},
		/**
		 * Fetch task data for current user.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		async fetchData() {
			this.loading = true
			try {
				const currentUser = OC?.currentUser || ''
				const results = await this.objectStore.fetchCollection('task', {
					'_filters[assignee]': currentUser,
					_limit: 7,
				})
				// Filter to active/available tasks only.
				this.tasks = (results || []).filter(
					t => t.status === 'available' || t.status === 'active',
				)
			} catch (err) {
				console.error('[MyTasksWidget] Failed to fetch tasks:', err)
				this.tasks = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
