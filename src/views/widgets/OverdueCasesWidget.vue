<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'Geen openstaande zaken')">
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
import { getOverdueCases } from '../../utils/dashboardHelpers.js'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'OverdueCasesWidget',
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
			overdueCases: [],
			itemMenu: {
				show: {
					text: t('procest', 'Bekijk zaak'),
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
			return this.overdueCases.map((caseObj) => ({
				id: caseObj.id,
				mainText: caseObj.title || caseObj.identifier || t('procest', 'Naamloze zaak'),
				subText: caseObj.daysOverdue
					? t('procest', '{days} dagen te laat', { days: caseObj.daysOverdue })
					: caseObj.identifier || '',
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
		 * Fetch overdue case data.
		 *
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const [cases, caseTypes, statusTypes] = await Promise.all([
					this.objectStore.fetchCollection('case', { _limit: 1000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
				])

				// Filter to open cases (non-final status).
				const statusTypeMap = new Map()
				for (const st of (statusTypes || [])) {
					statusTypeMap.set(st.id, st)
				}
				const openCases = (cases || []).filter(c => {
					const st = statusTypeMap.get(c.status)
					return !st?.isFinal
				})

				this.overdueCases = getOverdueCases(openCases, caseTypes || []).slice(0, 7)
			} catch (err) {
				console.error('[OverdueCasesWidget] Failed to fetch cases:', err)
				this.overdueCases = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
