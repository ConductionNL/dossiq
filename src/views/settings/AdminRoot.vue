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
			:name="t('procest', 'Parafeerroutes')"
			:description="t('procest', 'Configure parafeerroutes for B&W decision-making workflow')"
			:loading="!storesReady">
			<ParafeerRouteAdmin v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('procest', 'ZGW API Mapping')"
			:description="t('procest', 'Configure property mappings between English OpenRegister fields and Dutch ZGW API fields')"
			:loading="!storesReady">
			<ZgwMappingSettings v-if="storesReady" />
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
import ParafeerRouteAdmin from './ParafeerRouteAdmin.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
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
		ParafeerRouteAdmin,
		ZgwMappingSettings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: document.getElementById('procest-settings')?.dataset?.version || 'Unknown',
			reimporting: false,
			message: '',
			messageType: 'success',
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
	methods: {
		async reimport() {
			this.reimporting = true
			this.message = ''

			try {
				const response = await fetch('/apps/procest/api/settings/load', {
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
