<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		:aiCompanion="true"
		:manifest="manifest"
		:customComponents="customComponents"
		:registry="registry"
		:pageTypes="pageTypes"
		:formatters="formatters"
		:cellWidgets="cellWidgets"
		appId="dossiq"
		:translate="translateForApp"
		:permissions="permissions">
		<!--
			Host-mounted object sidebar (decidesk pattern). CnAppRoot
			suppresses its own auto-mounted CnObjectSidebar because this
			App provides `objectSidebarState`, so the detail-page tab
			strip (manifest `config.sidebarTabs` on CaseDetail /
			BezwaarDetail) only renders when we mount the sidebar here.
			CnDetailPage publishes its tabs into `objectSidebarState`
			(shared with this slot via CnAppRoot's ancestor-aware
			provide); we bind those tabs straight through.
			`:use-registry="false"` keeps the manifest `component:`-based
			tabs (CaseTasksTab / CaseEmailTab / …) instead of the
			integration-registry tabs.

			`objectSchema` and `objectData` are the RESOLVED schema and record,
			not the slugs beside them. A `data` sidebar tab renders
			CnObjectDataWidget, which builds its field list from the schema
			OBJECT and its values from the loaded record; given neither it
			returns an empty list and the tab reads "No data available" on a
			case that is perfectly fine. CnDetailPage publishes both into this
			shared state for exactly this reason — `schemaObject` and `object`
			— and they were simply never passed on, so the Tags tab could not
			show a tag and could not offer the Click-to-edit that adds a first
			one.
		-->
		<template #sidebar="{ pageSidebarComponent }">
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:useRegistry="false"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:objectType="objectSidebarState.objectType"
				:objectId="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:objectSchema="objectSidebarState.schemaObject"
				:objectData="objectSidebarState.object"
				:tabs="objectSidebarState.tabs"
				:hiddenTabs="objectSidebarState.hiddenTabs"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
			<!-- The manifest page's own sidebar (pages[].sidebarComponent). Passed in
			     as a slot prop because filling this slot suppresses CnAppRoot's
			     fallback, which is what hid the flow sidebar. -->
			<component :is="pageSidebarComponent" v-if="pageSidebarComponent" />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { reactive } from 'vue'
import { initializeStores } from './store/store.js'
import { currentPermissions } from './utils/permissions.js'

export default {
	name: 'App',
	components: {
		CnAppRoot,
		CnObjectSidebar,
	},

	/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
	provide() {
		return {
			// Provide/inject channel for index pages that auto-mount sidebar
			// content; matches the decidesk pattern (App.vue hosts a single
			// CnObjectSidebar via CnAppRoot's #sidebar slot).
			objectSidebarState: this.objectSidebarState,
			// Legacy alias kept for any existing custom components that
			// inject `sidebarState` (CaseList / TaskList / AdminRoot
			// referenced this name in the pre-manifest shell).
			sidebarState: this.objectSidebarState,
		}
	},

	props: {
		manifest: {
			type: Object,
			required: true,
		},

		customComponents: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * V2 component registry — map of registry-key → `{ kind, component }`.
		 * Forwarded verbatim to CnAppRoot, which validates kinds at mount time.
		 * Replaces the string-keyed customComponents prop for v2 manifests.
		 * Both props may coexist during transition (CnAppRoot warns once).
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		pageTypes: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Cell-formatter registry — forwarded to CnAppRoot as `cnFormatters`.
		 * Resolves `pages[].config.columns[].formatter` ids on index/logs
		 * pages (see src/services/formatters.js).
		 */
		formatters: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Cell-widget registry — forwarded to CnAppRoot as `cnCellWidgets`.
		 * Resolves `pages[].config.columns[].widget` ids to components on
		 * index pages (see src/services/cellWidgets.js). A formatter shapes a
		 * value; a cell widget is what a column needs when the cell carries a
		 * STATE the reader has to see, such as an overdue deadline.
		 */
		cellWidgets: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			objectSidebarState: reactive({
				active: false,
				open: true,
				// --- Detail-page object-sidebar fields (written by
				// CnDetailPage.syncSidebarState via inject). Predefined
				// here so Vue 2 tracks the writes reactively. ---
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				// `schema` doubles as the legacy index-sidebar schema and
				// the detail-sidebar schema slug; '' is the inert default.
				schema: '',
				hiddenTabs: [],
				tabs: undefined,
				// --- Legacy index-sidebar fields (kept for the custom
				// list components that inject `sidebarState`). ---
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
		permissions() {
			// One source, shared with the router guard in `main.js`. It used
			// to live here alone, and the router half of the same manifest
			// field went unenforced for as long as it did: the nav hid
			// Integrations from an ordinary account and the route rendered it
			// in full to the same account. See `utils/permissions.js` for why
			// the list is never empty and for what this does NOT close.
			return currentPermissions()
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
	async created() {
		// Pinia stores still need to come up so legacy custom components
		// keep working through the manifest transition. CnAppRoot itself
		// doesn't depend on them.
		await initializeStores()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md
		 */
		translateForApp(key) {
			return ncT('dossiq', key)
		},
	},
}
</script>
