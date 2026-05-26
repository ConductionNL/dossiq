<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'No deadline alerts')">
				<template #icon>
					<AlertCircle />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getDeadlineAlerts } from '../../utils/dashboardHelpers.js'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'DeadlineAlertsWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		AlertCircle,
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
			alerts: { overdue: [], atRisk: [] },
			itemMenu: {
				show: {
					text: t('procest', 'View case'),
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
			const overdueItems = this.alerts.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days overdue', { days: item.daysOverdue }),
				avatarUrl: '/apps-extra/procest/img/app-dark.svg',
			}))
			const atRiskItems = this.alerts.atRisk.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: item.daysRemaining === 0
					? t('procest', 'Due today')
					: t('procest', '{days} days remaining', { days: item.daysRemaining }),
				avatarUrl: '/apps-extra/procest/img/app-dark.svg',
			}))
			return [...overdueItems, ...atRiskItems].slice(0, 5)
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle showing a case.
		 *
		 * @param {object} item The case item to show
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		onShow(item) {
			window.location.href = `/index.php/apps/procest/#/cases/${item.id}`
		},
		/**
		 * Fetch case data and compute deadline alerts.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		async fetchData() {
			this.loading = true
			try {
				const results = await Promise.allSettled([
					this.objectStore.fetchCollection('case', { _limit: 1000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
				])

				const allCases = results[0].status === 'fulfilled' ? (results[0].value || []) : []
				const caseTypes = results[1].status === 'fulfilled' ? (results[1].value || []) : []
				const statusTypes = results[2].status === 'fulfilled' ? (results[2].value || []) : []

				const statusTypeMap = new Map()
				for (const st of statusTypes) {
					statusTypeMap.set(st.id, st)
				}
				const openCases = allCases.filter(c => {
					const st = statusTypeMap.get(c.status)
					return !st?.isFinal
				})

				this.alerts = getDeadlineAlerts(openCases, caseTypes)
			} catch (err) {
				console.error('[DeadlineAlertsWidget] Failed to fetch data:', err)
				this.alerts = { overdue: [], atRisk: [] }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
