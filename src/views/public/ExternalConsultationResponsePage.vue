<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="external-consultation-response">
		<!-- Loading state -->
		<div v-if="loading" class="external-consultation-response__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('procest', 'Consultatie gegevens laden...') }}</p>
		</div>

		<!-- Error state -->
		<div v-else-if="loadError" class="external-consultation-response__error">
			<h2>{{ t('procest', 'Niet gevonden') }}</h2>
			<p>{{ loadError }}</p>
		</div>

		<!-- Success state (after submission) -->
		<div v-else-if="submitted" class="external-consultation-response__success">
			<div class="external-consultation-response__success-icon">✓</div>
			<h2>{{ t('procest', 'Advies ingediend') }}</h2>
			<p>{{ t('procest', 'Uw advies is succesvol ontvangen. U kunt dit venster sluiten.') }}</p>
		</div>

		<!-- Consultation data + response form -->
		<div v-else-if="consultationData" class="external-consultation-response__content">
			<header class="external-consultation-response__header">
				<h1>{{ t('procest', 'Adviesverzoek') }}</h1>
				<p class="external-consultation-response__organization">
					{{ t('procest', 'Van:') }} {{ consultationData.aanvragendeOrganisatie || t('procest', 'Gemeente') }}
				</p>
			</header>

			<!-- Consultation details -->
			<section class="external-consultation-response__section">
				<h2>{{ t('procest', 'Details consultatie') }}</h2>
				<dl class="external-consultation-response__details">
					<div>
						<dt>{{ t('procest', 'Onderwerp') }}</dt>
						<dd>{{ consultationData.onderwerp }}</dd>
					</div>
					<div v-if="consultationData.vraagstelling">
						<dt>{{ t('procest', 'Vraagstelling') }}</dt>
						<dd class="external-consultation-response__question">
							{{ consultationData.vraagstelling }}
						</dd>
					</div>
					<div>
						<dt>{{ t('procest', 'Gevraagd door') }}</dt>
						<dd>{{ consultationData.aanvragendeAfdeling || '—' }}</dd>
					</div>
					<div>
						<dt>{{ t('procest', 'Deadline') }}</dt>
						<dd :class="{ 'external-consultation-response__deadline--overdue': isDeadlinePassed }">
							{{ formatDate(consultationData.uiterlijkeReactiedatum) }}
							<span v-if="isDeadlinePassed" class="external-consultation-response__overdue-label">
								({{ t('procest', 'verlopen') }})
							</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- Response form (inline, no dialog) -->
			<section class="external-consultation-response__section">
				<h2>{{ t('procest', 'Uw advies') }}</h2>

				<div class="external-consultation-response__form">
					<div class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Advies') }} *
						</label>
						<NcSelect
							v-model="responseForm.advies"
							:options="adviesOptions"
							:aria-label-combobox="t('procest', 'Adviestype')"
							label="label"
							:reduce="opt => opt.value"
							:placeholder="t('procest', 'Selecteer adviestype')" />
					</div>

					<div v-if="responseForm.advies !== null" class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Toelichting') }}
							<span v-if="toelichtingRequired">*</span>
						</label>
						<textarea
							v-model="responseForm.toelichting"
							class="external-consultation-response__textarea"
							rows="5"
							:placeholder="t('procest', 'Geef een toelichting op uw advies...')" />
					</div>

					<!-- Conditions block for positief_met_voorwaarden -->
					<div v-if="responseForm.advies === 'positief_met_voorwaarden'" class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Voorwaarden') }}
						</label>
						<div
							v-for="(voorwaarde, idx) in responseForm.voorwaarden"
							:key="idx"
							class="external-consultation-response__condition-row">
							<input
								v-model="voorwaarde.description"
								class="external-consultation-response__condition-input"
								:placeholder="t('procest', 'Beschrijving voorwaarde')"
								type="text" />
							<NcSelect
								v-model="voorwaarde.priority"
								:options="priorityOptions"
								:aria-label-combobox="t('procest', 'Prioriteit voorwaarde {n}', { n: idx + 1 })"
								label="label"
								:reduce="opt => opt.value"
								class="external-consultation-response__condition-priority"
								:placeholder="t('procest', 'Prioriteit')" />
							<NcButton
								type="tertiary"
								:title="t('procest', 'Verwijder voorwaarde')"
								@click="removeVoorwaarde(idx)">
								✕
							</NcButton>
						</div>
						<NcButton @click="addVoorwaarde">
							{{ t('procest', 'Voorwaarde toevoegen') }}
						</NcButton>
					</div>

					<div class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Datum advies') }} *
						</label>
						<input
							v-model="responseForm.datum"
							type="date"
							class="external-consultation-response__date-input" />
					</div>

					<NcNoteCard v-if="submitError" type="error">
						{{ submitError }}
					</NcNoteCard>

					<div class="external-consultation-response__form-actions">
						<NcButton
							type="primary"
							:disabled="!canSubmit || submitting"
							@click="submitResponse">
							{{ submitting ? t('procest', 'Bezig...') : t('procest', 'Advies indienen') }}
						</NcButton>
					</div>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ExternalConsultationResponsePage',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},
	props: {
		token: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: true,
			loadError: '',
			submitError: '',
			submitting: false,
			submitted: false,
			consultationData: null,
			responseForm: {
				advies: null,
				toelichting: '',
				voorwaarden: [],
				datum: new Date().toISOString().slice(0, 10),
			},
			adviesOptions: [
				{ label: this.t('procest', 'Positief'), value: 'positief' },
				{ label: this.t('procest', 'Positief met voorwaarden'), value: 'positief_met_voorwaarden' },
				{ label: this.t('procest', 'Negatief'), value: 'negatief' },
				{ label: this.t('procest', 'Niet van toepassing'), value: 'niet_van_toepassing' },
			],
			priorityOptions: [
				{ label: this.t('procest', 'Hoog'), value: 'hoog' },
				{ label: this.t('procest', 'Normaal'), value: 'normaal' },
				{ label: this.t('procest', 'Laag'), value: 'laag' },
			],
		}
	},
	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		toelichtingRequired() {
			return this.responseForm.advies !== 'niet_van_toepassing'
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		isDeadlinePassed() {
			if (!this.consultationData?.uiterlijkeReactiedatum) return false
			return new Date(this.consultationData.uiterlijkeReactiedatum) < new Date()
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		canSubmit() {
			if (!this.responseForm.advies) return false
			if (this.toelichtingRequired && this.responseForm.toelichting.trim() === '') return false
			if (!this.responseForm.datum) return false
			return true
		},
	},
	async mounted() {
		await this.loadConsultation()
	},
	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		async loadConsultation() {
			this.loading = true
			this.loadError = ''
			try {
				const response = await axios.get(`/apps/procest/api/public/consultations/${encodeURIComponent(this.token)}`)
				this.consultationData = response.data
			} catch (err) {
				this.loadError = this.t('procest', 'Consultatie niet gevonden of link is verlopen.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		addVoorwaarde() {
			this.responseForm.voorwaarden.push({ description: '', priority: 'normaal' })
		},
		/**
		 * @param idx
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06
		 */
		removeVoorwaarde(idx) {
			this.responseForm.voorwaarden.splice(idx, 1)
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		async submitResponse() {
			this.submitError = ''
			if (!this.canSubmit) return
			this.submitting = true
			try {
				await axios.post(
					`/apps/procest/api/public/consultations/${encodeURIComponent(this.token)}`,
					{
						advies: this.responseForm.advies,
						toelichting: this.responseForm.toelichting.trim(),
						voorwaarden: this.responseForm.advies === 'positief_met_voorwaarden'
							? [...this.responseForm.voorwaarden]
							: [],
						datum: this.responseForm.datum,
					},
				)
				this.submitted = true
			} catch (err) {
				this.submitError = this.t('procest', 'Indienen mislukt. Probeer het opnieuw.')
			} finally {
				this.submitting = false
			}
		},
		/**
		 * @param dateStr
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06
		 */
		formatDate(dateStr) {
			if (!dateStr) return '—'
			const d = new Date(dateStr)
			if (isNaN(d.getTime())) return dateStr
			return d.toLocaleDateString('nl-NL', { year: 'numeric', month: 'long', day: 'numeric' })
		},
	},
}
</script>

<style scoped>
.external-consultation-response {
	max-width: 720px;
	margin: 0 auto;
	padding: 24px 16px;
}

.external-consultation-response__loading,
.external-consultation-response__error {
	text-align: center;
	padding: 48px;
}

.external-consultation-response__success {
	text-align: center;
	padding: 48px;
}

.external-consultation-response__success-icon {
	font-size: 64px;
	color: var(--color-success, #2e7d32);
	margin-bottom: 16px;
}

.external-consultation-response__header {
	margin-bottom: 24px;
}

.external-consultation-response__header h1 {
	margin: 0 0 4px;
}

.external-consultation-response__organization {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.external-consultation-response__section {
	margin-bottom: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.external-consultation-response__section h2 {
	margin: 0 0 12px;
	font-size: 1.1em;
}

.external-consultation-response__details {
	display: grid;
	gap: 12px;
}

.external-consultation-response__details dt {
	font-weight: 600;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.external-consultation-response__details dd {
	margin: 0;
}

.external-consultation-response__question {
	white-space: pre-wrap;
}

.external-consultation-response__deadline--overdue {
	color: var(--color-error);
}

.external-consultation-response__overdue-label {
	font-size: 0.85em;
}

.external-consultation-response__form {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.external-consultation-response__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.external-consultation-response__label {
	font-weight: 600;
	font-size: 0.9em;
}

.external-consultation-response__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
	font-size: 14px;
	font-family: inherit;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.external-consultation-response__date-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.external-consultation-response__condition-row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 6px;
}

.external-consultation-response__condition-input {
	flex: 1;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.external-consultation-response__condition-priority {
	width: 140px;
	flex-shrink: 0;
}

.external-consultation-response__form-actions {
	display: flex;
	justify-content: flex-end;
}
</style>
