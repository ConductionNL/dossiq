<!-- SPDX-License-Identifier: EUPL-1.2 -->

<template>
	<NcDialog
		:open="visible"
		:title="t('procest', 'Request Advice')"
		:buttons="buttons"
		@close="close">
		<div class="dialog-content">
			<div class="form-group">
				<label class="form-label">{{ t('procest', 'Type') }}</label>
				<div class="type-toggle">
					<label class="toggle-option">
						<input
							v-model="formData.type"
							type="radio"
							value="intern"
							class="toggle-input">
						{{ t('procest', 'Internal') }}
					</label>
					<label class="toggle-option">
						<input
							v-model="formData.type"
							type="radio"
							value="extern"
							class="toggle-input">
						{{ t('procest', 'External') }}
					</label>
				</div>
			</div>

			<div class="form-group">
				<label class="form-label">{{ t('procest', 'Adviseur') }}</label>
				<input
					v-model="formData.adviseur"
					type="text"
					class="form-input"
					:placeholder="formData.type === 'intern' ? t('procest', 'User ID') : t('procest', 'Organization name')"
					@keyup.enter="submit">
			</div>

			<div class="form-group">
				<label class="form-label">{{ t('procest', 'Subject') }}</label>
				<input
					v-model="formData.onderwerp"
					type="text"
					class="form-input"
					:placeholder="t('procest', 'What advice is needed?')"
					@keyup.enter="submit">
			</div>

			<div class="form-group">
				<label class="form-label">{{ t('procest', 'Deadline') }}</label>
				<input
					v-model="formData.deadline"
					type="date"
					class="form-input"
					:min="today">
			</div>

			<div class="form-group">
				<label class="form-label">{{ t('procest', 'Questions (optional)') }}</label>
				<textarea
					v-model="formData.questions"
					class="form-textarea"
					:placeholder="t('procest', 'Specific questions for the adviseur')"
					rows="4" />
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import * as adviceApi from '../../../services/adviceApi.js'

export default {
	name: 'AdviesAanvraagDialog',

	components: {
		NcDialog,
	},

	props: {
		visible: {
			type: Boolean,
			default: false,
		},
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			formData: {
				type: 'intern',
				adviseur: '',
				onderwerp: '',
				deadline: this.getDefaultDeadline(),
				questions: '',
			},
			submitting: false,
		}
	},

	computed: {
		today() {
			return new Date().toISOString().split('T')[0]
		},

		canSubmit() {
			return this.formData.adviseur && this.formData.deadline && !this.submitting
		},

		buttons() {
			return [
				{
					label: this.submitting ? t('procest', 'Saving...') : t('procest', 'Create'),
					type: 'primary',
					disabled: !this.canSubmit,
					callback: this.submit,
				},
				{
					label: t('procest', 'Cancel'),
					callback: this.close,
				},
			]
		},
	},

	methods: {
		t,

		getDefaultDeadline() {
			const date = new Date()
			date.setDate(date.getDate() + 14)
			return date.toISOString().split('T')[0]
		},

		async submit() {
			if (!this.canSubmit) {
				return
			}

			this.submitting = true
			try {
				await adviceApi.createAdvice({
					caseId: this.caseId,
					type: this.formData.type,
					adviseur: this.formData.adviseur,
					onderwerp: this.formData.onderwerp,
					deadline: this.formData.deadline,
					questions: this.formData.questions,
				})

				this.$emit('created')
				this.resetForm()
			} catch (error) {
				this.$notify({
					title: t('procest', 'Error'),
					text: t('procest', 'Failed to create advice request'),
					type: 'error',
				})
			} finally {
				this.submitting = false
			}
		},

		close() {
			this.$emit('close')
			this.resetForm()
		},

		resetForm() {
			this.formData = {
				type: 'intern',
				adviseur: '',
				onderwerp: '',
				deadline: this.getDefaultDeadline(),
				questions: '',
			}
		},
	},
}
</script>

<style scoped>
.dialog-content {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.form-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-text);
}

.form-input,
.form-textarea {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
	font-family: inherit;
	background: var(--color-main-background);
	color: var(--color-text);
}

.form-input:focus,
.form-textarea:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-light);
}

.type-toggle {
	display: flex;
	gap: 12px;
	margin-top: 4px;
}

.toggle-option {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	user-select: none;
	font-size: 14px;
}

.toggle-input {
	cursor: pointer;
}
</style>
