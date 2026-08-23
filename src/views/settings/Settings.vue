<template>
	<CnSettingsSection
		:name="t('dossiq', 'Configuration')"
		:description="t('dossiq', 'Register and schema settings')"
		docUrl="https://dossiq.conduction.nl/docs/intro"
		:loading="loading">
		<template #actions>
			<NcButton type="primary" @click="save">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</template>

		<div class="settings-form">
			<div class="form-group">
				<label>{{ t('dossiq', 'Register') }}</label>
				<NcTextField
					:modelValue="form.register"
					:label="t('dossiq', 'Register')"
					@update:modelValue="(v) => (form.register = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Case schema') }}</label>
				<NcTextField
					:modelValue="form.case_schema"
					:label="t('dossiq', 'Case schema')"
					@update:modelValue="(v) => (form.case_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Task schema') }}</label>
				<NcTextField
					:modelValue="form.task_schema"
					:label="t('dossiq', 'Task schema')"
					@update:modelValue="(v) => (form.task_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Status schema') }}</label>
				<NcTextField
					:modelValue="form.status_schema"
					:label="t('dossiq', 'Status schema')"
					@update:modelValue="(v) => (form.status_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Role schema') }}</label>
				<NcTextField
					:modelValue="form.role_schema"
					:label="t('dossiq', 'Role schema')"
					@update:modelValue="(v) => (form.role_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Result schema') }}</label>
				<NcTextField
					:modelValue="form.result_schema"
					:label="t('dossiq', 'Result schema')"
					@update:modelValue="(v) => (form.result_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Decision schema') }}</label>
				<NcTextField
					:modelValue="form.decision_schema"
					:label="t('dossiq', 'Decision schema')"
					@update:modelValue="(v) => (form.decision_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Case type schema') }}</label>
				<NcTextField
					:modelValue="form.case_type_schema"
					:label="t('dossiq', 'Case type schema')"
					@update:modelValue="(v) => (form.case_type_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('dossiq', 'Status type schema') }}</label>
				<NcTextField
					:modelValue="form.status_type_schema"
					:label="t('dossiq', 'Status type schema')"
					@update:modelValue="(v) => (form.status_type_schema = v)" />
			</div>
		</div>

		<p v-if="saved" class="success-message">
			{{ t('dossiq', 'Configuration saved') }}
		</p>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		CnSettingsSection,
		NcButton,
		NcTextField,
	},

	data() {
		return {
			form: {
				register: '',
				case_schema: '',
				task_schema: '',
				status_schema: '',
				role_schema: '',
				result_schema: '',
				decision_schema: '',
				case_type_schema: '',
				status_type_schema: '',
			},

			saved: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		settingsStore() {
			return useSettingsStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		loading() {
			return this.settingsStore.isLoading
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.form = { ...this.form, ...config }
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async save() {
			this.saved = false
			const result = await this.settingsStore.saveSettings(this.form)
			if (result) {
				this.saved = true
				setTimeout(() => {
					this.saved = false
				}, 3000)
			}
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.success-message {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
