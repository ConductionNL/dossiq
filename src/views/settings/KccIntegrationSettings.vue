<template>
	<div class="kcc-settings">
		<NcNoteCard type="info">
			{{ t('procest', 'Configure how the KCC-werkplek bridge identifies burgers, opens the case-voorblad, scores sentiment, and routes calls. DigiD authentication and the telephony screen-pop are delivered by OpenConnector and pipelinq respectively; only the Procest-side behaviour is configured here.') }}
		</NcNoteCard>

		<div class="setting-row">
			<NcSelect
				v-model="identificationMethodOption"
				:input-label="t('procest', 'Identification method')"
				:options="identificationMethodOptions"
				:disabled="!writable || loading"
				:clearable="false" />
			<p class="setting-help">
				{{ t('procest', 'Whether burgers are identified via DigiD (portaal/chat), identificatievragen (telefoon), or both.') }}
			</p>
		</div>

		<div class="setting-row">
			<label for="kcc_identification_score_threshold">{{ t('procest', 'Identification score threshold (0.6 - 1.0)') }}</label>
			<NcInputField
				id="kcc_identification_score_threshold"
				v-model="form.identification_score_threshold"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'0.8'" />
			<p class="setting-help">
				{{ t('procest', 'Minimum identificatievragen match score to link a burger and reveal full zaaksinfo. Below the threshold, only openbare zaaksinformatie is shown.') }}
			</p>
		</div>

		<div class="setting-row">
			<label for="kcc_sentiment_polling_interval">{{ t('procest', 'Sentiment polling interval (seconds)') }}</label>
			<NcInputField
				id="kcc_sentiment_polling_interval"
				v-model="form.sentiment_polling_interval"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'5'" />
		</div>

		<div class="setting-row">
			<label for="kcc_specialist_availability_polling_interval">{{ t('procest', 'Specialist availability polling interval (seconds)') }}</label>
			<NcInputField
				id="kcc_specialist_availability_polling_interval"
				v-model="form.specialist_availability_polling_interval"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'30'" />
		</div>

		<div class="setting-row">
			<label for="kcc_max_zaken_voorblad">{{ t('procest', 'Max open zaken in voorblad') }}</label>
			<NcInputField
				id="kcc_max_zaken_voorblad"
				v-model="form.max_zaken_voorblad"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'10'" />
		</div>

		<div class="setting-row">
			<label for="kcc_max_contactmomenten_history">{{ t('procest', 'Max contactmomenten in history') }}</label>
			<NcInputField
				id="kcc_max_contactmomenten_history"
				v-model="form.max_contactmomenten_history"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'5'" />
		</div>

		<div class="setting-row">
			<label for="kcc_belplan_overflow_threshold_wachttijd">{{ t('procest', 'Belplan overflow threshold — wachttijd (seconds)') }}</label>
			<NcInputField
				id="kcc_belplan_overflow_threshold_wachttijd"
				v-model="form.belplan_overflow_threshold_wachttijd"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'180'" />
		</div>

		<div class="setting-row">
			<label for="kcc_belplan_overflow_threshold_wachtrij_lengte">{{ t('procest', 'Belplan overflow threshold — wachtrij lengte') }}</label>
			<NcInputField
				id="kcc_belplan_overflow_threshold_wachtrij_lengte"
				v-model="form.belplan_overflow_threshold_wachtrij_lengte"
				type="number"
				:disabled="!writable || loading"
				:placeholder="'5'" />
		</div>

		<div class="setting-row">
			<label for="kcc_sentiment_trigger_words">{{ t('procest', 'Sentiment trigger words (one per line)') }}</label>
			<textarea
				id="kcc_sentiment_trigger_words"
				v-model="triggerWordsText"
				class="kcc-settings__textarea"
				:disabled="!writable || loading"
				rows="6"
				:placeholder="'klacht\nadvocaat\nwethouder\nmedia'" />
			<p class="setting-help">
				{{ t('procest', 'Dutch words that flag negative sentiment and trigger an escalation recommendation. One word or phrase per line.') }}
			</p>
		</div>

		<div class="kcc-settings__actions">
			<NcButton
				type="primary"
				:disabled="!writable || saving || loading"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ saving ? t('procest', 'Saving...') : t('procest', 'Save KCC settings') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="result" :type="result.type">
			{{ result.message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcInputField, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { triggerWordsToText, textToTriggerWords } from '../../utils/kccTriggerWords.js'

/**
 * KCC-werkplek bridge admin settings.
 *
 * Reads and writes the KCC config keys through the generic Procest settings
 * endpoint (GET /api/settings, POST /api/settings). DigiD and the telephony
 * screen-pop live in OpenConnector / pipelinq and are not configured here.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md
 */
export default {
	name: 'KccIntegrationSettings',
	components: { NcButton, NcInputField, NcLoadingIcon, NcNoteCard, NcSelect },
	data() {
		return {
			loading: true,
			saving: false,
			writable: true,
			result: null,
			triggerWordsText: '',
			form: {
				identification_method: 'both',
				identification_score_threshold: '0.8',
				sentiment_polling_interval: '5',
				specialist_availability_polling_interval: '30',
				max_zaken_voorblad: '10',
				max_contactmomenten_history: '5',
				belplan_overflow_threshold_wachttijd: '180',
				belplan_overflow_threshold_wachtrij_lengte: '5',
				sentiment_trigger_words: '[]',
			},
			identificationMethodOptions: [
				{ id: 'digid', label: 'DigiD' },
				{ id: 'bsn_questions', label: t('procest', 'Identificatievragen') },
				{ id: 'both', label: t('procest', 'Both') },
			],
		}
	},
	computed: {
		/** @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md */
		identificationMethodOption: {
			get() {
				return this.identificationMethodOptions.find(o => o.id === this.form.identification_method)
					|| this.identificationMethodOptions[2]
			},
			set(option) {
				this.form.identification_method = option ? option.id : 'both'
			},
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/** @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md */
		async load() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/procest/api/settings'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					const config = (data && data.config) ? data.config : {}
					this.writable = data && data.isAdmin === true
					Object.keys(this.form).forEach(key => {
						if (config[key] !== undefined && config[key] !== null) {
							this.form[key] = String(config[key])
						}
					})
					this.triggerWordsText = triggerWordsToText(this.form.sentiment_trigger_words)
				}
			} catch (error) {
				// Non-fatal: defaults stay in place if the endpoint is unreachable.
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/specs.md */
		async save() {
			this.saving = true
			this.result = null
			try {
				const payload = {
					...this.form,
					sentiment_trigger_words: textToTriggerWords(this.triggerWordsText),
				}
				const response = await fetch(generateUrl('/apps/procest/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(payload),
				})
				if (response.ok) {
					this.result = { type: 'success', message: t('procest', 'KCC instellingen opgeslagen') }
					await this.load()
				} else {
					this.result = { type: 'error', message: t('procest', 'Could not save KCC settings.') }
				}
			} catch (error) {
				this.result = { type: 'error', message: error.message || t('procest', 'Could not save KCC settings.') }
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.kcc-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.setting-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.kcc-settings__textarea {
	width: 100%;
	font-family: var(--font-face-mono, monospace);
}

.kcc-settings__actions {
	display: flex;
	gap: 8px;
}
</style>
