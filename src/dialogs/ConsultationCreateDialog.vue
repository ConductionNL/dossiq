<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('procest', 'New consultation')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="consultation-create-dialog">
			<!-- Read-only parent case display -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Case') }}
				</label>
				<span class="consultation-create-dialog__readonly">{{
					caseId
				}}</span>
			</div>

			<div class="consultation-create-dialog__field">
				<NcTextField
					:modelValue="form.adviesInstantie"
					:label="t('procest', 'Advisory body')"
					:placeholder="
						t('procest', 'e.g. Fire brigade, Aesthetics committee')
					"
					required
					@update:modelValue="(v) => (form.adviesInstantie = v)" />
			</div>

			<div class="consultation-create-dialog__field">
				<NcTextField
					:modelValue="form.onderwerp"
					:label="t('procest', 'Onderwerp')"
					required
					@update:modelValue="(v) => (form.onderwerp = v)" />
			</div>

			<div class="consultation-create-dialog__field">
				<label
					class="consultation-create-dialog__label"
					for="consultation-create-question">
					{{ t('procest', 'Question') }} *
				</label>
				<textarea
					id="consultation-create-question"
					v-model="form.vraagstelling"
					class="consultation-create-dialog__textarea"
					rows="4" />
			</div>

			<div class="consultation-create-dialog__field">
				<label
					class="consultation-create-dialog__label"
					for="consultation-create-response-date">
					{{ t('procest', 'Latest response date') }} *
				</label>
				<input
					id="consultation-create-response-date"
					v-model="form.uiterlijkeReactiedatum"
					type="date"
					class="consultation-create-dialog__date-input"
					:min="today" />
			</div>

			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('procest', 'Priority') }}
				</label>
				<NcSelect
					v-model="form.prioriteit"
					:options="prioriteitOptions"
					:aria-label-combobox="t('procest', 'Priority')"
					label="label"
					:reduce="(opt) => opt.value"
					:placeholder="t('procest', 'Select priority')" />
			</div>

			<NcNoteCard v-if="validationError" type="error">
				{{ validationError }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="onSubmit">
				{{
					submitting
						? t('procest', 'Bezig...')
						: t('procest', 'Create consultation')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ConsultationCreateDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		caseId: {
			type: String,
			required: true,
		},

		parentZaakTitle: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'created'],
	data() {
		return {
			submitting: false,
			validationError: '',
			form: {
				adviesInstantie: '',
				onderwerp: '',
				vraagstelling: '',
				uiterlijkeReactiedatum: '',
				prioriteit: 'normaal',
			},

			prioriteitOptions: [
				{ label: this.t('procest', 'Normal'), value: 'normaal' },
				{ label: this.t('procest', 'Urgent'), value: 'spoed' },
			],
		}
	},

	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		defaultDeadline() {
			const d = new Date()
			d.setDate(d.getDate() + 28)
			return d.toISOString().slice(0, 10)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		canSubmit() {
			return (
				!this.submitting
				&& this.form.adviesInstantie.trim() !== ''
				&& this.form.onderwerp.trim() !== ''
				&& this.form.vraagstelling.trim() !== ''
				&& this.form.uiterlijkeReactiedatum !== ''
			)
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
					adviesInstantie: '',
					onderwerp: this.parentZaakTitle,
					vraagstelling: '',
					uiterlijkeReactiedatum: this.defaultDeadline,
					prioriteit: 'normaal',
				}
			}
		},
	},

	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		validate() {
			if (this.form.adviesInstantie.trim() === '') {
				this.validationError = this.t(
					'procest',
					'Advisory body is required.',
				)
				return false
			}
			if (this.form.onderwerp.trim() === '') {
				this.validationError = this.t('procest', 'Subject is required.')
				return false
			}
			if (this.form.vraagstelling.trim() === '') {
				this.validationError = this.t('procest', 'Question is required.')
				return false
			}
			if (this.form.uiterlijkeReactiedatum === '') {
				this.validationError = this.t(
					'procest',
					'Latest response date is required.',
				)
				return false
			}
			return true
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onSubmit() {
			this.validationError = ''
			if (!this.validate()) return
			this.submitting = true
			this.$emit('created', {
				parentZaak: this.caseId,
				adviesInstantie: this.form.adviesInstantie.trim(),
				onderwerp: this.form.onderwerp.trim(),
				vraagstelling: this.form.vraagstelling.trim(),
				uiterlijkeReactiedatum: this.form.uiterlijkeReactiedatum,
				prioriteit: this.form.prioriteit,
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
.consultation-create-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.consultation-create-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-create-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
}

.consultation-create-dialog__readonly {
	padding: 6px 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.consultation-create-dialog__textarea {
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

.consultation-create-dialog__date-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
