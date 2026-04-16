<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
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
			required: true,
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
		items() {
			return this.tasks.map((task) => ({
				id: task.id,
				mainText: task.summary || t('procest', 'Unnamed task'),
				subText: task.due
					? t('procest', 'Deadline: {date}', { date: task.due.slice(0, 10) })
					: t('procest', 'No deadline'),
				avatarUrl: '/custom_apps/procest/img/app-dark.svg',
			}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle showing a task — navigate to the linked object in Procest.
		 *
		 * @param {object} item The task item to show
		 * @return {void}
		 */
		onShow(item) {
			const task = this.tasks.find(t => t.id === item.id)
			if (task?.objectUuid && task?.registerId && task?.schemaId) {
				window.location.href = `/index.php/apps/procest/#/cases/${task.objectUuid}`
			}
		},
		/**
		 * Fetch CalDAV tasks for the current user via OpenRegister's task API.
		 * Filters to non-completed tasks only.
		 *
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const headers = {
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
					'Content-Type': 'application/json',
				}
				const response = await fetch(
					'/index.php/apps/openregister/api/tasks?_limit=7&status=needs-action',
					{ headers },
				)
				if (response.ok) {
					const data = await response.json()
					this.tasks = data.results || []
				}
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
