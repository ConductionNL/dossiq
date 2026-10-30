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
		:descGap="6"
		plainNotes
		:sectionColumns="['general', 'vth'].includes(activeTab) ? 2 : 1"
		:formatters="adminFormatters"
		:showSetup="true"
		:setupSteps="setupSteps"
		lazyTabs
		@tabChange="onTabChange"
		@reimported="onReimported">
		<CnSettingsFieldsSection
			id="section-house-style"
			appId="dossiq"
			:name="t('dossiq', 'House style')"
			:columns="4"
			:wide="true"
			valuesPath="config"
			:fields="houseStyleFields" />

		<CnSettingsFieldsSection
			id="section-texts-and-access"
			appId="dossiq"
			:name="t('dossiq', 'Texts and access')"
			:columns="1"
			valuesPath="config"
			:fields="textsAndAccessFields">
		</CnSettingsFieldsSection>

		<CnSettingsSection
			id="section-working-days"
			:actionsInTitle="true"
			:name="t('dossiq', 'Working days and holidays')">
			<template #actions>
				<NcButton variant="secondary" data-testid="dossiq-working-days-add" @click="$refs.workingCalendar.openAdd()">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('dossiq', 'Add day') }}
				</NcButton>
			</template>
			<CnWorkingCalendarWidget ref="workingCalendar" :content="workingCalendarContent" />
		</CnSettingsSection>

		<template #tab-system>
			<CnRegisterMapping
				id="section-configuration"
				:name="t('dossiq', 'Configuration')"
				:description="
					t(
						'dossiq',
						'In which register Dossiq keeps cases, and with which schemas.',
					)
				"
				docUrl="https://dossiq.conduction.nl/docs/intro"
				boardLayout="selects"
				:groups="registerGroups"
				:configuration="registerConfiguration"
				:saving="registerSaving"
				:saveButtonText="t('dossiq', 'Save')"
				@save="saveRegisterMapping" />

			<CnSettingsFieldsSection
				id="section-menu-structure"
				appId="dossiq"
				:name="t('dossiq', 'Menu structure')"
				:description="
					t(
						'dossiq',
						'How much the menu shows. Simple is the default.',
					)
				"
				valuesPath="config"
				:autoSave="true"
				:savedMessage="
					t(
						'dossiq',
						'Saved. People see the change the next time they open Dossiq.',
					)
				"
				:fields="menuStructureFields" />

			<CnSettingsFieldsSection
				id="section-background-service-account"
				:name="t('dossiq', 'Background service account')"
				:description="
					t(
						'dossiq',
						'The account Dossiq works as while nobody is signed in, such as for term monitoring and fetching mail.',
					)
				"
				url="/apps/dossiq/api/settings/background-service-account"
				:reloadAfterSave="true"
				:saveLabel="t('dossiq', 'Use this account')"
				saveVariant="secondary"
				:saveInline="true"
				:savedMessage="t('dossiq', 'Background jobs now save as {userId}.')"
				:fields="backgroundAccountFields" />

			<CnSettingsFieldsSection
				id="section-mailbox"
				:name="t('dossiq', 'Case email: shared mailbox')"
				:description="
					t(
						'dossiq',
						'Mail to the shared mailbox comes in as a case or on an existing case.',
					)
				"
				url="/apps/dossiq/api/settings/email"
				:columns="2"
				:saveAll="true"
				:hintsToggle="true"
				:saveLabel="t('dossiq', 'Save')"
				:savedMessage="t('dossiq', 'Mailbox settings saved.')"
				:fields="sharedMailboxFields" />

			<CnSettingsFieldsSection
				id="section-ai"
				:name="t('dossiq', 'AI-assisted processing')"
				:description="
					t(
						'dossiq',
						'Summarise, classify and anonymise with a language model you choose.',
					)
				"
				url="/apps/dossiq/api/ai/settings"
				:columns="2"
				valuesPath="settings"
				saveMethod="POST"
				:autoSave="true"
				:fields="aiFields" />

			<CnSettingsFieldsSection
				id="section-consultation"
				appId="dossiq"
				:name="t('dossiq', 'Consultation management')"
				:description="
					t(
						'dossiq',
						'Standards for advice requests to other departments and organisations.',
					)
				"
				docUrl="https://dossiq.conduction.nl/docs/n8n-consultation-workflows"
				:columns="2"
				valuesPath="config"
				:saveLabel="t('dossiq', 'Save')"
				:fields="consultationFields" />

			<CnSettingsSection
				id="section-search-index"
				:name="t('dossiq', 'Search index')"
				:description="
					t(
						'dossiq',
						'Cases, documents and contacts in the Dossiq search bar.',
					)
				"
				:loading="!storesReady">
				<SearchIndexTab v-if="storesReady" />
			</CnSettingsSection>
		</template>

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
					:sortable="true"
					:filterable="true"
					:columns="connectionColumns"
					:emptyText="
						t(
							'dossiq',
							'No connections reported yet. They appear once integriq has read dossiq\'s declaration.',
						)
					">
					<template #row-actions="{ row }">
						<CnRowActions v-if="row.settingsUrl" :actions="connectionRowActions" :row="row" />
					</template>
				</CnWidgetObjectTable>
			</CnSettingsSection>

			<CnSettingsFieldsSection
				id="section-kadaster"
				appId="dossiq"
				:name="t('dossiq', 'Set up Kadaster (BRK) and WOZ')"
				:openedFrom="t('dossiq', 'Kadaster (BRK) and WOZ')"
				:description="
					t(
						'dossiq',
						'Off until you choose a source. Off means: no request goes outside.',
					)
				"
				:columns="3"
				valuesPath="config"
				:fields="kadasterFields" />
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
				:actionsInTitle="true"
				:wide="true"
				:name="t('dossiq', 'Activities from the Omgevingsloket')"
				:description="t('dossiq', 'An application from the DSO becomes a case of the linked case type.')"
				:footerLink="ownerApps.integriq ? { label: t('dossiq', 'Manage the links in integriq'), href: integriqAdminUrl } : null">
				<template v-if="ownerApps.integriq" #actions>
					<NcButton :href="integriqAdminUrl" variant="secondary">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('dossiq', 'Link activity') }}
					</NcButton>
				</template>
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
					:endpointSource="intakeActivitySource"
					:rowClass="[{ when: { field: 'unmapped', value: true }, class: 'dq-dso-activity--unmapped' }]"
					:sortable="true"
					:filterable="true"
					:columns="intakeActivityColumns"
					:emptyText="t('dossiq', 'No activities linked yet.')" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-fee-schedules"
				:actionsInTitle="true"
				:wide="true"
				:name="t('dossiq', 'Fee rules')"
				:description="t('dossiq', 'Loaded at installation; adjusted per year.')"
				:footerLink="ownerApps.shillinq ? { label: t('dossiq', 'Manage fees in shillinq'), href: feeSchedulesUrl } : null">
				<template v-if="ownerApps.shillinq" #actions>
					<NcButton :href="feeSchedulesUrl" variant="secondary">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('dossiq', 'Add rule') }}
					</NcButton>
				</template>
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
					:sortable="true"
					:filterable="true"
					:columns="feeScheduleColumns"
					:emptyText="t('dossiq', 'No fee rules for dossiq yet.')" />
			</CnSettingsSection>

			<CnSettingsSection
				:actionsInTitle="true"
				:name="t('dossiq', 'Inspection checklists')"
				:loading="!storesReady">
				<template v-if="storesReady" #actions>
					<NcButton variant="secondary" @click="$refs.checklists.openEditor(null)">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('dossiq', 'Add checklist') }}
					</NcButton>
				</template>
				<ChecklistTemplatesTab v-if="storesReady" ref="checklists" :hideAddButton="true" />
			</CnSettingsSection>

			<CnSettingsSection
				id="section-enforcement-strategy"
				:name="t('dossiq', 'Enforcement strategy')">
				<CnFactsWidget :content="enforcementMatrixFacts" />
				<p>
					<a
						href="#section-enforcement-strategy"
						role="button"
						data-testid="enforcement-earlier-versions"
						:aria-expanded="String(showEarlierMatrices)"
						@click.prevent="showEarlierMatrices = !showEarlierMatrices">
						{{ showEarlierMatrices ? t('dossiq', 'Hide earlier versions') : t('dossiq', 'Earlier versions') }}
					</a>
				</p>
				<CnWidgetObjectTable
					v-if="showEarlierMatrices"
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

		<template #tab-advanced>
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

			<CnSettingsFieldsSection
				id="section-kcc"
				appId="dossiq"
				:name="t('dossiq', 'KCC-werkplek integration')"
				:description="
					t(
						'dossiq',
						'Burger identification, case-voorblad limits, sentiment trigger words, and belplan overflow thresholds for the KCC contact-center bridge.',
					)
				"
				:columns="2"
				valuesPath="config"
				:saveLabel="t('dossiq', 'Save KCC settings')"
				:fields="contactCentreFields" />

			<CnSettingsSection
				id="section-dmn"
				:name="t('dossiq', 'Decision tables (DMN)')"
				:loading="!storesReady">
				<p class="dq-settings-body-text">
					{{ t('dossiq', 'Decision tables live in the decision-tables register of OpenRegister. Dossiq stores its own tables there under keys that start with dossiq. and evaluates them from workflow steps and the enforcement recommendation. Open OpenRegister to read or change a table.') }}
				</p>
			</CnSettingsSection>

			<CnSettingsFieldsSection
				id="section-financial"
				appId="dossiq"
				:name="t('dossiq', 'Financial integration: dwangsom callback')"
				:description="
					t(
						'dossiq',
						'Configure the shared secret used to validate ERP payment-confirmation callbacks for dwangsom (penalty payment) uitbetalingen.',
					)
				"
				:detailedDescription="
					t(
						'dossiq',
						'Configure the shared secret used to validate the X-Procest-Signature HMAC-SHA256 header on the public dwangsom payment-confirmation callback ({endpoint}). Without a configured secret, every callback request is rejected (HTTP 401). An unconfigured secret is never treated as an implicit pass.',
						{ endpoint: '/apps/dossiq/api/dwangsom/payment-callback' },
					)
				"
				valuesPath="config"
				:fields="paymentCallbackFields" />

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

			<CnSettingsFieldsSection
				id="section-store"
				:name="t('dossiq', 'Store registry')"
				:description="
					t(
						'dossiq',
						'Connect the OpenRegister instance the Store page browses. Without one, dossiq stays offline and shows only its own templates.',
					)
				"
				url="/apps/dossiq/api/store/settings"
				:saveLabel="t('dossiq', 'Save store settings')"
				:fields="storeRegistryFields" />

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
				:description="
					t('dossiq', 'Case types, with their statuses, fields, messages and workflow, are managed on the Case types page.')
				">
				<NcButton :href="caseTypesUrl" variant="secondary">
					{{ t('dossiq', 'Open the case types') }}
				</NcButton>
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
				<DeadlineDefinitionsTab v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsFieldsSection
				id="section-queue-urgency"
				appId="dossiq"
				:name="t('dossiq', 'Queue urgency')"
				:description="
					t(
						'dossiq',
						'When a case counts as critical or almost due, and how much priority and time lying still weigh in the order.',
					)
				"
				:detailedDescription="
					t(
						'dossiq',
						'Decides the order of the cases assigned to someone. A case type can set the two thresholds itself.',
					)
				"
				:columns="2"
				valuesPath="config"
				:fields="queueUrgencyFields" />

			<CnSettingsSection
				:name="t('dossiq', 'Mandate matrix: administration')"
				:description="
					t(
						'dossiq',
						'Configure mandate decisions, organisational roles, role assignments, and import legacy mandate exports',
					)
				"
				:loading="!storesReady">
				<DelegationMatrixTab v-if="storesReady" />
			</CnSettingsSection>

			<CnSettingsFieldsSection
				id="section-archival"
				:name="t('dossiq', 'Archiving')"
				:description="
					t(
						'dossiq',
						'How often a reviewer is reminded of the archival decisions they hold. The setting lives in OpenRegister, which runs the archiving process; dossiq keeps no copy of it.',
					)
				"
				url="/apps/openregister/api/settings/archival"
				saveMethod="POST"
				:fields="archivalFields" />

			<CnSettingsFieldsSection
				id="section-woo-reports"
				appId="dossiq"
				:name="t('dossiq', 'Woo review reports')"
				:description="
					t(
						'dossiq',
						'Choose which Woo review reports your organisation uses, and who may read the throughput per reviewer.',
					)
				"
				:detailedDescription="
					t(
						'dossiq',
						'Both reports are off until you switch them on. The throughput report counts what each reviewer did per day, so only the group you name may read it.',
					)
				"
				valuesPath="config"
				:fields="reviewReportFields" />
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import {
	CnAdminSettingsShell,
	CnFactsWidget,
	CnRegisterMapping,
	CnRowActions,
	CnSettingsFieldsSection,
	CnSettingsSection,
	CnWidgetObjectTable,
	CnWorkingCalendarWidget,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SubstitutionAdmin from '../admin/SubstitutionAdmin.vue'
import ShippedConfiguration from './ShippedConfiguration.vue'
import StufAuditLog from './StufAuditLog.vue'
import StufEndpoints from './StufEndpoints.vue'
import ChecklistTemplatesTab from './tabs/ChecklistTemplatesTab.vue'
import DeadlineDefinitionsTab from './tabs/DeadlineDefinitionsTab.vue'
import DelegationMatrixTab from './tabs/DelegationMatrixTab.vue'
import FirstRunTab from './tabs/FirstRunTab.vue'
import PrerequisitesTab from './tabs/PrerequisitesTab.vue'
import SearchIndexTab from './tabs/SearchIndexTab.vue'
import TenantOnboardingTab from './tabs/TenantOnboardingTab.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
import manifest from '../../manifest.json'
import { fetchAdminTabCounts } from '../../services/adminTabCounts.js'
import { initializeStores } from '../../store/store.js'
import { feeRuleBasis, feeRuleCalculation, feeRuleName } from '../../utils/feeRules.js'
import {
	aiFields,
	archivalFields,
	backgroundAccountFields,
	CONNECTION_STATUS_TONES,
	consultationFields,
	contactCentreFields,
	houseStyleFields,
	kadasterFields,
	menuStructureFields,
	paymentCallbackFields,
	queueUrgencyFields,
	registerMappingGroups,
	reviewReportFields,
	sharedMailboxFields,
	storeRegistryFields,
	textsAndAccessFields,
} from './adminFields.js'
import { dsoActivityFormatters } from './dsoActivityFormatters.js'

export default {
	name: 'AdminRoot',
	components: {
		Plus,
		FirstRunTab,
		PrerequisitesTab,
		TenantOnboardingTab,
		CnAdminSettingsShell,
		CnFactsWidget,
		CnRegisterMapping,
		CnRowActions,
		CnSettingsFieldsSection,
		CnSettingsSection,
		CnWidgetObjectTable,
		CnWorkingCalendarWidget,
		ZgwMappingSettings,
		SearchIndexTab,
		ChecklistTemplatesTab,
		DeadlineDefinitionsTab,
		DelegationMatrixTab,
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
			// Cell formatters for the admin tables. A connection's title and
			// description arrive in English from integriq's registry, which
			// stores dossiq's declaration as written.
			cnFormatters: {
				translated: (value) =>
					typeof value === 'string' && value !== ''
						? this.t('dossiq', value)
						: value,

				// A shillinq fee rule: its name, its basis and its calculation in words.
				feeRuleName: (value, row) => feeRuleName(row),
				feeRuleBasis: (value, row) => feeRuleBasis(row),
				feeRuleCalculation: (value, row) => feeRuleCalculation(row, (text, vars) => this.t('dossiq', text, vars), getCanonicalLocale()),
			},
		}
	},

	data() {
		return {
			// Cell formatters for the tables on this page, handed to the shell.
			adminFormatters: dsoActivityFormatters,
			storesReady: false,
			registerConfiguration: {},
			registerSaving: false,
			activeTab: 'general',
			tabCounts: {},
			/** Whether the enforcement strategy card shows every matrix version. */
			showEarlierMatrices: false,
			ownerApps: loadState('dossiq', 'ownerApps', {
				integriq: true,
				shillinq: true,
			}),
		}
	},

	computed: {
		/**
		 * The tabs of the page, in the order the board draws them. Every
		 * section the page had before sits on one of them. Integrations and
		 * Map layers carry how many there are, once that is read.
		 *
		 * @return {Array<{id: string, label: string, count?: number}>} The tabs.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		adminTabs() {
			return this.withCounts([
				{ id: 'general', label: this.t('dossiq', 'General') },
				{ id: 'system', label: this.t('dossiq', 'System') },
				{
					id: 'integrations',
					label: this.t('dossiq', 'Integration settings'),
				},
				{ id: 'mail', label: this.t('dossiq', 'Mail') },
				{ id: 'map-layers', label: this.t('dossiq', 'Map layers') },
				{ id: 'vth', label: this.t('dossiq', 'VTH') },
				{ id: 'substitution', label: this.t('dossiq', 'Substitution') },
				// The settings set once or by a specialist: ZGW, KCC, StUF, Store,
				// first run, prerequisites, terms, mandates, archiving, reports.
				{ id: 'advanced', label: this.t('dossiq', 'Advanced') },
			])
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
			// The Algemeen board ends the path with the short note; the other
			// tabs say where the in-app settings are.
			if (this.activeTab === 'general') {
				return this.t('dossiq', 'applies to the whole organisation')
			}
			if (this.activeTab === 'advanced') {
				return this.t('dossiq', 'applies to the whole organisation; settings you set once or with a specialist')
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
		 * A connection row's menu: what the row itself offers, opening its
		 * settings card on this page.
		 *
		 * @return {Array<object>} The row actions.
		 * @spec openspec/changes/connection-row-menu/specs/admin-settings/spec.md#requirement-a-connection-row-has-a-menu-with-what-the-row-offers
		 */
		connectionRowActions() {
			return [
				{
					id: 'open-settings',
					label: this.t('dossiq', 'Open settings'),
					icon: 'CogOutline',
					href: (row) => row.settingsUrl,
					linkTarget: '_self',
				},
			]
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
					label: this.t('dossiq', 'Link'),
					cellClass: 'cn-cell--strong',
					formatter: 'translated',
					// A row opens its settings card on this page.
					widget: 'link',
					widgetProps: { href: '{settingsUrl}', target: '_self' },
					secondary: (row) =>
						row.description ? this.t('dossiq', row.description) : '',
				},
				{
					key: 'source',
					label: this.t('dossiq', 'Source'),
					resolve: { register: 'integriq', schema: 'source', labelField: 'name' },
				},
				{
					key: 'status',
					label: this.t('dossiq', 'Status'),
					widget: 'badge',
					widgetProps: { colorMap: CONNECTION_STATUS_TONES },
					enum: [
						'configured',
						'unconfigured',
						'unavailable',
						'simulated',
						'disabled',
						'error',
					],

					enumLabels: {
						configured: 'On',
						unconfigured: 'Off',
						unavailable: 'Not available',
						simulated: 'Simulated',
						disabled: 'Off',
						error: 'Error',
					},
				},
				{
					key: 'checkedAt',
					label: this.t('dossiq', 'Last contact'),
					formatter: 'relativeDate',
					formatterOptions: { time: true },
				},
			]
		},

		/**
		 * integriq's DSO activity overview: every mapping row with when an
		 * application last arrived for it, then the activities no row links yet.
		 *
		 * @return {object} The table's endpoint source.
		 * @spec openspec/changes/vth-dso-activity-board-columns/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
		 */
		intakeActivitySource() {
			return {
				url: '/apps/integriq/api/admin/dso-activities',
				responsePath: 'results',
			}
		},

		/**
		 * The DSO activity table's columns, as the board draws them.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/changes/vth-dso-activity-board-columns/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
		 */
		intakeActivityColumns() {
			return [
				{
					key: 'activityName',
					label: this.t('dossiq', 'DSO activity'),
					// The row's note and a switched-off row stay readable here.
					secondary: 'note',
					pills: [{ field: 'isActive', equals: false, label: this.t('dossiq', 'Inactive') }],
				},
				{
					key: 'caseTypes',
					label: this.t('dossiq', 'Becomes case type'),
					formatter: 'dsoCaseTypes',
					cellClass: 'dq-dso-activity__case-types',
				},
				{
					key: 'variant',
					valueField: 'caseTypes',
					label: this.t('dossiq', 'Variant'),
					formatter: 'dsoVariants',
				},
				{
					key: 'lastReceivedAt',
					label: this.t('dossiq', 'Last received'),
					formatter: 'relativeDate',
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
					key: 'name',
					label: this.t('dossiq', 'Rule'),
					formatter: 'feeRuleName',
				},
				{ key: 'legalBasis', label: this.t('dossiq', 'Basis'), formatter: 'feeRuleBasis' },
				{ key: 'calculation', label: this.t('dossiq', 'Amount'), formatter: 'feeRuleCalculation' },
				{ key: 'validFrom', label: this.t('dossiq', 'Valid from') },
			]
		},

		/**
		 * The Kadaster (BRK) and WOZ bindings IntegrationMode reads: the
		 * source, the service address and the key, which is written and never
		 * read back.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		kadasterFields() {
			return kadasterFields(this.t.bind(this))
		},

		/**
		 * Huisstijl: the organisation's name and logo are Nextcloud theming's,
		 * the theme is thematiq's brand for dossiq, and only the name staff know
		 * the app by is dossiq's own setting.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		houseStyleFields() {
			return houseStyleFields(this.t.bind(this))
		},

		/**
		 * Teksten en toegang: the sender of dossiq's messages and the text above
		 * Mijn zaken in the portal; which websites may call the portal is
		 * configured where the portal runs, so it is a pointer here.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		textsAndAccessFields() {
			return textsAndAccessFields(this.t.bind(this))
		},

		/**
		 * The menu structure choice.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		menuStructureFields() {
			return menuStructureFields(this.t.bind(this))
		},

		/**
		 * The queue urgency thresholds and weights.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		queueUrgencyFields() {
			return queueUrgencyFields(this.t.bind(this))
		},


		/**
		 * The consultation behaviour.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		consultationFields() {
			return consultationFields(this.t.bind(this))
		},

		/**
		 * The review report switches and their reader group.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		reviewReportFields() {
			return reviewReportFields(this.t.bind(this))
		},

		/**
		 * The payment callback secret.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		paymentCallbackFields() {
			return paymentCallbackFields(this.t.bind(this))
		},

		/**
		 * The store registry connection.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		storeRegistryFields() {
			return storeRegistryFields(this.t.bind(this))
		},

		/**
		 * OpenRegister's archival reminder interval.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		archivalFields() {
			return archivalFields(this.t.bind(this))
		},

		/**
		 * The register dossiq keeps its records in, and a schema per kind.
		 *
		 * @return {Array<object>} The groups.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		registerGroups() {
			return registerMappingGroups(this.t.bind(this))
		},

		/**
		 * The shared mailbox intake, matching and sender.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		sharedMailboxFields() {
			return sharedMailboxFields(this.t.bind(this))
		},

		/**
		 * The AI switches, model and connection test.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		aiFields() {
			return aiFields(this.t.bind(this))
		},

		/**
		 * The background service account and why it cannot be used.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		backgroundAccountFields() {
			return backgroundAccountFields(this.t.bind(this))
		},

		/**
		 * The KCC bridge behaviour.
		 *
		 * @return {Array<object>} The fields.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		contactCentreFields() {
			return contactCentreFields(this.t.bind(this))
		},

		/**
		 * The organisation's working calendar in OpenRegister: the default
		 * calendar dossiq's terms count on (`nl-national`).
		 *
		 * @return {object} The widget content.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		workingCalendarContent() {
			return {
				calendar: {
					register: 'flow-timers',
					schema: 'working-calendar',
					filter: { slug: 'nl-national' },
				},

				// The section's title row draws Add day.
				addButton: false,

				// DqInstellingenAlgemeen says what the red days mean under
				// the calendar, not under the card title.
				footnote: this.t(
					'dossiq',
					'Red: a holiday or closing day. Terms do not count these days. One calendar for the whole organisation.',
				),
			}
		},

		/**
		 * The active enforcement matrix as facts (DqVthInstellingen
		 * Handhavingsstrategie): its name, version and last change.
		 *
		 * @return {object} The facts widget content.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		enforcementMatrixFacts() {
			return {
				source: { register: 'dossiq', schema: 'lhsMatrix', filter: { active: true } },
				fields: [
					{ label: 'Matrix', path: 'name' },
					{ label: 'Version', path: 'version' },
					{ label: 'Last changed', path: '@self.updated', format: 'date' },
				],

				emptyText: 'No active enforcement matrix yet',
			}
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
		 * integriq's admin settings, where a DSO activity is linked to a case type.
		 *
		 * @return {string} The address.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		integriqAdminUrl() {
			return generateUrl('/settings/admin/integriq')
		},

		/**
		 * shillinq's fee schedules, where a fee rule is added.
		 *
		 * @return {string} The address.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		feeSchedulesUrl() {
			return generateUrl('/apps/shillinq/overheid/fee-schedules')
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

		/** @return {string} The Case types page, where case types are managed. */
		caseTypesUrl() {
			return generateUrl('/apps/dossiq/settings/case-types')
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async created() {
		fetchAdminTabCounts().then((counts) => {
			this.tabCounts = counts
		})
		this.loadRegisterConfiguration()
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Add the known counts to the tabs.
		 *
		 * @param {Array<{id: string, label: string}>} tabs The tabs.
		 * @return {Array<{id: string, label: string, count?: number}>} The tabs with their counts.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		withCounts(tabs) {
			return tabs.map((tab) => (typeof this.tabCounts[tab.id] === 'number'
				? { ...tab, count: this.tabCounts[tab.id] }
				: tab))
		},

		/**
		 * Read the register and schema ids from dossiq's settings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async loadRegisterConfiguration() {
			try {
				const { data } = await axios.get(generateUrl('/apps/dossiq/api/settings'))
				this.registerConfiguration = data?.config ?? {}
			} catch {
				this.registerConfiguration = {}
			}
		},

		/**
		 * Save the register and schema ids, keeping the ids the app config
		 * has always held.
		 *
		 * @param {object} configuration The register and schema ids by key.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async saveRegisterMapping(configuration) {
			this.registerSaving = true
			try {
				const { data } = await axios.put(generateUrl('/apps/dossiq/api/settings'), configuration)
				this.registerConfiguration = data?.config ?? configuration
				showSuccess(this.t('dossiq', 'Configuration saved'))
			} catch (error) {
				showError(error?.response?.data?.message || this.t('dossiq', 'That change could not be saved.'))
			} finally {
				this.registerSaving = false
			}
		},

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

<style>
/* A card whose text is its body, drawn as body text under the title
   (DqBeheerGeavanceerd "Beslistabellen (DMN)"). */
.dq-settings-body-text {
	margin: 0;
	font-size: 15px;
	line-height: 1.5;
	color: var(--color-main-text);
}

/* An activity no mapping links yet: its case type cell reads as a warning. */
.dq-dso-activity--unmapped .dq-dso-activity__case-types {
	color: var(--color-error-text);
	font-weight: 600;
}
</style>
