<template>
	<CnSettingsSection
		:name="t('procest', 'Configuration')"
		:description="t('procest', 'Register and schema settings')"
		doc-url="https://procest.conduction.nl/docs/intro"
		:loading="loading">
		<template #actions>
			<NcButton type="primary" @click="save">
				{{ t('procest', 'Save') }}
			</NcButton>
		</template>

		<div class="settings-form">
			<div class="form-group">
				<label>{{ t('procest', 'Register') }}</label>
				<NcTextField
					:model-value="form.register"
					:label="t('procest', 'Register')"
					@update:model-value="(v) => (form.register = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Case schema') }}</label>
				<NcTextField
					:model-value="form.case_schema"
					:label="t('procest', 'Case schema')"
					@update:model-value="(v) => (form.case_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Task schema') }}</label>
				<NcTextField
					:model-value="form.task_schema"
					:label="t('procest', 'Task schema')"
					@update:model-value="(v) => (form.task_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Status schema') }}</label>
				<NcTextField
					:model-value="form.status_schema"
					:label="t('procest', 'Status schema')"
					@update:model-value="(v) => (form.status_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Role schema') }}</label>
				<NcTextField
					:model-value="form.role_schema"
					:label="t('procest', 'Role schema')"
					@update:model-value="(v) => (form.role_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Result schema') }}</label>
				<NcTextField
					:model-value="form.result_schema"
					:label="t('procest', 'Result schema')"
					@update:model-value="(v) => (form.result_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Decision schema') }}</label>
				<NcTextField
					:model-value="form.decision_schema"
					:label="t('procest', 'Decision schema')"
					@update:model-value="(v) => (form.decision_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Case type schema') }}</label>
				<NcTextField
					:model-value="form.case_type_schema"
					:label="t('procest', 'Case type schema')"
					@update:model-value="(v) => (form.case_type_schema = v)" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Status type schema') }}</label>
				<NcTextField
					:model-value="form.status_type_schema"
					:label="t('procest', 'Status type schema')"
					@update:model-value="(v) => (form.status_type_schema = v)" />
			</div>
		</div>

		<p v-if="saved" class="success-message">
			{{ t('procest', 'Configuration saved') }}
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
