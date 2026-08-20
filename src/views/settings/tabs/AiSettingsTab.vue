<template>
	<div class="ai-settings-tab">
		<h2>{{ t('procest', 'AI-Assisted Processing') }}</h2>

		<!-- Global toggle -->
		<div class="ai-settings-tab__section">
			<NcCheckboxRadioSwitch
				:modelValue="settings.ai_enabled"
				@update:modelValue="(v) => updateSetting('ai_enabled', v)">
				{{ t('procest', 'Enable AI-assisted processing') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template v-if="settings.ai_enabled">
			<!-- Model configuration -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('procest', 'Model Configuration') }}</h3>

				<div class="form-group">
					<label>{{ t('procest', 'Model type') }}</label>
					<NcCheckboxRadioSwitch
						:modelValue="settings.ai_model_type === 'local'"
						type="radio"
						name="model_type"
						@update:modelValue="
							() => updateSetting('ai_model_type', 'local')
						">
						{{ t('procest', 'Local (Ollama)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:modelValue="settings.ai_model_type === 'cloud'"
						type="radio"
						name="model_type"
						@update:modelValue="
							() => updateSetting('ai_model_type', 'cloud')
						">
						{{ t('procest', 'Cloud') }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcNoteCard v-if="settings.ai_model_type === 'cloud'" type="warning">
					{{
						t(
							'procest',
							'Warning: Case data will be sent to an external service. Ensure this complies with your data processing agreements.',
						)
					}}
				</NcNoteCard>

				<div class="form-group">
					<NcTextField
						:modelValue="settings.ai_model_url"
						:label="t('procest', 'Model endpoint URL')"
						@update:modelValue="
							(v) => updateSetting('ai_model_url', v)
						" />
				</div>

				<div class="form-group">
					<NcTextField
						:modelValue="settings.ai_model_name"
						:label="t('procest', 'Model name')"
						placeholder="llama3.1"
						@update:modelValue="
							(v) => updateSetting('ai_model_name', v)
						" />
				</div>

				<div v-if="settings.ai_model_type === 'cloud'" class="form-group">
					<NcPasswordField
						:modelValue="settings.ai_api_key"
						:label="t('procest', 'API Key')"
						@update:modelValue="(v) => updateSetting('ai_api_key', v)" />
				</div>
			</div>

			<!-- Feature toggles -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('procest', 'Features') }}</h3>
				<NcCheckboxRadioSwitch
					v-for="feature in featureToggles"
					:key="feature.key"
					:modelValue="settings[feature.key]"
					@update:modelValue="(v) => updateSetting(feature.key, v)">
					{{ feature.label }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Privacy -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('procest', 'Privacy & Compliance') }}</h3>
				<NcCheckboxRadioSwitch
					:modelValue="settings.ai_pii_stripping"
					@update:modelValue="(v) => updateSetting('ai_pii_stripping', v)">
					{{
						t(
							'procest',
							'Strip PII (BSN, financial data) from AI prompts',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="settings.ai_dpia_acknowledged"
					@update:modelValue="
						(v) => updateSetting('ai_dpia_acknowledged', v)
					">
					{{
						t(
							'procest',
							'DPIA (Data Protection Impact Assessment) has been completed',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="!settings.ai_dpia_acknowledged" type="warning">
					{{
						t(
							'procest',
							'A DPIA is required before using AI features with personal data. This must be acknowledged before AI features can be activated.',
						)
					}}
				</NcNoteCard>
			</div>

			<!-- Health check -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('procest', 'Connection Test') }}</h3>
				<NcButton :disabled="healthLoading" @click="testHealth">
					{{ t('procest', 'Test connection') }}
				</NcButton>
				<NcLoadingIcon v-if="healthLoading" :size="20" />
				<NcNoteCard
					v-if="healthResult"
					:type="healthResult.healthy ? 'success' : 'error'">
					{{ healthResult.message }}
					<template v-if="healthResult.responseTimeMs">
						({{ healthResult.responseTimeMs }}ms)
					</template>
				</NcNoteCard>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import {
	getAiSettings,
	testAiHealth,
	updateAiSettings,
} from '../../../services/aiApi.js'

export default {
	name: 'AiSettingsTab',
	components: {
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
	},

	data() {
		return {
			settings: {
				ai_enabled: false,
				ai_model_type: 'local',
				ai_model_url: '',
				ai_model_name: '',
				ai_api_key: '',
				ai_feature_classification: true,
				ai_feature_extraction: true,
				ai_feature_qa: true,
				ai_feature_summary: true,
				ai_feature_routing: true,
				ai_feature_decision_support: true,
				ai_pii_stripping: true,
				ai_dpia_acknowledged: false,
			},

			healthLoading: false,
			healthResult: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		featureToggles() {
			return [
				{
					key: 'ai_feature_classification',
					label: t('procest', 'Document classification'),
				},
				{
					key: 'ai_feature_extraction',
					label: t('procest', 'Data extraction'),
				},
				{ key: 'ai_feature_qa', label: t('procest', 'Knowledge base Q&A') },
				{
					key: 'ai_feature_summary',
					label: t('procest', 'Auto-summarization'),
				},
				{
					key: 'ai_feature_routing',
					label: t('procest', 'Routing suggestions'),
				},
				{
					key: 'ai_feature_decision_support',
					label: t('procest', 'Decision support'),
				},
			]
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
	async mounted() {
		try {
			const response = await getAiSettings()
			this.settings = { ...this.settings, ...(response.settings || {}) }
		} catch (e) {
			// Use defaults
		}
	},

	methods: {
		t,
		/**
		 * @param key
		 * @param value
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		async updateSetting(key, value) {
			this.settings[key] = value
			try {
				await updateAiSettings({ [key]: value })
			} catch (e) {
				// Revert on failure would go here
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async testHealth() {
			this.healthLoading = true
			this.healthResult = null
			try {
				this.healthResult = await testAiHealth()
			} catch (e) {
				this.healthResult = {
					healthy: false,
					message:
						e.response?.data?.error || t('procest', 'Connection failed'),
				}
			} finally {
				this.healthLoading = false
			}
		},
	},
}
</script>

<style scoped>
.ai-settings-tab__section {
	margin-bottom: 24px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
