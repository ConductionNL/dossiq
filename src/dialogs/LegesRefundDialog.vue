<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog v-if="open"
		:name="t('procest', 'Restitutie aanvragen')"
		size="normal"
		:can-close="!submitting"
		@closing="onClose">
		<div class="leges-refund">
			<p class="leges-refund__original">
				{{ t('procest', 'Oorspronkelijk bedrag') }}:
				<strong>{{ formatEuro(originalAmount) }}</strong>
			</p>

			<NcSelect v-model="reason"
				:options="reasonOptions"
				:input-label="t('procest', 'Reden')"
				:reduce="o => o.value"
				label="label"
				:clearable="false" />

			<NcSelect v-model="fase"
				:options="faseOptions"
				:input-label="t('procest', 'Fase bij intrekking')"
				:reduce="o => o.value"
				label="label"
				:clearable="false" />

			<p class="leges-refund__calc">
				{{ t('procest', 'Berekend restitutiepercentage') }}:
				<strong>{{ refundPercentage }}%</strong>
			</p>
			<p class="leges-refund__amount">
				{{ t('procest', 'Restitutiebedrag') }}:
				<strong>{{ formatEuro(refundAmount) }}</strong>
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('procest', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit"
				@click="onSubmit">
				{{ submitting ? t('procest', 'Bezig...') : t('procest', 'Creditfactuur indienen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { submitRefund } from '../services/legesApi.js'

// Phase staffel mirrors LegesRestitutieService::PHASE_STAFFEL.
const PHASE_STAFFEL = {
	aanvraag: 100,
	start_behandeling: 75,
	in_behandeling: 75,
	beschikking: 0,
	afgehandeld: 0,
}

export default {
	name: 'LegesRefundDialog',
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
		caseId: {
			type: String,
			required: true,
		},
		originalAmount: {
			type: Number,
			default: 0,
		},
	},
	data() {
		return {
			reason: 'aanvraag_ingetrokken',
			fase: 'in_behandeling',
			submitting: false,
			error: '',
		}
	},
	computed: {
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		reasonOptions() {
			return [
				{ value: 'aanvraag_ingetrokken', label: this.t('procest', 'Aanvraag ingetrokken') },
				{ value: 'dubbel_betaald', label: this.t('procest', 'Dubbel betaald') },
				{ value: 'coulance', label: this.t('procest', 'Coulance') },
				{ value: 'bezwaar_gegrond', label: this.t('procest', 'Bezwaar gegrond') },
			]
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		faseOptions() {
			return [
				{ value: 'aanvraag', label: this.t('procest', 'Aanvraag (binnen termijn)') },
				{ value: 'in_behandeling', label: this.t('procest', 'In behandeling') },
				{ value: 'beschikking', label: this.t('procest', 'Na beschikking') },
			]
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		refundPercentage() {
			return PHASE_STAFFEL[this.fase] ?? 0
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		refundAmount() {
			return Math.round(this.originalAmount * (this.refundPercentage / 100))
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		canSubmit() {
			return !this.submitting && this.reason && this.fase
		},
	},
	watch: {
		/**
		 * @param value
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
		 */
		open(value) {
			if (value) {
				this.error = ''
				this.submitting = false
			}
		},
	},
	methods: {
		/**
		 * @param cents
		 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-006
		 */
		formatEuro(cents) {
			return '€' + (cents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		async onSubmit() {
			if (!this.canSubmit) return
			this.submitting = true
			this.error = ''
			try {
				const result = await submitRefund(this.caseId, { reason: this.reason, fase: this.fase })
				this.$emit('refunded', result)
			} catch (err) {
				this.error = err?.response?.data?.error || this.t('procest', 'Restitutie mislukt')
				console.error('Procest leges refund failed', err)
			} finally {
				this.submitting = false
			}
		},
		/** @spec openspec/changes/leges-heffingen/specs.md#req-leges-006 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.leges-refund {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}
</style>
