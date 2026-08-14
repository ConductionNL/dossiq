<template>
	<NcDialog
		:open="open"
		:name="t('procest', 'Share case with a remote organisation')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="create-federated-share-dialog">
			<p class="create-federated-share-dialog__description">
				{{
					t(
						'procest',
						'Only the fields you select below are shared — never the whole case. The remote organisation gets read-only access to a snapshot; it can collaborate via the activity stream but cannot change the case.',
					)
				}}
			</p>

			<div class="form-group">
				<label for="federated-share-remote-cloud-id">{{
					t('procest', 'Remote cloud ID')
				}}</label>
				<input
					id="federated-share-remote-cloud-id"
					v-model="form.remoteCloudId"
					type="text"
					:placeholder="
						t('procest', 'e.g. partner-org@partner.example.com')
					" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Fields to share') }}</label>
				<ul class="create-federated-share-dialog__fields">
					<li v-for="field in allowedFields" :key="field.value">
						<label class="create-federated-share-dialog__checkbox">
							<input
								v-model="form.sharedFields"
								type="checkbox"
								:value="field.value" />
							{{ field.label }}
						</label>
					</li>
				</ul>
			</div>

			<div v-if="documents.length > 0" class="form-group">
				<label>{{ t('procest', 'Documents to share') }}</label>
				<ul class="create-federated-share-dialog__fields">
					<li v-for="doc in documents" :key="doc.id">
						<label class="create-federated-share-dialog__checkbox">
							<input
								v-model="form.sharedDocuments"
								type="checkbox"
								:value="doc.id" />
							{{ doc.name || doc.id }}
						</label>
					</li>
				</ul>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!isValid || saving"
				@click="createFederatedShare">
				{{ saving ? t('procest', 'Sharing...') : t('procest', 'Share') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import {
	FEDERATION_ALLOWED_FIELDS,
	isFederatedShareFormValid,
	shapeFederatedSharePayload,
} from '../utils/federatedShareHelpers.js'

export default {
	name: 'CreateFederatedShareDialog',
	components: {
		NcDialog,
		NcButton,
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

		/** Documents attached to the case; only these may be selected (server re-validates). */
		documents: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open', 'created'],
	data() {
		return {
			saving: false,
			form: {
				remoteCloudId: '',
				sharedFields: [],
				sharedDocuments: [],
			},

			// Field labels shown in the checkbox list; the VALUES mirror
			// CaseSharingService::FEDERATION_ALLOWED_FIELDS via the shared
			// helper (PHP is the source of truth; the server rejects any
			// field outside its own allow-list even if this UI somehow got
			// out of sync).
			allowedFields: FEDERATION_ALLOWED_FIELDS.map((value) => ({
				value,
				label: this.fieldLabel(value),
			})),
		}
	},

	computed: {
		isValid() {
			return isFederatedShareFormValid(this.form)
		},
	},

	methods: {
		/**
		 * @param {string} value the field name.
		 * @return {string} a human-readable label.
		 */
		fieldLabel(value) {
			const labels = {
				title: t('procest', 'Title'),
				description: t('procest', 'Description'),
				status: t('procest', 'Status'),
				caseType: t('procest', 'Case type'),
				priority: t('procest', 'Priority'),
				dueDate: t('procest', 'Due date'),
				requestedDate: t('procest', 'Requested date'),
			}
			return labels[value] || value
		},

		/**
		 * Emit a federated-share creation payload. The server independently
		 * re-validates every field/document against the allow-list — this
		 * dialog never trusts its own client-side selection as authoritative.
		 *
		 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
		 */
		async createFederatedShare() {
			this.saving = true
			try {
				this.$emit(
					'created',
					shapeFederatedSharePayload(this.form, this.caseId),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.create-federated-share-dialog__description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.create-federated-share-dialog .form-group {
	margin-bottom: 12px;
}

.create-federated-share-dialog .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.create-federated-share-dialog__fields {
	list-style: none;
	margin: 0;
	padding: 0;
}

.create-federated-share-dialog__checkbox {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: normal;
	padding: 2px 0;
}
</style>
