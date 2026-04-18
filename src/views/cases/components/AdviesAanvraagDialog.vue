<!-- SPDX-License-Identifier: EUPL-1.2 -->

<template>
	<CnFormDialog
		:title="t(appName, 'Request Advice')"
		:submit-button-label="t(appName, 'Submit')"
		:loading="submitting"
		@submit="submitForm"
		@closed="$emit('closed')">

		<CnFormGroup>
			<CnFormLabel>{{ t(appName, 'Type') }} *</CnFormLabel>
			<div class="form-toggle-group">
				<label class="form-toggle-label">
					<input
						v-model="form.type"
						type="radio"
						value="intern"
						class="form-toggle-input">
					{{ t(appName, 'Internal') }}
				</label>
				<label class="form-toggle-label">
					<input
						v-model="form.type"
						type="radio"
						value="extern"
						class="form-toggle-input">
					{{ t(appName, 'External') }}
				</label>
			</div>
		</CnFormGroup>

		<CnFormGroup>
			<CnFormLabel>{{ t(appName, 'Advisor') }} *</CnFormLabel>
			<CnUserPicker
				v-if="form.type === 'intern'"
				v-model="form.adviseur"
				:placeholder="t(appName, 'Select user')"
				@input="form.adviseur = $event" />
			<CnInputField
				v-else
				v-model="form.adviseur"
				:label="t(appName, 'Organization name')"
				:placeholder="t(appName, 'e.g., Brandweer Amsterdam')" />
		</CnFormGroup>

		<CnFormGroup>
			<CnFormLabel>{{ t(appName, 'Subject') }} *</CnFormLabel>
			<CnInputField
				v-model="form.onderwerp"
				:placeholder="t(appName, 'What advice is needed?')" />
		</CnFormGroup>

		<CnFormGroup>
			<CnFormLabel>{{ t(appName, 'Deadline') }} *</CnFormLabel>
			<CnDateTimePicker
				v-model="form.deadline"
				:placeholder="t(appName, 'Select date')"
				type="date" />
		</CnFormGroup>

		<CnFormGroup>
			<CnFormLabel>{{ t(appName, 'Questions') }}</CnFormLabel>
			<CnTextareaField
				v-model="form.questions"
				:placeholder="t(appName, 'Specific questions for the advisor')"
				:rows="3" />
		</CnFormGroup>

	</CnFormDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	CnFormDialog,
	CnFormGroup,
	CnFormLabel,
	CnInputField,
	CnTextareaField,
	CnDateTimePicker,
	CnUserPicker,
} from '@conduction/nextcloud-vue'
import * as adviceApi from '../../../services/adviceApi.js'

const appName = 'procest'

export default {
	name: 'AdviesAanvraagDialog',

	components: {
		CnFormDialog,
		CnFormGroup,
		CnFormLabel,
		CnInputField,
		CnTextareaField,
		CnDateTimePicker,
		CnUserPicker,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['created', 'closed'],

	data() {
		return {
			appName,
			form: {
				type: 'intern',
				adviseur: '',
				onderwerp: '',
				deadline: this.defaultDeadline(),
				questions: '',
			},
			submitting: false,
		}
	},

	computed: {
		canSubmit() {
			return this.form.adviseur && this.form.onderwerp && this.form.deadline
		},
	},

	methods: {
		t,

		defaultDeadline() {
			const d = new Date()
			d.setDate(d.getDate() + 14)
			return d.toISOString().split('T')[0]
		},

		async submitForm() {
			if (!this.canSubmit) {
				return
			}

			this.submitting = true
			try {
				const data = {
					case: this.caseId,
					type: this.form.type,
					adviseur: this.form.adviseur,
					onderwerp: this.form.onderwerp,
					deadline: this.form.deadline,
					questions: this.form.questions,
				}

				await adviceApi.createAdvice(data)
				this.$emit('created')
			} catch (error) {
				console.error('Failed to create advice request:', error)
				this.showError(t(appName, 'Failed to create advice request'))
			} finally {
				this.submitting = false
			}
		},

		showError(message) {
			this.$notify({
				title: t(appName, 'Error'),
				text: message,
				type: 'error',
			})
		},
	},
}
</script>

<style scoped>
.form-toggle-group {
	display: flex;
	gap: 16px;
	margin: 8px 0;
}

.form-toggle-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 14px;
}

.form-toggle-input {
	cursor: pointer;
}
</style>
