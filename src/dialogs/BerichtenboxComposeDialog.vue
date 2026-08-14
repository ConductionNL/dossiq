<template>
	<NcDialog
		v-if="show"
		:name="t('procest', 'Send Mijn Overheid Message')"
		size="normal"
		@close="$emit('close')">
		<div class="compose-dialog">
			<div class="form-group">
				<NcTextField
					:model-value="form.bsn"
					:label="t('procest', 'BSN (burgerservicenummer)')"
					:error="!!errors.bsn"
					@update:model-value="(v) => (form.bsn = v)" />
				<p v-if="errors.bsn" class="form-error">
					{{ errors.bsn }}
				</p>
			</div>

			<div class="form-group">
				<NcTextField
					:model-value="form.subject"
					:label="t('procest', 'Subject')"
					:error="!!errors.subject"
					@update:model-value="(v) => (form.subject = v)" />
				<p v-if="errors.subject" class="form-error">
					{{ errors.subject }}
				</p>
			</div>

			<div class="form-group">
				<label for="berichtenbox-compose-body">{{
					t('procest', 'Message (plain text only)')
				}}</label>
				<textarea
					id="berichtenbox-compose-body"
					v-model="form.body"
					class="compose-dialog__body"
					rows="8"
					:placeholder="t('procest', 'Enter your message...')" />
				<small class="compose-dialog__char-count">
					{{ form.body.length }} {{ t('procest', 'characters') }}
				</small>
				<p v-if="errors.body" class="form-error">
					{{ errors.body }}
				</p>
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Bericht type') }}</label>
				<NcSelect
					v-model="form.berichtTypeCode"
					:options="typeCodes"
					:aria-label-combobox="t('procest', 'Bericht type')"
					label="label"
					track-by="code" />
			</div>

			<div class="compose-dialog__actions">
				<NcButton type="primary" :disabled="sending" @click="send">
					{{ sending ? t('procest', 'Sending...') : t('procest', 'Send') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="sendError" type="error">
				{{ sendError }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcTextField,
	NcSelect,
	NcNoteCard,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { sendMessage } from '../services/berichtenboxApi.js'

export default {
	name: 'BerichtenboxComposeDialog',
	components: { NcDialog, NcButton, NcTextField, NcSelect, NcNoteCard },
	props: {
		caseId: { type: String, required: true },
		bsn: { type: String, default: '' },
		show: { type: Boolean, default: false },
	},
	emits: ['close', 'sent'],
	data() {
		return {
			form: { bsn: this.bsn, subject: '', body: '', berichtTypeCode: null },
			typeCodes: [
				{ code: 'decision', label: t('procest', 'Decision (Besluit)') },
				{ code: 'status', label: t('procest', 'Status update') },
				{ code: 'informatie', label: t('procest', 'Information') },
			],
			errors: {},
			sending: false,
			sendError: null,
		}
	},
	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		validate() {
			this.errors = {}
			if (!this.form.bsn) {
				this.errors.bsn = t(
					'procest',
					'BSN is required for Mijn Overheid messages',
				)
			}
			if (!this.form.subject) {
				this.errors.subject = t('procest', 'Subject is required')
			}
			if (!this.form.body) {
				this.errors.body = t('procest', 'Message body is required')
			}
			return Object.keys(this.errors).length === 0
		},
		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		async send() {
			if (!this.validate()) return
			this.sending = true
			this.sendError = null
			try {
				await sendMessage({
					caseId: this.caseId,
					bsn: this.form.bsn,
					subject: this.form.subject,
					body: this.form.body,
					berichtTypeCode: this.form.berichtTypeCode?.code || '',
				})
				this.$emit('sent')
			} catch (e) {
				this.sendError =
					e.response?.data?.error || t('procest', 'Failed to send message')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.compose-dialog__body {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: inherit;
	resize: vertical;
}

.compose-dialog__char-count {
	color: var(--color-text-maxcontrast);
	float: right;
}

.compose-dialog__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}
</style>
