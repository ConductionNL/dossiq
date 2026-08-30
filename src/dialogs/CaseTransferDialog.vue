<template>
	<NcDialog
		:open="open"
		:name="t('dossiq', 'Transfer case')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="case-transfer-dialog">
			<p class="case-transfer-dialog__description">
				{{
					t(
						'dossiq',
						'Transfer ownership of this case to another organization. The target organization must accept the transfer before it takes effect.',
					)
				}}
			</p>

			<div class="form-group">
				<label>{{ t('dossiq', 'Target organization') }}</label>
				<NcSelect
					v-model="form.targetOrganization"
					:options="partners"
					:aria-label-combobox="t('dossiq', 'Target organization')"
					label="name"
					trackBy="id"
					:placeholder="t('dossiq', 'Select organization...')" />
			</div>

			<div class="form-group">
				<label for="case-transfer-reason">{{
					t('dossiq', 'Reason for transfer')
				}}</label>
				<textarea
					id="case-transfer-reason"
					v-model="form.reason"
					class="case-transfer-dialog__textarea"
					:placeholder="
						t('dossiq', 'Explain why this case should be transferred...')
					"
					rows="3" />
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'Requested transfer date') }}</label>
				<NcDateTimePicker v-model="form.requestedDate" type="date" />
			</div>

			<!--
				Federated (cross-instance) zaakoverdracht — optional. When set,
				the transfer mints a transfer-scoped OpenRegister federated
				share so the remote instance can authenticate its accept/reject
				call (design.md §4). Leave empty for a same-instance transfer.
			-->
			<div class="form-group">
				<label for="case-transfer-remote-cloud-id">{{
					t(
						'dossiq',
						'Remote cloud ID (optional, for cross-instance transfer)',
					)
				}}</label>
				<input
					id="case-transfer-remote-cloud-id"
					v-model="form.remoteCloudId"
					type="text"
					:placeholder="
						t('dossiq', 'e.g. partner-org@partner.example.com')
					" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!isValid || saving"
				@click="submitTransfer">
				{{
					saving
						? t('dossiq', 'Submitting...')
						: t('dossiq', 'Submit transfer request')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePicker, NcDialog, NcSelect } from '@nextcloud/vue'

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
				remoteCloudId: '',
			},
		}
	},

	computed: {
		isValid() {
			return this.form.targetOrganization && this.form.reason.trim().length > 0
		},
	},

	methods: {
		/**
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 * @spec openspec/specs/federated-case-collaboration/spec.md#case-transfer-extends-across-federation-with-idempotent-acceptreject-and-a-custody-audit-trail
		 */
		async submitTransfer() {
			this.saving = true
			try {
				this.$emit('submitted', {
					caseId: this.caseId,
					targetOrganization: this.form.targetOrganization?.id,
					reason: this.form.reason,
					requestedDate: this.form.requestedDate
						? new Date(this.form.requestedDate)
								.toISOString()
								.slice(0, 10)
						: new Date().toISOString().slice(0, 10),
					remoteCloudId: this.form.remoteCloudId?.trim() || null,
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
