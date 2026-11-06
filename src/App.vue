<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		ref="appRoot"
		:aiCompanion="true"
		:manifest="manifest"
		:registry="registry"
		:pageTypes="pageTypes"
		:formatters="formatters"
		appId="dossiq"
		:translate="translateForApp"
		:notificationLabels="notificationLabels"
		:permissions="permissions"
		:supportDialog="supportDialog"
		:userSettingsSections="userSettingsSections"
		:userSettingsNextcloudLinks="userSettingsNextcloudLinks">
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
			tabs (CaseTimelineTab / …) instead of the
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
		<!--
			The user settings dialog. Every per-person preference lives here;
			dossiq has no Nextcloud personal settings page (Ruben, 11 Oct 2026).
			The app's sections join the library's credentials, guided tour and
			integrations sections; the library's notification section stays out,
			because dossiq's Notices section is the screen over those preferences.
		-->
		<template #user-settings-sections>
			<UserSettings />
		</template>
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
import { loadState } from '@nextcloud/initial-state'
import { translate as ncT } from '@nextcloud/l10n'
import { reactive } from 'vue'
import UserSettings from './views/settings/UserSettings.vue'
import { initializeStores } from './store/store.js'
import { notificationLabels } from './utils/notificationLabels.js'
import { supportDialogForThisLoad } from './utils/onboardingOrder.js'
import { currentPermissions } from './utils/permissions.js'

/**
 * The location hash that opens the user settings dialog.
 */
const USER_SETTINGS_HASH = '#user-settings'

export default {
	name: 'App',
	components: {
		CnAppRoot,
		CnObjectSidebar,
		UserSettings,
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

		/**
		 * V2 component registry — map of registry-key → `{ kind, component }`.
		 * Forwarded verbatim to CnAppRoot, which validates kinds at mount time.
		 * The only registry this app passes: the legacy string-keyed
		 * `customComponents` prop is deprecated for v2 manifests, and every
		 * entry that lived in it — pages, dashboard slots and the handlers a
		 * manifest names by string — is a kinded entry in src/registry.js.
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
			// Off on a load where the welcome tour is not yet behind the user,
			// so the tour comes first, and off when an administrator switched
			// the note off (src/utils/onboardingOrder.js).
			supportDialog: supportDialogForThisLoad(
				loadState('dossiq', 'walkthrough_seen', null),
				loadState('dossiq', 'support_note_shown', null),
			),

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
		/**
		 * The user settings dialog's sections, in order: the library's Views in
		 * my menu first (board DqPersoonlijkeInstellingen), the app's own, then
		 * the library's credentials, guided tour and integrations.
		 *
		 * @return {Array<string>} The section keys CnAppRoot draws.
		 * @spec openspec/specs/personal-settings-surface/spec.md#requirement-the-personal-settings-open-with-the-views-in-my-menu
		 */
		userSettingsSections() {
			return ['views', 'app', 'credentials', 'walkthrough', 'integrations']
		},

		/**
		 * Nextcloud's own personal settings, linked from the dialog's navigation
		 * above the app's entry. Nextcloud offers an app no list of them.
		 *
		 * @return {Array<{label: string, section: string, icon: string}>} The links.
		 * @spec openspec/specs/personal-settings-surface/spec.md
		 */
		userSettingsNextcloudLinks() {
			return [
				{
					label: 'Personal info',
					section: 'personal-info',
					icon: 'AccountOutline',
				},
				{ label: 'Security', section: 'security', icon: 'LockOutline' },
				{
					label: 'Notifications',
					section: 'notifications',
					icon: 'BellOutline',
				},
				{
					label: 'Mobile & desktop',
					section: 'sync-clients',
					icon: 'Cellphone',
				},
				{
					label: 'Sharing',
					section: 'sharing',
					icon: 'ShareVariantOutline',
				},
				{
					label: 'Appearance and accessibility',
					section: 'theming',
					icon: 'EyeOutline',
				},
				{
					label: 'Availability',
					section: 'availability',
					icon: 'ClockOutline',
				},
				{
					label: 'Privacy',
					section: 'privacy',
					icon: 'ShieldAccountOutline',
				},
			]
		},

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

		/**
		 * Labels for dossiq's notification rules in the user-settings pane,
		 * so a person reads "A case is assigned to me" rather than
		 * "caseAssigned".
		 *
		 * @return {{[key: string]: string}} Labels keyed `<schema>.<key>`.
		 * @spec openspec/specs/notification-labels/spec.md
		 */
		notificationLabels() {
			return notificationLabels()
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
	async created() {
		// Pinia stores still need to come up so legacy custom components
		// keep working through the manifest transition. CnAppRoot itself
		// doesn't depend on them.
		await initializeStores()
	},

	mounted() {
		window.addEventListener('hashchange', this.openUserSettingsFromHash)
		this.openUserSettingsFromHash()
	},

	beforeUnmount() {
		window.removeEventListener('hashchange', this.openUserSettingsFromHash)
	},

	methods: {
		/**
		 * The mounted CnAppRoot, or null before it mounts.
		 *
		 * @return {object|null} The CnAppRoot instance.
		 * @spec openspec/specs/personal-settings-surface/spec.md
		 */
		appRoot() {
			return this.$refs.appRoot || null
		},

		/**
		 * Open the user settings dialog when the location hash asks for it,
		 * then drop the hash so a reload does not open it again.
		 *
		 * @return {void}
		 * @spec openspec/specs/personal-settings-surface/spec.md
		 */
		openUserSettingsFromHash() {
			const root = this.appRoot()
			if (!root || window.location.hash !== USER_SETTINGS_HASH) {
				return
			}
			root.userSettingsOpen = true
			window.history.replaceState(
				window.history.state,
				'',
				window.location.pathname + window.location.search,
			)
		},

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
