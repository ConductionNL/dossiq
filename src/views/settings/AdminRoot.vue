<template>
	<CnAdminSettingsShell
		appId="dossiq"
		appName="Dossiq"
		look="board"
		:title="pageTitle"
		docUrl="https://dossiq.conduction.nl/docs/intro"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="breadcrumbNote"
		:tabs="adminTabs"
		tabStorageKey="dossiq.admin.activeTab"
		tabsVariant="underline"
		:compact="true"
		:sectionColumns="1"
		:showSetup="true"
		:setupSteps="setupSteps"
		lazyTabs
		@tabChange="onTabChange"
		@reimported="onReimported">
		<Settings />

		<CnSettingsSection
			id="section-menu-structure"
			:name="t('dossiq', 'Menu structure')"
			:description="
				t('dossiq', 'Choose how much the menu shows. Simple is the default.')
			">
			<MenuStructureTab />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-background-service-account"
			:name="t('dossiq', 'Background service account')"
			:description="
				t(
					'dossiq',
					'The account background jobs save as, with the access checks of OpenRegister on.',
				)
			">
			<BackgroundServiceAccountSettings />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-mailbox"
			:name="t('dossiq', 'Case email: shared mailbox')"
			:description="
				t(
					'dossiq',
					'Shared functional mailbox ingest (IMAP) and transport for case correspondence. Outbound mail and per-user accounts are owned by Nextcloud Mail.',
				)
			"
			:loading="!storesReady">
			<EmailSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'AI-assisted processing')"
			:description="
				t(
					'dossiq',
					'Configure AI features for document classification, data extraction, Q&A, summarization, routing and decision support',
				)
			"
			:loading="!storesReady">
			<AiSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Consultation management')"
			:description="
				t(
					'dossiq',
					'Adviesaanvragen: advisory body registry, mandatory-gate config, n8n webhook contracts and external response settings.',
				)
			"
			:loading="!storesReady">
			<ConsultationSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-search-index"
			:name="t('dossiq', 'Search index')"
			:description="
				t(
					'dossiq',
					'The indexes case search reads. They live in OpenRegister, which owns the query layer; dossiq keeps no copy of their state. A stale index is a case nobody finds.',
				)
			"
			:loading="!storesReady">
			<SearchIndexTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-first-run"
			:name="t('dossiq', 'First run')"
			:description="
				t(
					'dossiq',
					'What this instance still needs before it can take a case. Reported, never required. Repair the register with the Re-import configuration button.',
				)
			"
			:loading="!storesReady">
			<FirstRunTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-prerequisites"
			:name="t('dossiq', 'Prerequisites')"
			:description="
				t(
					'dossiq',
					'What dossiq needs to run, and what this instance has. Read live, every time you open this page.',
				)
			">
			<PrerequisitesTab />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-shipped-configuration"
			:name="t('dossiq', 'What shipped with dossiq')"
			:description="
				t(
					'dossiq',
					'Every case type, status, result and role dossiq seeded, and whether you changed it since. A newer shipped version is offered per object and never overwrites a change you made.',
				)
			"
			:loading="!storesReady">
			<ShippedConfiguration v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Tenant onboarding')"
			:description="
				t(
					'dossiq',
					'Track a tenant through the seven onboarding steps, run the go-live readiness check and activate the tenant. One-time setup, so it belongs here rather than in the daily navigation.',
				)
			"
			:loading="!storesReady">
			<TenantOnboardingTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Case type management')"
			:description="t('dossiq', 'Manage case types and their configurations')"
			:loading="!storesReady">
			<CaseTypeAdmin v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'AWB term definitions')"
			:description="
				t(
					'dossiq',
					'Configure statutory term definitions per zaaktype for AWB termijnbewaking (legal basis, duration, validity). Versioning is enforced on save.',
				)
			"
			:loading="!storesReady">
			<TermijnDefinitiesTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Queue urgency')"
			:description="
				t(
					'dossiq',
					'When a case counts as critical or almost due, and how much priority and time lying still weigh in the order.',
				)
			">
			<QueueUrgencySettingsTab />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Mandate matrix: administration')"
			:description="
				t(
					'dossiq',
					'Configure mandate decisions, organisational roles, role assignments, and import legacy mandate exports',
				)
			"
			:loading="!storesReady">
			<MandaatMatrixTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Mandate matrix: system settings')"
			:description="
				t(
					'dossiq',
					'Awb art. 10:3 mandate administration: Decidiq import, role hierarchy, waarnemer assignments.',
				)
			"
			:loading="!storesReady">
			<MandaatMatrixSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-archival"
			:name="t('dossiq', 'Archiving')"
			:description="
				t(
					'dossiq',
					'How often a reviewer is reminded of the archival decisions they hold. The setting lives in OpenRegister, which runs the archiving process; dossiq keeps no copy of it.',
				)
			"
			:loading="!storesReady">
			<ArchivalSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			id="section-woo-reports"
			:name="t('dossiq', 'Woo review reports')"
			:description="
				t(
					'dossiq',
					'Choose which Woo review reports your organisation uses, and who may read the throughput per reviewer.',
				)
			"
			:loading="!storesReady">
			<WooReportsSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<template #tab-integrations>
			<CnSettingsSection
				id="section-connections"
				:name="t('dossiq', 'Integrations')"
				:description="
					t(
						'dossiq',
						'A connection stays off until you choose a source. Off means: not one request goes outside.',
					)
				">
				<NcNoteCard v-if="!ownerApps.integriq" type="info">
					{{
						t(
							'dossiq',
							'The {app} app is not installed, so there is nothing to list here.',
							{ app: 'integriq' },
						)
					}}
				</NcNoteCard>
				<CnWidgetObjectTable
					v-else
					:hideWrapper="true"
					:source="connectionsSource"
					:columns="connectionColumns"
					:emptyText="
						t(
							'dossiq',
							'No connections reported yet. They appear once integriq has read dossiq\'s declaration.',
						)
					" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-zgw"
				:name="t('dossiq', 'ZGW API mapping')"
				:description="
					t(
						'dossiq',
						'Configure property mappings between English OpenRegister fields and Dutch ZGW API fields',
					)
				"
				:loading="!storesReady">
				<ZgwMappingSettings v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-kcc"
				:name="t('dossiq', 'KCC-werkplek integration')"
				:description="
					t(
						'dossiq',
						'Burger identification, case-voorblad limits, sentiment trigger words, and belplan overflow thresholds for the KCC contact-center bridge.',
					)
				"
				:loading="!storesReady">
				<KccIntegrationSettings v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-dmn"
				:name="t('dossiq', 'Decision tables (DMN)')"
				:description="
					t(
						'dossiq',
						'Decision tables live in the decision-tables register of OpenRegister. Dossiq stores its own tables there under keys that start with dossiq. and evaluates them from workflow steps and the enforcement recommendation. Open OpenRegister to read or change a table.',
					)
				"
				:loading="!storesReady" />

			<CnSettingsSection
				id="section-financial"
				:name="t('dossiq', 'Financial integration: dwangsom callback')"
				:description="
					t(
						'dossiq',
						'Configure the shared secret used to validate ERP payment-confirmation callbacks for dwangsom (penalty payment) uitbetalingen.',
					)
				"
				:loading="!storesReady">
				<FinancialIntegrationTab v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-stuf"
				:name="t('dossiq', 'StUF-ZKN endpoints')"
				:description="
					t(
						'dossiq',
						'Outbound StUF-ZKN/BG zaaksysteem endpoints per gemeente, with per-endpoint circuit-breaker health. Endpoints, WSSE credentials and mTLS certificates are managed by the platform operator.',
					)
				"
				:loading="!storesReady">
				<StufEndpoints v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				:name="t('dossiq', 'StUF-ZKN audit log')"
				:description="
					t(
						'dossiq',
						'Per-call audit log for outbound and inbound StUF SOAP envelopes (full XML, HTTP status, duration, retry history).',
					)
				"
				:loading="!storesReady">
				<StufAuditLog v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-store"
				:name="t('dossiq', 'Store registry')"
				:description="
					t(
						'dossiq',
						'Connect the OpenRegister instance the Store page browses. Without one, dossiq stays offline and shows only its own templates.',
					)
				"
				:loading="!storesReady">
				<StoreSettingsTab v-if="storesReady" />
			</CnSettingsSection>
		</template>

		<template #tab-mail>
			<CnSettingsSection
				id="section-mail-intake"
				:name="t('dossiq', 'Mail to cases')"
				:description="
					t(
						'dossiq',
						'Every mail the shared mailbox took in, and the case it went to or why it was skipped. The mailbox itself is set up under General.',
					)
				">
				<NcButton :href="mailIntakeLogUrl" variant="secondary">
					{{ t('dossiq', 'View the mail intake log') }}
				</NcButton>
			</CnSettingsSection>
		</template>

		<template #tab-map-layers>
			<CnSettingsSection
				id="section-map-layers"
				:name="t('dossiq', 'Map layers')"
				:description="
					t(
						'dossiq',
						'The WMS and WMTS layers the case map and the location fields offer. Each layer is a record you add, order and switch on in the map layers list.',
					)
				">
				<NcButton :href="mapLayersUrl" variant="secondary">
					{{ t('dossiq', 'Open the map layers') }}
				</NcButton>
			</CnSettingsSection>
		</template>

		<template #tab-vth>
			<CnSettingsSection
				id="section-intake-activities"
				:name="t('dossiq', 'Activities from the Omgevingsloket')"
				:description="
					t(
						'dossiq',
						'An application from the DSO becomes a case of the linked case type. The mapping is integriq\'s.',
					)
				">
				<NcNoteCard v-if="!ownerApps.integriq" type="info">
					{{
						t(
							'dossiq',
							'The {app} app is not installed, so there is nothing to list here.',
							{ app: 'integriq' },
						)
					}}
				</NcNoteCard>
				<CnWidgetObjectTable
					v-else
					:hideWrapper="true"
					:source="intakeActivitySource"
					:columns="intakeActivityColumns"
					:emptyText="t('dossiq', 'No activities linked yet.')" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-fee-schedules"
				:name="t('dossiq', 'Fee rules')"
				:description="
					t(
						'dossiq',
						'What a case type costs, per rule and validity date. The fee schedules are shillinq\'s.',
					)
				">
				<NcNoteCard v-if="!ownerApps.shillinq" type="info">
					{{
						t(
							'dossiq',
							'The {app} app is not installed, so there is nothing to list here.',
							{ app: 'shillinq' },
						)
					}}
				</NcNoteCard>
				<CnWidgetObjectTable
					v-else
					:hideWrapper="true"
					:source="feeScheduleSource"
					:columns="feeScheduleColumns"
					:emptyText="t('dossiq', 'No fee rules for dossiq yet.')" />
			</CnSettingsSection>

			<CnSettingsSection
				:name="t('dossiq', 'VTH inspection checklists')"
				:description="
					t(
						'dossiq',
						'Configure reusable inspection checklists for VTH cases (Toezicht). Checklists are versioned and linked to case types.',
					)
				"
				:loading="!storesReady">
				<ChecklistsTab v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-enforcement-strategy"
				:name="t('dossiq', 'Enforcement strategy')"
				:description="
					t(
						'dossiq',
						'The matrix that recommends an intervention from the severity of a violation and the behaviour of the offender.',
					)
				">
				<CnWidgetObjectTable
					:hideWrapper="true"
					:source="enforcementMatrixSource"
					:columns="enforcementMatrixColumns"
					:emptyText="t('dossiq', 'No enforcement matrix yet.')" />
			</CnSettingsSection>
		</template>

		<template #tab-substitution>
			<CnSettingsSection
				id="section-substitution"
				:name="t('dossiq', 'Substitution')"
				:description="
					t(
						'dossiq',
						'Who takes over the cases of an absent handler, and the bulk reassignment of their work.',
					)
				">
				<SubstitutionAdmin />
			</CnSettingsSection>
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import {
	CnAdminSettingsShell,
	CnSettingsSection,
	CnWidgetObjectTable,
} from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import SubstitutionAdmin from '../admin/SubstitutionAdmin.vue'
import BackgroundServiceAccountSettings from './BackgroundServiceAccountSettings.vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import EmailSettings from './EmailSettings.vue'
import KccIntegrationSettings from './KccIntegrationSettings.vue'
import Settings from './Settings.vue'
import ShippedConfiguration from './ShippedConfiguration.vue'
import StufAuditLog from './StufAuditLog.vue'
import StufEndpoints from './StufEndpoints.vue'
import AiSettingsTab from './tabs/AiSettingsTab.vue'
import ArchivalSettingsTab from './tabs/ArchivalSettingsTab.vue'
import ChecklistsTab from './tabs/ChecklistsTab.vue'
import ConsultationSettingsTab from './tabs/ConsultationSettingsTab.vue'
import FinancialIntegrationTab from './tabs/FinancialIntegrationTab.vue'
import FirstRunTab from './tabs/FirstRunTab.vue'
import MandaatMatrixSettingsTab from './tabs/MandaatMatrixSettingsTab.vue'
import MandaatMatrixTab from './tabs/MandaatMatrixTab.vue'
import MenuStructureTab from './tabs/MenuStructureTab.vue'
import PrerequisitesTab from './tabs/PrerequisitesTab.vue'
import QueueUrgencySettingsTab from './tabs/QueueUrgencySettingsTab.vue'
import SearchIndexTab from './tabs/SearchIndexTab.vue'
import StoreSettingsTab from './tabs/StoreSettingsTab.vue'
import TenantOnboardingTab from './tabs/TenantOnboardingTab.vue'
import TermijnDefinitiesTab from './tabs/TermijnDefinitiesTab.vue'
import WooReportsSettingsTab from './tabs/WooReportsSettingsTab.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
import manifest from '../../manifest.json'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		BackgroundServiceAccountSettings,
		FirstRunTab,
		PrerequisitesTab,
		TenantOnboardingTab,
		CnAdminSettingsShell,
		CnSettingsSection,
		CnWidgetObjectTable,
		Settings,
		CaseTypeAdmin,
		ZgwMappingSettings,
		AiSettingsTab,
		ArchivalSettingsTab,
		SearchIndexTab,
		ChecklistsTab,
		TermijnDefinitiesTab,
		WooReportsSettingsTab,
		QueueUrgencySettingsTab,
		MandaatMatrixTab,
		MandaatMatrixSettingsTab,
		MenuStructureTab,
		ConsultationSettingsTab,
		StoreSettingsTab,
		FinancialIntegrationTab,
		EmailSettings,
		KccIntegrationSettings,
		ShippedConfiguration,
		StufEndpoints,
		StufAuditLog,
		NcButton,
		NcNoteCard,
		SubstitutionAdmin,
	},

	/**
	 * The admin page has no CnAppRoot, so it hands the library dossiq's own
	 * translate itself: the setup wizard reads its step copy from the manifest
	 * through `cnTranslate`.
	 *
	 * @return {object} The provided values.
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	provide() {
		return {
			cnTranslate: (key, vars) => this.t('dossiq', key, vars),
		}
	},

	data() {
		return {
			storesReady: false,
			activeTab: 'general',
			ownerApps: loadState('dossiq', 'ownerApps', {
				integriq: true,
				shillinq: true,
			}),
		}
	},

	computed: {
		/**
		 * The tabs of the page, in the order the board draws them. Every
		 * section the page had before sits on one of them.
		 *
		 * @return {Array<{id: string, label: string}>} The tabs.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		adminTabs() {
			return [
				{ id: 'general', label: this.t('dossiq', 'General') },
				{
					id: 'integrations',
					label: this.t('dossiq', 'Integration settings'),
				},
				{ id: 'mail', label: this.t('dossiq', 'Mail') },
				{ id: 'map-layers', label: this.t('dossiq', 'Map layers') },
				{ id: 'vth', label: this.t('dossiq', 'VTH') },
				{ id: 'substitution', label: this.t('dossiq', 'Substitution') },
			]
		},

		/**
		 * The open tab's label.
		 *
		 * @return {string} The label, or the app's settings title before a tab is known.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		activeTabLabel() {
			const tab = this.adminTabs.find((entry) => entry.id === this.activeTab)
			return tab ? tab.label : this.t('dossiq', 'Dossiq settings')
		},

		/**
		 * The page title names the app and the open tab.
		 *
		 * @return {string} The title.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		pageTitle() {
			return this.t('dossiq', 'Dossiq administration: {tab}', {
				tab: /^[A-Z]{2,}$/.test(this.activeTabLabel)
					? this.activeTabLabel
					: this.activeTabLabel.toLocaleLowerCase(),
			})
		},

		/**
		 * The path under the title: the Nextcloud administration settings,
		 * the app, then the open tab.
		 *
		 * @return {Array<{label: string, href?: string}>} The crumbs.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		breadcrumbs() {
			return [
				{
					label: this.t('dossiq', 'Admin settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Dossiq' },
				{ label: this.activeTabLabel },
			]
		},

		/**
		 * The note after the path: what the open tab's settings hold for.
		 *
		 * @return {string} The note.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		breadcrumbNote() {
			if (this.activeTab === 'vth') {
				return this.t(
					'dossiq',
					'permits, supervision and enforcement, for the whole organisation',
				)
			}
			return this.t(
				'dossiq',
				'applies to the whole organisation; case types, flows and departments are in the app under Settings',
			)
		},

		/**
		 * The first-run wizard's steps, as the app's manifest declares them,
		 * so the version card can start the wizard again.
		 *
		 * @return {Array<object>} The steps.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		setupSteps() {
			return manifest.setup?.steps ?? []
		},

		/**
		 * dossiq's rows in integriq's connection registry.
		 *
		 * @return {object} The table source.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		connectionsSource() {
			return {
				register: 'integriq',
				schema: 'app_connection',
				filter: { app: 'dossiq' },
				order: { order: 'asc' },
				limit: 50,
			}
		},

		/**
		 * The connection table's columns, as the board draws them.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		connectionColumns() {
			return [
				{
					key: 'title',
					label: this.t('dossiq', 'Connection'),
					cellClass: 'cn-cell--strong',
					secondary: '{description}',
				},
				{ key: 'statusMessage', label: this.t('dossiq', 'Source') },
				{
					key: 'status',
					label: this.t('dossiq', 'Status'),
					widget: 'badge',
				},
				{ key: '@self.updated', label: this.t('dossiq', 'Last contact') },
			]
		},

		/**
		 * integriq's DSO activity mappings: which case type an activity becomes.
		 *
		 * @return {object} The table source.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		intakeActivitySource() {
			return {
				register: 'integriq',
				schema: 'dso_activity_mapping',
				limit: 50,
			}
		},

		/**
		 * The DSO activity table's columns.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		intakeActivityColumns() {
			return [
				{
					key: 'activityName',
					label: this.t('dossiq', 'DSO activity'),
					cellClass: 'cn-cell--strong',
				},
				{ key: 'caseTypes', label: this.t('dossiq', 'Becomes case type') },
				{ key: 'note', label: this.t('dossiq', 'Note') },
				{
					key: 'isActive',
					label: this.t('dossiq', 'Active'),
					widget: 'badge',
				},
			]
		},

		/**
		 * shillinq's fee schedules for dossiq's records.
		 *
		 * @return {object} The table source.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		feeScheduleSource() {
			return {
				register: 'shillinq',
				schema: 'FeeSchedule',
				filter: { targetApp: 'dossiq' },
				limit: 50,
			}
		},

		/**
		 * The fee table's columns.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		feeScheduleColumns() {
			return [
				{
					key: 'typeValue',
					label: this.t('dossiq', 'Rule'),
					cellClass: 'cn-cell--strong',
				},
				{ key: 'legalBasis.regulation', label: this.t('dossiq', 'Basis') },
				{ key: 'amount', label: this.t('dossiq', 'Amount') },
				{ key: 'validFrom', label: this.t('dossiq', 'Valid from') },
			]
		},

		/**
		 * The enforcement matrices dossiq's recommendation reads.
		 *
		 * @return {object} The table source.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		enforcementMatrixSource() {
			return { register: 'dossiq', schema: 'lhsMatrix', limit: 10 }
		},

		/**
		 * The enforcement matrix table's columns.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		enforcementMatrixColumns() {
			return [
				{
					key: 'name',
					label: this.t('dossiq', 'Matrix'),
					cellClass: 'cn-cell--strong',
				},
				{ key: 'version', label: this.t('dossiq', 'Version') },
				{
					key: 'active',
					label: this.t('dossiq', 'Active'),
					widget: 'badge',
				},
				{ key: '@self.updated', label: this.t('dossiq', 'Last changed') },
			]
		},

		/**
		 * The in-app log of the mail the shared mailbox took in.
		 *
		 * @return {string} The address.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		mailIntakeLogUrl() {
			return generateUrl('/apps/dossiq/mail-intake-log')
		},

		/**
		 * The in-app list of map layers, where a layer is added and ordered.
		 *
		 * @return {string} The address.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		mapLayersUrl() {
			return generateUrl('/apps/dossiq/settings/wms-layers')
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Remember which tab is open, for the title and the path.
		 *
		 * @param {string} id The open tab's id.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		onTabChange(id) {
			this.activeTab = id
		},

		/**
		 * Refresh the app stores after the shell re-imports the OpenRegister configuration.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async onReimported() {
			this.storesReady = false
			await initializeStores()
			this.storesReady = true
		},
	},
}
</script>
