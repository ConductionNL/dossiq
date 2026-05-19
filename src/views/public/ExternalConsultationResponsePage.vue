<template>
	<div class="external-consultation-page">
		<!-- Loading -->
		<div v-if="loading" class="external-consultation-page__loading">
			<div class="external-consultation-page__spinner" />
			<p>{{ t('procest', 'Loading consultation details...') }}</p>
		</div>

		<!-- Error / invalid token -->
		<div v-else-if="error" class="external-consultation-page__error-page">
			<div class="external-consultation-page__icon external-consultation-page__icon--error">
				&#9888;
			</div>
			<h2>{{ t('procest', 'Link not valid') }}</h2>
			<p>{{ error }}</p>
			<p class="external-consultation-page__help">
				{{ t('procest', 'If you believe this is an error, please contact the requesting organization.') }}
			</p>
		</div>

		<!-- Success (submitted) -->
		<div v-else-if="submitted" class="external-consultation-page__success">
			<div class="external-consultation-page__icon external-consultation-page__icon--success">
				&#10003;
			</div>
			<h2>{{ t('procest', 'Response received') }}</h2>
			<p>{{ t('procest', 'Your advice has been successfully submitted. Thank you.') }}</p>
			<p v-if="consultation" class="external-consultation-page__ref">
				{{ t('procest', 'Reference: {number}', { number: consultation.consultationNumber || '' }) }}
			</p>
		</div>

		<!-- Response form -->
		<div v-else-if="consultation" class="external-consultation-page__form-page">
			<header class="external-consultation-page__header">
				<h1>{{ t('procest', 'Consultation request') }}</h1>
				<span class="external-consultation-page__number">
					{{ consultation.consultationNumber }}
				</span>
			</header>

			<!-- Consultation details -->
			<section class="external-consultation-page__section">
				<h2>{{ t('procest', 'Details') }}</h2>
				<dl class="external-consultation-page__details">
					<div>
						<dt>{{ t('procest', 'Subject') }}</dt>
						<dd>{{ consultation.onderwerp }}</dd>
					</div>
					<div v-if="consultation.vraagstelling">
						<dt>{{ t('procest', 'Questions') }}</dt>
						<dd class="external-consultation-page__question">
							{{ consultation.vraagstelling }}
						</dd>
					</div>
					<div v-if="consultation.uiterlijkeReactiedatum">
						<dt>{{ t('procest', 'Response deadline') }}</dt>
						<dd :class="{ 'external-consultation-page__overdue': isOverdue }">
							{{ formatDate(consultation.uiterlijkeReactiedatum) }}
							<span v-if="isOverdue">
								({{ t('procest', 'overdue') }})
							</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- Response form -->
			<section class="external-consultation-page__section">
				<h2>{{ t('procest', 'Your advice') }}</h2>

				<!-- Advice type -->
				<div class="external-consultation-page__field">
					<label>{{ t('procest', 'Advice outcome') }} *</label>
					<select v-model="form.advies" class="external-consultation-page__select">
						<option value="positief">
							{{ t('procest', 'Positive') }}
						</option>
						<option value="positief_met_voorwaarden">
							{{ t('procest', 'Positive with conditions') }}
						</option>
						<option value="negatief">
							{{ t('procest', 'Negative') }}
						</option>
						<option value="niet_van_toepassing">
							{{ t('procest', 'Not applicable') }}
						</option>
					</select>
				</div>

				<!-- Explanation -->
				<div v-if="form.advies !== 'niet_van_toepassing'" class="external-consultation-page__field">
					<label>{{ t('procest', 'Explanation') }} *</label>
					<textarea
						v-model="form.toelichting"
						rows="5"
						class="external-consultation-page__textarea"
						:placeholder="t('procest', 'Provide a clear explanation...')" />
				</div>

				<!-- Conditions -->
				<div v-if="form.advies === 'positief_met_voorwaarden'" class="external-consultation-page__field">
					<label>{{ t('procest', 'Conditions') }}</label>
					<div v-for="(c, idx) in form.voorwaarden" :key="idx" class="external-consultation-page__condition">
						<span>{{ c.beschrijving }}</span>
						<button class="external-consultation-page__remove-btn" @click="form.voorwaarden.splice(idx, 1)">
							&times;
						</button>
					</div>
					<div class="external-consultation-page__add-condition">
						<input
							v-model="newCondition"
							type="text"
							class="external-consultation-page__input"
							:placeholder="t('procest', 'Describe a condition...')"
							@keydown.enter.prevent="addCondition" />
						<button class="external-consultation-page__btn" @click="addCondition">
							{{ t('procest', 'Add') }}
						</button>
					</div>
				</div>

				<!-- Date -->
				<div class="external-consultation-page__field">
					<label>{{ t('procest', 'Advice date') }} *</label>
					<input
						v-model="form.datum"
						type="date"
						class="external-consultation-page__input" />
				</div>

				<!-- Validation error -->
				<div v-if="validationError" class="external-consultation-page__validation-error">
					{{ validationError }}
				</div>

				<!-- Submit -->
				<div class="external-consultation-page__submit">
					<button
						class="external-consultation-page__btn external-consultation-page__btn--primary"
						:disabled="saving"
						@click="submit">
						{{ saving ? t('procest', 'Submitting...') : t('procest', 'Submit advice') }}
					</button>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ExternalConsultationResponsePage',

	data() {
		return {
			loading: true,
			submitted: false,
			saving: false,
			error: null,
			consultation: null,
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
		token() {
			return this.$route?.params?.token || ''
		},

		isOverdue() {
			if (!this.consultation?.uiterlijkeReactiedatum) return false
			return new Date(this.consultation.uiterlijkeReactiedatum) < new Date()
		},
	},

	mounted() {
		this.loadConsultation()
	},

	methods: {
		t,

		async loadConsultation() {
			this.loading = true
			try {
				const url = generateUrl(`/apps/procest/api/public/consultations/${encodeURIComponent(this.token)}`)
				const { data } = await axios.get(url)
				this.consultation = data
			} catch (err) {
				if (err.response?.status === 403 || err.response?.status === 404) {
					this.error = t('procest', 'This link is no longer valid or has expired.')
				} else {
					this.error = t('procest', 'Could not load consultation details. Please try again later.')
				}
			} finally {
				this.loading = false
			}
		},

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

		async submit() {
			this.validationError = null

			if (this.form.advies !== 'niet_van_toepassing' && !this.form.toelichting.trim()) {
				this.validationError = t('procest', 'Explanation is required for this advice type')
				return
			}

			if (!this.form.datum) {
				this.validationError = t('procest', 'Advice date is required')
				return
			}

			this.saving = true
			try {
				const url = generateUrl(`/apps/procest/api/public/consultations/${encodeURIComponent(this.token)}`)
				await axios.post(url, { ...this.form })
				this.submitted = true
			} catch (err) {
				if (err.response?.status === 403) {
					this.error = t('procest', 'This link is no longer valid or has expired.')
				} else {
					this.validationError = t('procest', 'Could not submit your advice. Please try again.')
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.external-consultation-page {
	max-width: 720px;
	margin: 40px auto;
	padding: 24px;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	color: #333;
}

.external-consultation-page__loading {
	text-align: center;
	padding: 48px;
}

.external-consultation-page__spinner {
	width: 40px;
	height: 40px;
	border: 3px solid #ddd;
	border-top-color: #0082c9;
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
	margin: 0 auto 16px;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.external-consultation-page__icon {
	font-size: 3rem;
	margin-bottom: 16px;
}

.external-consultation-page__icon--error {
	color: #c62828;
}

.external-consultation-page__icon--success {
	color: #2e7d32;
}

.external-consultation-page__error-page,
.external-consultation-page__success {
	text-align: center;
	padding: 48px 24px;
}

.external-consultation-page__help {
	color: #666;
	margin-top: 16px;
}

.external-consultation-page__header {
	display: flex;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 24px;
	border-bottom: 2px solid #0082c9;
	padding-bottom: 12px;
}

.external-consultation-page__number {
	font-size: 0.875rem;
	color: #666;
	font-weight: 600;
}

.external-consultation-page__section {
	margin-bottom: 32px;
}

.external-consultation-page__details {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 16px;
}

.external-consultation-page__details dt {
	font-weight: 600;
	color: #555;
}

.external-consultation-page__question {
	white-space: pre-wrap;
}

.external-consultation-page__overdue {
	color: #c62828;
	font-weight: 600;
}

.external-consultation-page__field {
	margin-bottom: 20px;
}

.external-consultation-page__field label {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
}

.external-consultation-page__select,
.external-consultation-page__input,
.external-consultation-page__textarea {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-size: 0.9375rem;
}

.external-consultation-page__textarea {
	min-height: 100px;
	resize: vertical;
}

.external-consultation-page__condition {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 10px;
	border: 1px solid #ddd;
	border-radius: 4px;
	margin-bottom: 6px;
	font-size: 0.875rem;
}

.external-consultation-page__remove-btn {
	background: none;
	border: none;
	cursor: pointer;
	color: #c62828;
	font-size: 1.2rem;
	padding: 0 4px;
}

.external-consultation-page__add-condition {
	display: flex;
	gap: 8px;
}

.external-consultation-page__btn {
	padding: 8px 16px;
	background: #e0e0e0;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 0.9375rem;
}

.external-consultation-page__btn--primary {
	background: #0082c9;
	color: white;
	padding: 10px 24px;
	font-size: 1rem;
}

.external-consultation-page__btn--primary:hover:not(:disabled) {
	background: #006ca5;
}

.external-consultation-page__btn:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.external-consultation-page__submit {
	margin-top: 24px;
}

.external-consultation-page__validation-error {
	background: #fce4ec;
	color: #c62828;
	border-radius: 4px;
	padding: 8px 12px;
	font-size: 0.875rem;
	margin-bottom: 12px;
}

.external-consultation-page__ref {
	color: #666;
	font-size: 0.875rem;
}
</style>
