<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<!--
  Step-type-aware action dialog for parafering: advies, parafering, accordering,
  and terugsturen. Submits via the procest /api/parafeer-actie backend endpoint
  (which enforces per-step actor authorization server-side).

  @spec openspec/changes/parafering-actions/tasks.md#T07
-->
<template>
	<NcDialog
		v-if="open"
		:name="dialogTitle"
		size="normal"
		:can-close="!submitting"
		@closing="onClose">
		<div class="parafeer-actie-dialog">
			<div class="parafeer-actie-dialog__step">
				<strong>{{ t('procest', 'Step') }} {{ step.order }} — {{ stepLabel }}</strong>
				<span v-if="step.actor" class="parafeer-actie-dialog__actor">
					{{ step.actor }}
				</span>
			</div>

			<!-- Advies field: required on advies steps. -->
			<div v-if="isAdviesStep && !showReturnForm" class="parafeer-actie-dialog__field">
				<label>{{ t('procest', 'Advice') }} *</label>
				<textarea
					v-model="advice"
					rows="4"
					:placeholder="t('procest', 'Advice')"
					:disabled="submitting" />
			</div>

			<!-- Optional comment for parafering/accordering. -->
			<div v-if="!isAdviesStep && !showReturnForm" class="parafeer-actie-dialog__field">
				<label>{{ t('procest', 'Optional comment') }}</label>
				<textarea
					v-model="comment"
					rows="3"
					:placeholder="t('procest', 'Optional comment')"
					:disabled="submitting" />
			</div>

			<!-- Return reason form (toggled by Terugsturen click). -->
			<div v-if="showReturnForm" class="parafeer-actie-dialog__field">
				<label>{{ t('procest', 'Reason for returning') }} *</label>
				<textarea
					v-model="returnReason"
					rows="4"
					:placeholder="t('procest', 'Reason for returning')"
					:disabled="submitting" />
				<p v-if="validationError" class="parafeer-actie-dialog__error">
					{{ validationError }}
				</p>
			</div>

			<!-- Delegate selector — hidden when mandates empty. -->
			<DelegateSelectorField
				v-if="!showReturnForm"
				:mandates="mandates"
				@update:onBehalfOf="onBehalfOf = $event"
				@update:mandate="mandate = $event" />

			<p v-if="errorMessage" class="parafeer-actie-dialog__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<template v-if="!showReturnForm">
				<NcButton
					v-if="primaryActionLabel"
					type="primary"
					:disabled="submitting || !canSubmit"
					@click="submitPrimary">
					{{ primaryActionLabel }}
				</NcButton>
				<NcButton
					type="error"
					:disabled="submitting"
					@click="showReturnForm = true">
					{{ t('procest', 'Return') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton
					type="error"
					:disabled="submitting || returnReason.trim() === ''"
					@click="submitReturn">
					{{ t('procest', 'Return') }}
				</NcButton>
				<NcButton :disabled="submitting" @click="showReturnForm = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import DelegateSelectorField from './DelegateSelectorField.vue'
import { recordAction } from '../../../services/parafeerActieApi.js'

export default {
	name: 'ParafeerActieDialog',
	components: {
		NcDialog,
		NcButton,
		DelegateSelectorField,
	},
	props: {
		voorstelId: {
			type: String,
			required: true,
		},
		/**
		 * Current step (from voorstel.routeSnapshot) — must include order, type, actor, label.
		 */
		step: {
			type: Object,
			required: true,
		},
		open: {
			type: Boolean,
			default: false,
		},
		/**
		 * Mandates available to the logged-in user. When empty, the
		 * DelegateSelectorField is hidden.
		 */
		mandates: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['action-recorded', 'update:open'],
	data() {
		return {
			advice: '',
			comment: '',
			returnReason: '',
			showReturnForm: false,
			onBehalfOf: null,
			mandate: null,
			submitting: false,
			errorMessage: '',
			validationError: '',
		}
	},
	computed: {
		isAdviesStep() {
			return this.step?.type === 'advies'
		},
		stepLabel() {
			return this.step?.label || this.formatStepType(this.step?.type)
		},
		dialogTitle() {
			return this.t('procest', 'Take action')
		},
		primaryActionLabel() {
			if (this.step?.type === 'advies') return this.t('procest', 'Advise')
			if (this.step?.type === 'parafering') return this.t('procest', 'Approve (paraferen)')
			if (this.step?.type === 'accordering') return this.t('procest', 'Accord')
			return ''
		},
		canSubmit() {
			if (this.isAdviesStep) {
				return this.advice.trim() !== ''
			}
			return true
		},
	},
	methods: {
		formatStepType(type) {
			const labels = {
				advies: this.t('procest', 'Advise'),
				parafering: this.t('procest', 'Approve (paraferen)'),
				accordering: this.t('procest', 'Accord'),
			}
			return labels[type] || type || ''
		},
		onClose() {
			if (this.submitting) return
			this.$emit('update:open', false)
			this.resetForm()
		},
		resetForm() {
			this.advice = ''
			this.comment = ''
			this.returnReason = ''
			this.showReturnForm = false
			this.onBehalfOf = null
			this.mandate = null
			this.errorMessage = ''
			this.validationError = ''
		},
		buildPayload(action) {
			const payload = {
				voorstel: this.voorstelId,
				action,
			}
			if (this.comment.trim() !== '') payload.comment = this.comment.trim()
			if (this.advice.trim() !== '') payload.advice = this.advice.trim()
			if (this.onBehalfOf) payload.onBehalfOf = this.onBehalfOf
			if (this.mandate) payload.mandate = this.mandate
			return payload
		},
		async submitPrimary() {
			if (!this.canSubmit) return
			const stepType = this.step?.type
			let action = null
			if (stepType === 'advies') action = 'advised'
			if (stepType === 'parafering') action = 'parafered'
			if (stepType === 'accordering') action = 'accorded'
			if (!action) {
				this.errorMessage = this.t('procest', 'Invalid action for this step type')
				return
			}
			await this.submit(this.buildPayload(action))
		},
		async submitReturn() {
			if (this.returnReason.trim() === '') {
				this.validationError = this.t('procest', 'Return reason is required')
				return
			}
			this.validationError = ''
			const payload = {
				voorstel: this.voorstelId,
				action: 'returned',
				comment: this.returnReason.trim(),
			}
			await this.submit(payload)
		},
		async submit(payload) {
			this.submitting = true
			this.errorMessage = ''
			try {
				const result = await recordAction(payload)
				this.$emit('action-recorded', result)
				this.$emit('update:open', false)
				this.resetForm()
			} catch (error) {
				const serverMessage = error?.response?.data?.message
				this.errorMessage = serverMessage || this.t('procest', 'Operation failed')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.parafeer-actie-dialog__step {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.parafeer-actie-dialog__actor {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.parafeer-actie-dialog__field {
	margin-top: 12px;
}

.parafeer-actie-dialog__field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.parafeer-actie-dialog__field textarea {
	width: 100%;
	resize: vertical;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.parafeer-actie-dialog__error {
	color: var(--color-error);
	font-size: 0.9em;
	margin-top: 8px;
}
</style>
