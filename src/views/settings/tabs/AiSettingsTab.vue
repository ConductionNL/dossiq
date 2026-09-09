<template>
	<div class="ai-settings-tab">
		<h2>{{ t('dossiq', 'AI-Assisted Processing') }}</h2>

		<NcLoadingIcon v-if="loadState === 'loading'" :size="32" />

		<!--
			Nothing is drawn until the stored settings are in hand, and a failed
			load says so rather than falling back to defaults. A switch is a
			two-state control with no way to render "we do not know", so drawing
			one before the answer arrives means inventing a state — and the
			invented state was ON, for all six features and for PII stripping.
		-->
		<NcNoteCard v-else-if="loadState === 'error'" type="error">
			{{
				t(
					'dossiq',
					'We could not load the AI settings, so none are shown. Reload the page to try again.',
				)
			}}
		</NcNoteCard>

		<template v-else>
			<!-- Global toggle -->
			<div class="ai-settings-tab__section">
				<NcCheckboxRadioSwitch
					:modelValue="settings.ai_enabled"
					@update:modelValue="(v) => updateSetting('ai_enabled', v)">
					{{ t('dossiq', 'Enable AI-assisted processing') }}
				</NcCheckboxRadioSwitch>
			</div>

			<template v-if="settings.ai_enabled">
				<!-- Model configuration -->
				<div class="ai-settings-tab__section">
					<h3>{{ t('dossiq', 'Model Configuration') }}</h3>

					<div class="form-group">
						<label>{{ t('dossiq', 'Model type') }}</label>
						<NcCheckboxRadioSwitch
							:modelValue="settings.ai_model_type === 'local'"
							type="radio"
							name="model_type"
							@update:modelValue="
								() => updateSetting('ai_model_type', 'local')
							">
							{{ t('dossiq', 'Local (Ollama)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:modelValue="settings.ai_model_type === 'cloud'"
							type="radio"
							name="model_type"
							@update:modelValue="
								() => updateSetting('ai_model_type', 'cloud')
							">
							{{ t('dossiq', 'Cloud') }}
						</NcCheckboxRadioSwitch>
					</div>

					<NcNoteCard
						v-if="settings.ai_model_type === 'cloud'"
						type="warning">
						{{
							t(
								'dossiq',
								'Warning: Case data will be sent to an external service. Ensure this complies with your data processing agreements.',
							)
						}}
					</NcNoteCard>

					<div class="form-group">
						<NcTextField
							:modelValue="settings.ai_model_url"
							:label="t('dossiq', 'Model endpoint URL')"
							@update:modelValue="
								(v) => updateSetting('ai_model_url', v)
							" />
					</div>

					<div class="form-group">
						<NcTextField
							:modelValue="settings.ai_model_name"
							:label="t('dossiq', 'Model name')"
							placeholder="llama3.1"
							@update:modelValue="
								(v) => updateSetting('ai_model_name', v)
							" />
					</div>

					<div
						v-if="settings.ai_model_type === 'cloud'"
						class="form-group">
						<NcPasswordField
							:modelValue="apiKeyInput"
							:label="t('dossiq', 'API Key')"
							@update:modelValue="(v) => updateApiKey(v)" />
						<!--
							The key itself is never sent to the browser, so the
							field is always empty and cannot say by itself
							whether one is stored. The server answers
							ai_api_key_set for exactly this, and it used to be
							computed and then thrown away.
						-->
						<p class="form-group__hint">
							{{
								settings.ai_api_key_set
									? t(
											'dossiq',
											'You have a key stored. Type a new one to replace it.',
										)
									: t('dossiq', 'You have no key stored.')
							}}
						</p>
					</div>
				</div>

				<!-- Feature toggles -->
				<div class="ai-settings-tab__section">
					<h3>{{ t('dossiq', 'Features') }}</h3>
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
					<h3>{{ t('dossiq', 'Privacy & Compliance') }}</h3>
					<NcCheckboxRadioSwitch
						:modelValue="settings.ai_pii_stripping"
						@update:modelValue="
							(v) => updateSetting('ai_pii_stripping', v)
						">
						{{
							t(
								'dossiq',
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
								'dossiq',
								'DPIA (Data Protection Impact Assessment) has been completed',
							)
						}}
					</NcCheckboxRadioSwitch>
					<NcNoteCard v-if="!settings.ai_dpia_acknowledged" type="warning">
						{{
							t(
								'dossiq',
								'A DPIA is required before you use AI features with personal data. Until you acknowledge it here, every AI feature stays off.',
							)
						}}
					</NcNoteCard>
				</div>

				<!-- Health check -->
				<div class="ai-settings-tab__section">
					<h3>{{ t('dossiq', 'Connection Test') }}</h3>
					<NcButton :disabled="healthLoading" @click="testHealth">
						{{ t('dossiq', 'Test connection') }}
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
			/**
			 * The stored settings, or null until they have been read.
			 *
			 * There are deliberately NO seeded values here. This object used to
			 * be pre-filled with `true` for all six feature toggles and for
			 * `ai_pii_stripping`, and `mounted()` merged the response over the
			 * top of it. The response was read as `response.settings` while the
			 * endpoint answered the settings flat, so the merge was always a
			 * merge of `{}` and the seeded `true`s were what the administrator
			 * saw — every switch on, whatever was actually stored. A privacy
			 * control that misreports its own state is worse than an absent one,
			 * because it stops anyone looking further.
			 */
			settings: null,

			/** 'loading' | 'ready' | 'error'. */
			loadState: 'loading',

			/** The API key being typed. The stored key never leaves the server. */
			apiKeyInput: '',

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
					label: t('dossiq', 'Document classification'),
				},
				{
					key: 'ai_feature_extraction',
					label: t('dossiq', 'Data extraction'),
				},
				{ key: 'ai_feature_qa', label: t('dossiq', 'Knowledge base Q&A') },
				{
					key: 'ai_feature_summary',
					label: t('dossiq', 'Auto-summarization'),
				},
				{
					key: 'ai_feature_routing',
					label: t('dossiq', 'Routing suggestions'),
				},
				{
					key: 'ai_feature_decision_support',
					label: t('dossiq', 'Decision support'),
				},
			]
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
	async mounted() {
		try {
			const response = await getAiSettings()
			if (!response?.settings || typeof response.settings !== 'object') {
				// A body that does not carry a settings object is a failure,
				// not an empty result. Reading it as "nothing is configured"
				// is exactly how the previous shape mismatch stayed invisible.
				this.loadState = 'error'
				return
			}
			this.settings = response.settings
			this.loadState = 'ready'
		} catch {
			this.loadState = 'error'
		}
	},

	methods: {
		t,
		/**
		 * @param {string} key The key.
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		async updateSetting(key, value) {
			const previous = this.settings[key]
			this.settings[key] = value
			try {
				await updateAiSettings({ [key]: value })
			} catch {
				// Put the switch back. Leaving it where the click left it
				// reports a setting the server never accepted — the same class
				// of lie as showing a state that was never read.
				this.settings[key] = previous
			}
		},

		/**
		 * Store a new API key.
		 *
		 * Tracked separately from `settings`, which never receives the stored
		 * key: the server answers `ai_api_key_set` and withholds the value.
		 *
		 * @param {string} value The key typed by the administrator.
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		async updateApiKey(value) {
			this.apiKeyInput = value
			try {
				await updateAiSettings({ ai_api_key: value })
				this.settings.ai_api_key_set = value !== ''
			} catch {
				// Leave ai_api_key_set reporting what the server last confirmed.
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
						e.response?.data?.error || t('dossiq', 'Connection failed'),
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

.form-group__hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
