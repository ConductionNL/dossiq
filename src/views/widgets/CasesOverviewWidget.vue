<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow"
		@click.native.capture="onRowNav">
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
import { generateUrl, imagePath } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
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
			default: '',
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
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		items() {
			return this.cases.map((caseObj) => ({
				id: caseObj.id,
				mainText: caseObj.title || caseObj.identifier || t('procest', 'Unnamed case'),
				subText: caseObj.identifier
					? `#${caseObj.identifier}`
					: '',
				avatarUrl: imagePath('procest', 'app-dark.svg'),
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
		 * Handle showing a case.
		 *
		 * @param {object} item The case item to show
		 * @return {void}
		 */
		/**
		 * @param item
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		onShow(item) {
			window.location.href = generateUrl(`/apps/procest/cases/${item.id}`)
		},
		/**
		 * Intercept a plain row click so it navigates in the SAME tab.
		 * NcDashboardWidget renders item.targetUrl as a target="_blank" link
		 * (kept for accessibility / ctrl-click); this capture handler rewrites
		 * a plain left-click into a same-tab navigation to the history-mode
		 * case route so the in-app router resolves the detail page.
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
