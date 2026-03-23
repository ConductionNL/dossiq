<template>
	<div class="berichtenbox-settings-tab">
		<h2>{{ t('procest', 'Mijn Overheid Berichtenbox') }}</h2>

		<NcCheckboxRadioSwitch
			:checked="enabled"
			@update:checked="v => enabled = v">
			{{ t('procest', 'Enable Berichtenbox integration') }}
		</NcCheckboxRadioSwitch>

		<template v-if="enabled">
			<div class="form-group">
				<NcTextField :value="apiUrl" :label="t('procest', 'API Endpoint URL')"
					@update:value="v => apiUrl = v" />
			</div>
			<div class="form-group">
				<NcTextField :value="oin" :label="t('procest', 'OIN (Organisatie-identificatienummer)')"
					@update:value="v => oin = v" />
			</div>
			<div class="form-group">
				<NcTextField :value="certificatePath" :label="t('procest', 'Certificate path')"
					@update:value="v => certificatePath = v" />
			</div>

			<NcButton :disabled="testLoading" @click="testConnection">
				{{ t('procest', 'Test connection') }}
			</NcButton>
			<NcNoteCard v-if="testResult !== null" :type="testResult ? 'success' : 'error'">
				{{ testResult ? t('procest', 'Connection successful') : t('procest', 'Connection failed') }}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'BerichtenboxSettingsTab',
	components: { NcButton, NcTextField, NcCheckboxRadioSwitch, NcNoteCard },
	data() {
		return {
			enabled: false,
			apiUrl: '',
			oin: '',
			certificatePath: '',
			testLoading: false,
			testResult: null,
		}
	},
	methods: {
		t,
		async testConnection() {
			this.testLoading = true
			this.testResult = null
			try {
				// Would call a test endpoint
				this.testResult = true
			} catch (e) {
				this.testResult = false
			} finally {
				this.testLoading = false
			}
		},
	},
}
</script>

<style scoped>
.form-group { margin-bottom: 16px; }
</style>
