<template>
	<CnDataTable :rows="items"
		:columns="columns"
		:loading="loading"
		hide-header
		borderless
		:empty-text="t('procest', 'No cases found')"
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
import { SIGNAL_COLUMNS, navigateTo } from './signalTable.js'

export default {
	name: 'CasesOverviewWidget',
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
			cases: [],
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
			return generateUrl('/apps/procest/cases')
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		items() {
			return this.cases.map((caseObj) => ({
				id: caseObj.id,
				mainText: caseObj.title || caseObj.identifier || t('procest', 'Unnamed case'),
				subText: caseObj.identifier
					? `#${caseObj.identifier}`
					: '',
				targetUrl: generateUrl(`/apps/procest/cases/${caseObj.id}`),
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
		 * Fetch case data.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
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
