<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('procest', 'No cases found')">
				<template #icon>
					<FolderOpen />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'

export default {
	name: 'CasesOverviewWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		FolderOpen,
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
			cases: [],
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
			return this.cases.map((caseObj) => ({
				id: caseObj.id,
				mainText: caseObj.title || caseObj.identifier || t('procest', 'Unnamed case'),
				subText: caseObj.identifier
					? `#${caseObj.identifier}`
					: '',
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
		 * Fetch case data.
		 *
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('case', {
					_limit: 7,
					_order: { startDate: 'desc' },
				})
				this.cases = results || []
			} catch (err) {
				console.error('[CasesOverviewWidget] Failed to fetch cases:', err)
				this.cases = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
