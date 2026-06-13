<template>
	<div class="procest-admin">
		<!-- Version Information -->
		<CnVersionInfoCard
			:app-name="'Procest'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('procest', 'Version Information')"
			:description="t('procest', 'Information about the current Procest installation')">
			<template #actions>
				<NcButton type="primary"
					:disabled="reimporting"
					@click="reimport">
					<template #icon>
						<NcLoadingIcon v-if="reimporting" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ reimporting ? t('procest', 'Importing...') : t('procest', 'Re-import configuration') }}
				</NcButton>
			</template>
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('procest', 'Support') }}</h4>
					<p>{{ t('procest', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
					<p>{{ t('procest', 'For a Service Level Agreement (SLA), contact') }} <a href="mailto:sales@conduction.nl">sales@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings />

		<CnSettingsSection
			:name="t('procest', 'Case Type Management')"
			:description="t('procest', 'Manage case types and their configurations')"
			:loading="!storesReady">
			<CaseTypeAdmin v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Map Layers')"
			:description="t('procest', 'Configure GIS map layers for case location views (WMS, WFS, PDOK)')"
			:loading="!storesReady">
			<MapLayerSettings v-if="storesReady" />
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
			:name="t('procest', 'Archief — Retention Rules')"
			:description="t('procest', 'Define per-zaaktype retention periods that drive scheduled e-Depot handover (BagIt + MDTO)')"
			:loading="!storesReady">
			<ArchiefConfiguratieTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'Archief — Pipeline Settings')"
			:description="t('procest', 'GiHandover/MDTO archival pipeline: batch concurrency, e-Depot adapter, proof of transfer.')"
			:loading="!storesReady">
			<ArchiefSettingsTab v-if="storesReady" />
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

		<!-- Re-import Status -->
		<div v-if="message" class="actions-section">
			<NcNoteCard :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { CnSettingsSection, CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Settings from './Settings.vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
import MapLayerSettings from './MapLayerSettings.vue'
import AiSettingsTab from './tabs/AiSettingsTab.vue'
import ChecklistsTab from './tabs/ChecklistsTab.vue'
import TermijnDefinitiesTab from './tabs/TermijnDefinitiesTab.vue'
import MandaatMatrixTab from './tabs/MandaatMatrixTab.vue'
import ArchiefConfiguratieTab from './tabs/ArchiefConfiguratieTab.vue'
import ArchiefSettingsTab from './tabs/ArchiefSettingsTab.vue'
import MandaatMatrixSettingsTab from './tabs/MandaatMatrixSettingsTab.vue'
import ConsultationSettingsTab from './tabs/ConsultationSettingsTab.vue'
import EmailSettings from './EmailSettings.vue'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnSettingsSection,
		CnVersionInfoCard,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
		Settings,
		CaseTypeAdmin,
		ZgwMappingSettings,
		MapLayerSettings,
		AiSettingsTab,
		ChecklistsTab,
		TermijnDefinitiesTab,
		MandaatMatrixTab,
		ArchiefConfiguratieTab,
		ArchiefSettingsTab,
		MandaatMatrixSettingsTab,
		ConsultationSettingsTab,
		EmailSettings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: loadState('procest', 'version', 'Unknown'),
			reimporting: false,
			message: '',
			messageType: 'success',
		}
	},
	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async created() {
		await initializeStores()
		this.storesReady = true
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async reimport() {
			this.reimporting = true
			this.message = ''

			try {
				const response = await fetch(generateUrl('/apps/procest/api/settings/load'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				const data = await response.json()

				if (data.success) {
					this.message = t('procest', 'Configuration re-imported successfully')
					this.messageType = 'success'
				} else {
					this.message = data.message || t('procest', 'Re-import failed')
					this.messageType = 'error'
				}
			} catch (error) {
				this.message = error.message || t('procest', 'Re-import failed')
				this.messageType = 'error'
			} finally {
				this.reimporting = false
			}
		},
	},
}
</script>

<style scoped>
.procest-admin {
	max-width: 900px;
}
</style>
