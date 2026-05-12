<template>
	<NcDialog
		:open="open"
		:name="t('procest', 'Transfer case')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="case-transfer-dialog">
			<p class="case-transfer-dialog__description">
				{{ t('procest', 'Transfer ownership of this case to another organization. The target organization must accept the transfer before it takes effect.') }}
			</p>

			<div class="form-group">
				<label>{{ t('procest', 'Target organization') }}</label>
				<NcSelect
					v-model="form.targetOrganization"
					:options="partners"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Select organization...')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Reason for transfer') }}</label>
				<textarea
					v-model="form.reason"
					class="case-transfer-dialog__textarea"
					:placeholder="t('procest', 'Explain why this case should be transferred...')"
					rows="3" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Requested transfer date') }}</label>
				<NcDateTimePicker
					v-model="form.requestedDate"
					type="date" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!isValid || saving"
				@click="submitTransfer">
				{{ saving ? t('procest', 'Submitting...') : t('procest', 'Submit transfer request') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcDateTimePicker } from '@nextcloud/vue'

export default {
	name: 'CaseTransferDialog',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcDateTimePicker,
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
		partners: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:open', 'submitted'],
	data() {
		return {
			saving: false,
			form: {
				targetOrganization: null,
				reason: '',
				requestedDate: new Date(),
			},
		}
	},
	computed: {
		isValid() {
			return this.form.targetOrganization && this.form.reason.trim().length > 0
		},
	},
	methods: {
		async submitTransfer() {
			this.saving = true
			try {
				this.$emit('submitted', {
					caseId: this.caseId,
					targetOrganization: this.form.targetOrganization?.id,
					reason: this.form.reason,
					requestedDate: this.form.requestedDate
						? new Date(this.form.requestedDate).toISOString().slice(0, 10)
						: new Date().toISOString().slice(0, 10),
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.case-transfer-dialog__description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.case-transfer-dialog .form-group {
	margin-bottom: 12px;
}

.case-transfer-dialog .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.case-transfer-dialog__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
}
</style>
