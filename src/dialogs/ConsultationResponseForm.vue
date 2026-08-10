<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog v-if="open"
		:name="t('procest', 'Issue advice')"
		size="normal"
		:can-close="!submitting"
		@closing="onClose">
		<div class="consultation-response-form">
			<div v-if="consultationSubject" class="consultation-response-form__subject">
				<span class="consultation-response-form__subject-label">{{ t('procest', 'Subject:') }}</span>
				{{ consultationSubject }}
			</div>

			<div class="consultation-response-form__field">
				<label class="consultation-response-form__label">
					{{ t('procest', 'Advice') }} *
				</label>
				<NcSelect
					v-model="form.advies"
					:options="adviesOptions"
					:aria-label-combobox="t('procest', 'Advice')"
					label="label"
					:reduce="opt => opt.value"
					:placeholder="t('procest', 'Select advice type')" />
			</div>

			<div v-if="showToelichting" class="consultation-response-form__field">
				<label class="consultation-response-form__label" for="consultation-response-toelichting">
					{{ t('procest', 'Explanation') }} <span v-if="toelichtingRequired">*</span>
				</label>
				<textarea
					id="consultation-response-toelichting"
					v-model="form.toelichting"
					class="consultation-response-form__textarea"
					rows="4"
					:placeholder="t('procest', 'Provide an explanation for your advice...')" />
			</div>

			<!-- Conditions — only shown for positief_met_voorwaarden -->
			<div v-if="showVoorwaarden" class="consultation-response-form__field">
				<label class="consultation-response-form__label">
					{{ t('procest', 'Conditions') }}
				</label>
				<div
					v-for="(voorwaarde, idx) in form.voorwaarden"
					:key="idx"
					class="consultation-response-form__condition-row">
					<input
						v-model="voorwaarde.description"
						class="consultation-response-form__condition-input"
						:aria-label="t('procest', 'Condition description {n}', { n: idx + 1 })"
						:placeholder="t('procest', 'Condition description')"
						type="text">
					<NcSelect
						v-model="voorwaarde.priority"
						:options="priorityOptions"
						:aria-label-combobox="t('procest', 'Priority condition {n}', { n: idx + 1 })"
						label="label"
						:reduce="opt => opt.value"
						class="consultation-response-form__condition-priority"
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

			<div class="consultation-response-form__field">
				<label class="consultation-response-form__label" for="consultation-response-datum">
					{{ t('procest', 'Advice date') }} *
				</label>
				<input
					id="consultation-response-datum"
					v-model="form.datum"
					type="date"
					class="consultation-response-form__date-input"
					:max="today">
			</div>

			<NcNoteCard v-if="validationError" type="error">
				{{ validationError }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!canSubmit"
				@click="onSubmit">
				{{ submitting ? t('procest', 'Bezig...') : t('procest', 'Submit advice') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ConsultationResponseForm',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		consultationId: {
			type: String,
			required: true,
		},
		consultationSubject: {
			type: String,
			default: '',
		},
	},
	emits: ['close', 'submitted'],
	data() {
		return {
			submitting: false,
			validationError: '',
			form: {
				advies: null,
				toelichting: '',
				voorwaarden: [],
				datum: '',
			},
			adviesOptions: [
				{ label: this.t('procest', 'Positive'), value: 'positief' },
				{ label: this.t('procest', 'Positive with conditions'), value: 'positief_met_voorwaarden' },
				{ label: this.t('procest', 'Negative'), value: 'negatief' },
				{ label: this.t('procest', 'Not applicable'), value: 'niet_van_toepassing' },
			],
			priorityOptions: [
				{ label: this.t('procest', 'High'), value: 'hoog' },
				{ label: this.t('procest', 'Normal'), value: 'normaal' },
				{ label: this.t('procest', 'Low'), value: 'laag' },
			],
		}
	},
	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		showVoorwaarden() {
			return this.form.advies === 'positief_met_voorwaarden'
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		showToelichting() {
			return this.form.advies !== null
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		toelichtingRequired() {
			return this.form.advies !== 'niet_van_toepassing'
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		canSubmit() {
			if (this.submitting) return false
			if (!this.form.advies) return false
			if (this.toelichtingRequired && this.form.toelichting.trim() === '') return false
			if (!this.form.datum) return false
			return true
		},
	},
	watch: {
		/**
		 * @param value
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		open(value) {
			if (value) {
				this.validationError = ''
				this.submitting = false
				this.form = {
					advies: null,
					toelichting: '',
					voorwaarden: [],
					datum: this.today,
				}
			}
		},
	},
	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		addVoorwaarde() {
			this.form.voorwaarden.push({ description: '', priority: 'normaal' })
		},
		/**
		 * @param idx
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		removeVoorwaarde(idx) {
			this.form.voorwaarden.splice(idx, 1)
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		validate() {
			if (!this.form.advies) {
				this.validationError = this.t('procest', 'Select an advice type.')
				return false
			}
			if (this.toelichtingRequired && this.form.toelichting.trim() === '') {
				this.validationError = this.t('procest', 'Explanation is required for this advice type.')
				return false
			}
			if (!this.form.datum) {
				this.validationError = this.t('procest', 'Date is required.')
				return false
			}
			return true
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onSubmit() {
			this.validationError = ''
			if (!this.validate()) return
			this.submitting = true
			this.$emit('submitted', {
				consultationId: this.consultationId,
				advies: this.form.advies,
				toelichting: this.form.toelichting.trim(),
				voorwaarden: this.showVoorwaarden ? [...this.form.voorwaarden] : [],
				datum: this.form.datum,
			})
			this.submitting = false
		},
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.consultation-response-form {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 12px 0;
}

.consultation-response-form__subject {
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.9em;
}

.consultation-response-form__subject-label {
	font-weight: 600;
	margin-right: 4px;
}

.consultation-response-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-response-form__label {
	font-weight: 600;
	font-size: 0.9em;
}

.consultation-response-form__textarea {
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

.consultation-response-form__date-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__condition-row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 6px;
}

.consultation-response-form__condition-input {
	flex: 1;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__condition-priority {
	width: 140px;
	flex-shrink: 0;
}
</style>
