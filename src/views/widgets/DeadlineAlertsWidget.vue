<template>
	<CnDataTable :rows="items"
		:columns="columns"
		:loading="loading"
		hide-header
		borderless
		:empty-text="t('procest', 'No deadline alerts')"
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
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { getDeadlineAlerts } from '../../utils/dashboardHelpers.js'
import { SIGNAL_COLUMNS, navigateTo } from './signalTable.js'

export default {
	name: 'DeadlineAlertsWidget',
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
			alerts: { overdue: [], atRisk: [] },
			columns: SIGNAL_COLUMNS,
		}
	},
	computed: {
		/** @spec openspec/specs/signalering-widgets/spec.md */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * Real destination URL for the "View all" link (gate-32: an `<a>`
		 * with a real `href` is a genuine link, not a mouse-only click
		 * target).
		 *
		 * @spec openspec/specs/signalering-widgets/spec.md
		 */
		viewAllUrl() {
			return generateUrl('/apps/procest/cases')
		},
		/** @spec openspec/specs/signalering-widgets/spec.md */
		items() {
			const overdueItems = this.alerts.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days overdue', { days: item.daysOverdue }),
				targetUrl: generateUrl(`/apps/procest/cases/${item.id}`),
			}))
			const atRiskItems = this.alerts.atRisk.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: item.daysRemaining === 0
					? t('procest', 'Due today')
					: t('procest', '{days} days remaining', { days: item.daysRemaining }),
				targetUrl: generateUrl(`/apps/procest/cases/${item.id}`),
			}))
			return [...overdueItems, ...atRiskItems].slice(0, 5)
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
		 * Navigate to a clicked case in the same tab.
		 *
		 * @param {object} row The clicked row (a shaped case item).
		 * @return {void}
		 */
		onRowClick(row) {
			navigateTo(row.targetUrl)
		},
		/**
		 * Navigate to the full cases list.
		 *
		 * @return {void}
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/procest/cases'))
		},
		/**
		 * Fetch case data and compute deadline alerts.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
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
