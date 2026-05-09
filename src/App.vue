<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcContent app-name="procest">
		<template v-if="storesReady && !hasOpenRegisters">
			<NcAppContent class="open-register-missing">
				<NcEmptyContent
					:name="t('procest', 'OpenRegister is required')"
					:description="t('procest', 'Procest needs the OpenRegister app to store and manage data. Please install OpenRegister from the app store to get started.')">
					<template #icon>
						<img
							:src="appIcon"
							alt=""
							width="64"
							height="64">
					</template>
					<template #action>
						<NcButton
							v-if="isAdmin"
							type="primary"
							:href="appStoreUrl">
							{{ t('procest', 'Install OpenRegister') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</NcAppContent>
		</template>
		<template v-else-if="storesReady && hasOpenRegisters">
			<CnAppRoot
				:manifest="manifest"
				:custom-components="customComponents"
				:page-types="pageTypes" />
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import Vue from 'vue'
import { NcButton, NcContent, NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import { initializeStores } from './store/store.js'
import { useSettingsStore } from './store/modules/settings.js'

export default {
	name: 'App',
	components: {
		NcButton,
		NcContent,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
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
			storesReady: false,
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
		hasOpenRegisters() {
			const settingsStore = useSettingsStore()
			return settingsStore.hasOpenRegisters
		},
		isAdmin() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
		appIcon() {
			return imagePath('procest', 'app-dark.svg')
		},
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>
