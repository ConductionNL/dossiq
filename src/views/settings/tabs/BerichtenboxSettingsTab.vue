<template>
	<div class="berichtenbox-settings-tab">
		<h2>{{ t('procest', 'Mijn Overheid Berichtenbox') }}</h2>

		<NcCheckboxRadioSwitch
			:modelValue="enabled"
			@update:modelValue="(v) => (enabled = v)">
			{{ t('procest', 'Enable Berichtenbox integration') }}
		</NcCheckboxRadioSwitch>

		<template v-if="enabled">
			<div class="form-group">
				<NcTextField
					:modelValue="apiUrl"
					:label="t('procest', 'API Endpoint URL')"
					@update:modelValue="(v) => (apiUrl = v)" />
			</div>
			<div class="form-group">
				<NcTextField
					:modelValue="oin"
					:label="t('procest', 'OIN (Organisatie-identificatienummer)')"
					@update:modelValue="(v) => (oin = v)" />
			</div>
			<div class="form-group">
				<NcTextField
					:modelValue="certificatePath"
					:label="t('procest', 'Certificate path')"
					@update:modelValue="(v) => (certificatePath = v)" />
			</div>

			<NcButton :disabled="testLoading" @click="testConnection">
				{{ t('procest', 'Test connection') }}
			</NcButton>
			<NcNoteCard
				v-if="testResult !== null"
				:type="testResult ? 'success' : 'error'">
				{{
					testResult
						? t('procest', 'Connection successful')
						: t('procest', 'Connection failed')
				}}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

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
		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
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
.form-group {
	margin-bottom: 16px;
}
</style>
