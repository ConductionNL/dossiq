<template>
	<div class="consultation-response-form">
		<h5 class="consultation-response-form__title">
			{{ t('procest', 'Submit advice response') }}
		</h5>

		<!-- Consultation context -->
		<div class="consultation-response-form__context">
			<p>
				<strong>{{ t('procest', 'Question:') }}</strong>
				{{ consultation.vraagstelling }}
			</p>
			<p>
				<strong>{{ t('procest', 'Deadline:') }}</strong>
				{{ formatDate(consultation.uiterlijkeReactiedatum) }}
			</p>
		</div>

		<!-- Advice type -->
		<div class="consultation-response-form__field">
			<label class="consultation-response-form__label">
				{{ t('procest', 'Advice outcome') }} *
			</label>
			<NcSelect
				:value="selectedAdvies"
				:options="adviesOptions"
				:input-label="t('procest', 'Select advice type')"
				label="label"
				track-by="value"
				@update:value="v => form.advies = v ? v.value : 'positief'" />
		</div>

		<!-- Explanation (required except niet_van_toepassing) -->
		<div
			v-if="form.advies !== 'niet_van_toepassing'"
			class="consultation-response-form__field">
			<label class="consultation-response-form__label">
				{{ t('procest', 'Explanation') }} *
			</label>
			<textarea
				v-model="form.toelichting"
				class="consultation-response-form__textarea"
				rows="4"
				:placeholder="t('procest', 'Provide a clear explanation of your advice')" />
		</div>

		<!-- Conditions (only for positief_met_voorwaarden) -->
		<div
			v-if="form.advies === 'positief_met_voorwaarden'"
			class="consultation-response-form__field">
			<label class="consultation-response-form__label">
				{{ t('procest', 'Conditions') }}
			</label>
			<div
				v-for="(condition, idx) in form.voorwaarden"
				:key="idx"
				class="consultation-response-form__condition">
				<span>{{ condition.beschrijving }}</span>
				<NcButton
					type="tertiary"
					:aria-label="t('procest', 'Remove condition')"
					@click="removeCondition(idx)">
					{{ t('procest', 'Remove') }}
				</NcButton>
			</div>
			<div class="consultation-response-form__add-condition">
				<NcTextField
					:value="newCondition"
					:label="t('procest', 'New condition')"
					:placeholder="t('procest', 'Describe a condition...')"
					@update:value="v => newCondition = v"
					@keydown.enter.prevent="addCondition" />
				<NcButton type="secondary" :disabled="!newCondition.trim()" @click="addCondition">
					{{ t('procest', 'Add') }}
				</NcButton>
			</div>
		</div>

		<!-- Advice date -->
		<div class="consultation-response-form__field">
			<label class="consultation-response-form__label">
				{{ t('procest', 'Advice date') }} *
			</label>
			<input
				v-model="form.datum"
				class="consultation-response-form__date"
				type="date" />
		</div>

		<!-- Error -->
		<div v-if="validationError" class="consultation-response-form__error">
			{{ validationError }}
		</div>

		<!-- Actions -->
		<div class="consultation-response-form__actions">
			<NcButton :disabled="saving" @click="$emit('cancel')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving"
				@click="submit">
				{{ saving ? t('procest', 'Submitting...') : t('procest', 'Submit advice') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ConsultationResponseForm',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},

	props: {
		consultation: {
			type: Object,
			required: true,
		},
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['submit', 'cancel'],

	data() {
		return {
			newCondition: '',
			validationError: null,
			form: {
				advies: 'positief',
				toelichting: '',
				voorwaarden: [],
				datum: new Date().toISOString().split('T')[0],
			},
		}
	},

	computed: {
		adviesOptions() {
			return [
				{ value: 'positief', label: t('procest', 'Positive') },
				{ value: 'positief_met_voorwaarden', label: t('procest', 'Positive with conditions') },
				{ value: 'negatief', label: t('procest', 'Negative') },
				{ value: 'niet_van_toepassing', label: t('procest', 'Not applicable') },
			]
		},

		selectedAdvies() {
			return this.adviesOptions.find(o => o.value === this.form.advies) || null
		},
	},

	methods: {
		t,

		formatDate(dateStr) {
			if (!dateStr) return '---'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString('nl-NL')
		},

		addCondition() {
			if (!this.newCondition.trim()) return
			this.form.voorwaarden.push({
				beschrijving: this.newCondition.trim(),
				prioriteit: 'normaal',
			})
			this.newCondition = ''
		},

		removeCondition(idx) {
			this.form.voorwaarden.splice(idx, 1)
		},

		submit() {
			this.validationError = null

			if (this.form.advies !== 'niet_van_toepassing' && !this.form.toelichting.trim()) {
				this.validationError = t('procest', 'Explanation is required for this advice type')
				return
			}

			if (!this.form.datum) {
				this.validationError = t('procest', 'Advice date is required')
				return
			}

			this.$emit('submit', { ...this.form })
		},
	},
}
</script>

<style scoped>
.consultation-response-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.consultation-response-form__title {
	font-size: 1rem;
	font-weight: 600;
	margin: 0;
}

.consultation-response-form__context {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 12px;
	font-size: 0.875rem;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-response-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-response-form__label {
	font-size: 0.875rem;
	font-weight: 600;
}

.consultation-response-form__textarea,
.consultation-response-form__date {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__textarea {
	min-height: 80px;
	resize: vertical;
}

.consultation-response-form__condition {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 0.875rem;
}

.consultation-response-form__add-condition {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.consultation-response-form__error {
	background: var(--color-error-soft, #fce4ec);
	color: var(--color-error, #c62828);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	font-size: 0.875rem;
}

.consultation-response-form__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	padding-top: 8px;
}
</style>
