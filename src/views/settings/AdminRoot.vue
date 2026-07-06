<template>
	<CnAdminSettingsShell
		app-id="procest"
		app-name="Procest"
		@reimported="onReimported">
		<Settings />

		<CnSettingsSection
			:name="t('procest', 'Case Type Management')"
			:description="t('procest', 'Manage case types and their configurations')"
			:loading="!storesReady">
			<CaseTypeAdmin v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'ZGW API Mapping')"
			:description="t('procest', 'Configure property mappings between English OpenRegister fields and Dutch ZGW API fields')"
			:loading="!storesReady">
			<ZgwMappingSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'VTH Inspection Checklists')"
			:description="t('procest', 'Configure reusable inspection checklists for VTH cases (Toezicht). Checklists are versioned and linked to case types.')"
			:loading="!storesReady">
			<ChecklistsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'AI-Assisted Processing')"
			:description="t('procest', 'Configure AI features for document classification, data extraction, Q&A, summarization, routing and decision support')"
			:loading="!storesReady">
			<AiSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'AWB Term Definitions')"
			:description="t('procest', 'Configure statutory term definitions per zaaktype for AWB termijnbewaking (legal basis, duration, validity). Versioning is enforced on save.')"
			:loading="!storesReady">
			<TermijnDefinitiesTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Mandate Matrix — Administration')"
			:description="t('procest', 'Configure mandate decisions, organisational roles, role assignments, and import legacy mandate exports')"
			:loading="!storesReady">
			<MandaatMatrixTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Mandate Matrix — System Settings')"
			:description="t('procest', 'Awb art. 10:3 mandate administration: Decidesk import, role hierarchy, waarnemer assignments.')"
			:loading="!storesReady">
			<MandaatMatrixSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Consultation Management')"
			:description="t('procest', 'Adviesaanvragen: advisory body registry, mandatory-gate config, n8n webhook contracts and external response settings.')"
			:loading="!storesReady">
			<ConsultationSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Case Email — Shared Mailbox')"
			:description="t('procest', 'Shared functional mailbox ingest (IMAP) and transport for case correspondence. Outbound mail and per-user accounts are owned by Nextcloud Mail.')"
			:loading="!storesReady">
			<EmailSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'KCC-werkplek Integration')"
			:description="t('procest', 'Burger identification, case-voorblad limits, sentiment trigger words, and belplan overflow thresholds for the KCC contact-center bridge.')"
			:loading="!storesReady">
			<KccIntegrationSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'StUF-ZKN Endpoints')"
			:description="t('procest', 'Outbound StUF-ZKN/BG zaaksysteem endpoints per gemeente, with per-endpoint circuit-breaker health. Endpoints, WSSE credentials and mTLS certificates are managed by the platform operator.')"
			:loading="!storesReady">
			<StufEndpoints v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'StUF-ZKN Audit Log')"
			:description="t('procest', 'Per-call audit log for outbound and inbound StUF SOAP envelopes (full XML, HTTP status, duration, retry history).')"
			:loading="!storesReady">
			<StufAuditLog v-if="storesReady" />
		</CnSettingsSection>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
import AiSettingsTab from './tabs/AiSettingsTab.vue'
import ChecklistsTab from './tabs/ChecklistsTab.vue'
import TermijnDefinitiesTab from './tabs/TermijnDefinitiesTab.vue'
import MandaatMatrixTab from './tabs/MandaatMatrixTab.vue'
import MandaatMatrixSettingsTab from './tabs/MandaatMatrixSettingsTab.vue'
import ConsultationSettingsTab from './tabs/ConsultationSettingsTab.vue'
import EmailSettings from './EmailSettings.vue'
import KccIntegrationSettings from './KccIntegrationSettings.vue'
import StufEndpoints from './StufEndpoints.vue'
import StufAuditLog from './StufAuditLog.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
		Settings,
		CaseTypeAdmin,
		ZgwMappingSettings,
		AiSettingsTab,
		ChecklistsTab,
		TermijnDefinitiesTab,
		MandaatMatrixTab,
		MandaatMatrixSettingsTab,
		ConsultationSettingsTab,
		EmailSettings,
		KccIntegrationSettings,
		StufEndpoints,
		StufAuditLog,
	},
	data() {
		return {
			storesReady: false,
		}
	},
	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async created() {
		await initializeStores()
		this.storesReady = true
	},
	methods: {
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
