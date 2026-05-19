<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:registry="registry"
		:page-types="pageTypes"
		:formatters="formatters"
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
			const base = window.OC?.currentUser?.permissions ?? []
			// CnAppNav's permission filter is an array-includes check; Nextcloud
			// does not put the boolean admin flag into the permissions array, so
			// we inject it here for manifest entries gated on permission: "admin"
			// (the platform-admin tenant management pages). isUserAdmin() returns
			// true for users in the Nextcloud admin group, matching the backend
			// TenantService::isPlatformAdmin() check.
			const isAdmin = typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
			return isAdmin ? [...base, 'admin'] : base
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
