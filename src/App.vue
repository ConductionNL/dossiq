<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		app-id="procest"
		:translate="translateForApp"
		:permissions="permissions" />
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		CnAppRoot,
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
		pageTypes: {
			type: Object,
			default: () => ({}),
		},
	},

	provide() {
		return {
			// Provide/inject channel for index pages that auto-mount sidebar
			// content; matches the decidesk pattern (App.vue hosts a single
			// CnObjectSidebar via CnAppRoot's #sidebar slot).
			objectSidebarState: this.objectSidebarState,
			// Legacy alias kept for any existing custom components that
			// inject `sidebarState` (CaseList / TaskList / VoorstelList /
			// AdminRoot referenced this name in the pre-manifest shell).
			sidebarState: this.objectSidebarState,
		}
	},

	data() {
		return {
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				schema: null,
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
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

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
		 */
		translateForApp(key) {
			return ncT('procest', key)
		},
	},
}
</script>
