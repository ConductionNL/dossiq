<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CreateAccessLinkDialog asks the four things a case link needs: what its
  holder may do, until when, whether a password is needed, and which
  documents travel with it.

  It mints nothing itself. The payload goes to POST /apps/dossiq/api/shares,
  which asks OpenRegister for the link (#3817). OpenRegister refuses a link
  with no expiry, so the date field starts filled rather than empty.

  @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
-->
<template>
	<NcDialog
		:open="open"
		:name="t('dossiq', 'Share this case by link')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="create-access-link-dialog">
			<p class="create-access-link-dialog__intro">
				{{
					t(
						'dossiq',
						'Anyone holding the link can open the case without an account. Every use is recorded on the case.',
					)
				}}
			</p>

			<div class="form-group">
				<label for="access-link-label">{{ t('dossiq', 'Who is this for?') }}</label>
				<NcTextField
					id="access-link-label"
					:modelValue="form.label"
					:label="t('dossiq', 'Who is this for?')"
					:placeholder="t('dossiq', 'The body or person receiving it')"
					@update:modelValue="form.label = $event" />
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'What the holder may do') }}</label>
				<NcCheckboxRadioSwitch :modelValue="true" :disabled="true">
					{{ t('dossiq', 'Read the case') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.comment">
					{{ t('dossiq', 'Write a comment') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.upload">
					{{ t('dossiq', 'Add a document') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-group">
				<label for="access-link-expiry">{{ t('dossiq', 'Stops working on') }}</label>
				<NcTextField
					id="access-link-expiry"
					type="date"
					:modelValue="form.expiresAt"
					:label="t('dossiq', 'Stops working on')"
					@update:modelValue="form.expiresAt = $event" />
			</div>

			<div class="form-group">
				<label for="access-link-password">{{ t('dossiq', 'Password') }}</label>
				<NcTextField
					id="access-link-password"
					type="password"
					:modelValue="form.password"
					:label="t('dossiq', 'Password')"
					:placeholder="t('dossiq', 'Leave empty for no password')"
					@update:modelValue="form.password = $event" />
			</div>

			<div v-if="documents.length > 0" class="form-group">
				<label>{{ t('dossiq', 'Documents to send with it') }}</label>
				<p class="create-access-link-dialog__hint">
					{{
						t(
							'dossiq',
							'A document picked here gets its own link. The holder of that link sees the document and nothing else of the case.',
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-for="document in documents"
					:key="document.id"
					:modelValue="form.documents.includes(document.id)"
					@update:modelValue="toggleDocument(document.id, $event)">
					{{ document.name }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="createLink">
				{{ saving ? t('dossiq', 'Creating the link') : t('dossiq', 'Create the link') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcTextField,
} from '@nextcloud/vue'

/**
 * How far ahead the expiry field starts, in days.
 *
 * OpenRegister refuses a link with no expiry, so the handler is offered a
 * short one rather than an empty field they have to guess at.
 */
const DEFAULT_DAYS = 14

export default {
	name: 'CreateAccessLinkDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
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

		/** The case's documents, as `{ id, name }`. */
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
				label: '',
				comment: true,
				upload: false,
				password: '',
				expiresAt: this.defaultExpiry(),
				documents: [],
			},
		}
	},

	methods: {
		/**
		 * The date the expiry field starts on.
		 *
		 * @return {string} an ISO date, DEFAULT_DAYS from today.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		defaultExpiry() {
			const date = new Date()
			date.setDate(date.getDate() + DEFAULT_DAYS)
			return date.toISOString().slice(0, 10)
		},

		/**
		 * Add or remove one document from the share.
		 *
		 * @param {string} id the document id.
		 * @param {boolean} checked whether it travels with the link.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
		 */
		toggleDocument(id, checked) {
			const picked = this.form.documents.filter((entry) => entry !== id)
			if (checked) {
				picked.push(id)
			}
			this.form.documents = picked
		},

		/**
		 * Hand the payload up. Reading is always granted, so it is never sent
		 * as a choice.
		 *
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		createLink() {
			const capabilities = ['read']
			if (this.form.comment) {
				capabilities.push('comment')
			}
			if (this.form.upload) {
				capabilities.push('upload')
			}

			this.saving = true
			try {
				this.$emit('created', {
					caseId: this.caseId,
					shareType: 'link',
					label: this.form.label,
					capabilities,
					expiresAt: this.form.expiresAt,
					password: this.form.password || null,
					sharedDocuments: this.form.documents,
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.create-access-link-dialog {
	padding: 12px;
}

.create-access-link-dialog__intro,
.create-access-link-dialog__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-bottom: 12px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}
</style>
