<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="external-consultation-response">
		<!-- Loading state -->
		<div v-if="loading" class="external-consultation-response__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('procest', 'Loading consultation data...') }}</p>
		</div>

		<!-- Error state -->
		<div v-else-if="loadError" class="external-consultation-response__error">
			<h2>{{ t('procest', 'Not found') }}</h2>
			<p>{{ loadError }}</p>
		</div>

		<!-- Success state (after submission) -->
		<div v-else-if="submitted" class="external-consultation-response__success">
			<div class="external-consultation-response__success-icon">✓</div>
			<h2>{{ t('procest', 'Advice submitted') }}</h2>
			<p>
				{{
					t(
						'procest',
						'Your advice has been received successfully. You can close this window.',
					)
				}}
			</p>
		</div>

		<!-- Consultation data + response form -->
		<div
			v-else-if="consultationData"
			class="external-consultation-response__content">
			<header class="external-consultation-response__header">
				<h1>{{ t('procest', 'Advice request') }}</h1>
				<p class="external-consultation-response__organization">
					{{ t('procest', 'From:') }}
					{{
						consultationData.aanvragendeOrganisatie
						|| t('procest', 'Municipality')
					}}
				</p>
			</header>

			<!-- Consultation details -->
			<section class="external-consultation-response__section">
				<h2>{{ t('procest', 'Consultation details') }}</h2>
				<dl class="external-consultation-response__details">
					<div>
						<dt>{{ t('procest', 'Onderwerp') }}</dt>
						<dd>{{ consultationData.subject }}</dd>
					</div>
					<div v-if="consultationData.question_formulation">
						<dt>{{ t('procest', 'Question') }}</dt>
						<dd class="external-consultation-response__question">
							{{ consultationData.question_formulation }}
						</dd>
					</div>
					<div>
						<dt>{{ t('procest', 'Requested by') }}</dt>
						<dd>{{ consultationData.aanvragendeAfdeling || '—' }}</dd>
					</div>
					<div>
						<dt>{{ t('procest', 'Deadline') }}</dt>
						<dd
							:class="{
								'external-consultation-response__deadline--overdue':
									isDeadlinePassed,
							}">
							{{ formatDate(consultationData.latestResponseDate) }}
							<span
								v-if="isDeadlinePassed"
								class="external-consultation-response__overdue-label">
								({{ t('procest', 'expired') }})
							</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- Response form (inline, no dialog) -->
			<section class="external-consultation-response__section">
				<h2>{{ t('procest', 'Your advice') }}</h2>

				<div class="external-consultation-response__form">
					<div class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Advice') }} *
						</label>
						<NcSelect
							v-model="responseForm.advies"
							:options="adviesOptions"
							:aria-label-combobox="t('procest', 'Advice type')"
							label="label"
							:reduce="(opt) => opt.value"
							:placeholder="t('procest', 'Select advice type')" />
					</div>

					<div
						v-if="responseForm.advies !== null"
						class="external-consultation-response__field">
						<label
							class="external-consultation-response__label"
							for="external-consultation-response-toelichting">
							{{ t('procest', 'Explanation') }}
							<span v-if="toelichtingRequired">*</span>
						</label>
						<textarea
							id="external-consultation-response-toelichting"
							v-model="responseForm.notes"
							class="external-consultation-response__textarea"
							rows="5"
							:placeholder="
								t(
									'procest',
									'Provide an explanation for your advice...',
								)
							" />
					</div>

					<!-- Conditions block for positief_met_voorwaarden -->
					<div
						v-if="responseForm.advies === 'positief_with_terms'"
						class="external-consultation-response__field">
						<label class="external-consultation-response__label">
							{{ t('procest', 'Conditions') }}
						</label>
						<div
							v-for="(voorwaarde, idx) in responseForm.terms"
							:key="idx"
							class="external-consultation-response__condition-row">
							<input
								v-model="voorwaarde.description"
								class="external-consultation-response__condition-input"
								:aria-label="
									t('procest', 'Condition description {n}', {
										n: idx + 1,
									})
								"
								:placeholder="t('procest', 'Condition description')"
								type="text" />
							<NcSelect
								v-model="voorwaarde.priority"
								:options="priorityOptions"
								:aria-label-combobox="
									t('procest', 'Priority condition {n}', {
										n: idx + 1,
									})
								"
								label="label"
								:reduce="(opt) => opt.value"
								class="external-consultation-response__condition-priority"
								:placeholder="t('procest', 'Priority')" />
							<NcButton
								type="tertiary"
								:title="t('procest', 'Remove condition')"
								@click="removeVoorwaarde(idx)">
								✕
							</NcButton>
						</div>
						<NcButton @click="addVoorwaarde">
							{{ t('procest', 'Add condition') }}
						</NcButton>
					</div>

					<div class="external-consultation-response__field">
						<label
							class="external-consultation-response__label"
							for="external-consultation-response-datum">
							{{ t('procest', 'Advice date') }} *
						</label>
						<input
							id="external-consultation-response-datum"
							v-model="responseForm.date"
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
							{{
								submitting
									? t('procest', 'Bezig...')
									: t('procest', 'Submit advice')
							}}
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
				notes: '',
				terms: [],
				date: new Date().toISOString().slice(0, 10),
			},

			adviesOptions: [
				{ label: this.t('procest', 'Positive'), value: 'positive' },
				{
					label: this.t('procest', 'Positive with conditions'),
					value: 'positief_with_terms',
				},
				{ label: this.t('procest', 'Negative'), value: 'negative' },
				{
					label: this.t('procest', 'Not applicable'),
					value: 'non_from_application',
				},
			],

			priorityOptions: [
				{ label: this.t('procest', 'High'), value: 'high' },
				{ label: this.t('procest', 'Normal'), value: 'normal' },
				{ label: this.t('procest', 'Low'), value: 'low' },
			],
		}
	},

	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		toelichtingRequired() {
			return this.responseForm.advies !== 'non_from_application'
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		isDeadlinePassed() {
			if (!this.consultationData?.latestResponseDate) return false
			return new Date(this.consultationData.latestResponseDate) < new Date()
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		canSubmit() {
			if (!this.responseForm.advies) return false
			if (this.toelichtingRequired && this.responseForm.notes.trim() === '')
				return false
			if (!this.responseForm.date) return false
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
				const response = await axios.get(
					`/apps/procest/api/public/consultations/${encodeURIComponent(this.token)}`,
				)
				this.consultationData = response.data
			} catch (err) {
				this.loadError = this.t(
					'procest',
					'Consultation not found or the link has expired.',
				)
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06 */
		addVoorwaarde() {
			this.responseForm.terms.push({
				description: '',
				priority: 'normal',
			})
		},

		/**
		 * @param idx
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-06
		 */
		removeVoorwaarde(idx) {
			this.responseForm.terms.splice(idx, 1)
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
						notes: this.responseForm.notes.trim(),
						terms:
							this.responseForm.advies === 'positief_with_terms'
								? [...this.responseForm.terms]
								: [],
						date: this.responseForm.date,
					},
				)
				this.submitted = true
			} catch (err) {
				this.submitError = this.t(
					'procest',
					'Submission failed. Please try again.',
				)
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
			return d.toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
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
