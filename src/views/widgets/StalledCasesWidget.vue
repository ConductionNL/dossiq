<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'All cases active')">
				<template #icon>
					<CheckCircle />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStalledCases } from '../../utils/dashboardHelpers.js'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'

export default {
	name: 'StalledCasesWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		CheckCircle,
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
			stalledCases: [],
			itemMenu: {
				show: {
					text: t('procest', 'View case'),
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
			return this.stalledCases.slice(0, 5).map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('procest', '{days} days inactive', { days: item.daysSinceActivity }),
				avatarUrl: '/apps-extra/procest/img/app-dark.svg',
			}))
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
		onShow(item) {
			window.location.href = `/index.php/apps/procest/#/cases/${item.id}`
		},
		/**
		 * Fetch case data and compute stalled cases.
		 *
		 * @return {Promise<void>}
		 */
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

				this.stalledCases = getStalledCases(openCases, caseTypes)
			} catch (err) {
				console.error('[StalledCasesWidget] Failed to fetch data:', err)
				this.stalledCases = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
