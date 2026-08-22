<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'Stap overslaan')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="skip-step-dialog">
			<div v-if="step" class="skip-step-dialog__step">
				<h4>
					{{
						t('dossiq', 'Stap {n}: {actor}', {
							n: step.order,
							actor: step.actor,
						})
					}}
				</h4>
				<p>
					<strong>{{ t('dossiq', 'Type') }}:</strong> {{ step.type }}<br />
					<strong>{{ t('dossiq', 'Actor type') }}:</strong>
					{{ step.actorType }}
				</p>
			</div>

			<NcNoteCard v-if="step && step.mandatory" type="warning">
				{{ t('dossiq', 'This step is mandatory and cannot be skipped.') }}
			</NcNoteCard>

			<NcTextArea
				v-else
				:modelValue="reason"
				:label="t('dossiq', 'Reason for skipping')"
				:placeholder="
					t('dossiq', 'Give a reason why this step is being skipped...')
				"
				required
				@update:modelValue="(v) => (reason = v)" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('dossiq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSubmit" @click="onSubmit">
				{{ submitting ? t('dossiq', 'Bezig...') : t('dossiq', 'Overslaan') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import parafeerRouteApi from '../services/parafeerRouteApi.js'

export default {
	name: 'SkipStepDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		voorstelId: {
			type: String,
			required: true,
		},

		step: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			reason: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		canSubmit() {
			return (
				!this.submitting
				&& this.step
				&& this.step.mandatory !== true
				&& this.reason.trim().length > 0
			)
		},
	},

	watch: {
		/**
		 * @param value
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		open(value) {
			if (value) {
				this.reason = ''
				this.error = ''
			}
		},
	},

	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async onSubmit() {
			if (!this.canSubmit) return
			this.submitting = true
			this.error = ''
			try {
				await parafeerRouteApi.skipStep(this.voorstelId, {
					step: this.step.order,
					reason: this.reason.trim(),
				})
				this.$emit('skipped')
			} catch (err) {
				const apiMessage = err?.response?.data?.error
				this.error = apiMessage || this.t('dossiq', 'Failed to skip')
				console.error('skipStep failed', err)
			} finally {
				this.submitting = false
			}
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.skip-step-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.skip-step-dialog__step h4 {
	margin: 0 0 4px 0;
}
</style>
