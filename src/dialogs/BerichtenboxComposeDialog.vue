<template>
	<NcDialog
		v-if="isOpen"
		:name="t('dossiq', 'Send Mijn Overheid Message')"
		size="normal"
		@close="$emit('close')">
		<div class="compose-dialog">
			<div class="form-group">
				<NcTextField
					:modelValue="form.bsn"
					:label="t('dossiq', 'BSN (burgerservicenummer)')"
					:error="!!errors.bsn"
					@update:modelValue="(v) => (form.bsn = v)" />
				<p v-if="errors.bsn" class="form-error">
					{{ errors.bsn }}
				</p>
			</div>

			<div class="form-group">
				<NcTextField
					:modelValue="form.subject"
					:label="t('dossiq', 'Subject')"
					:error="!!errors.subject"
					@update:modelValue="(v) => (form.subject = v)" />
				<p v-if="errors.subject" class="form-error">
					{{ errors.subject }}
				</p>
			</div>

			<div class="form-group">
				<label for="berichtenbox-compose-body">{{
					t('dossiq', 'Message (plain text only)')
				}}</label>
				<textarea
					id="berichtenbox-compose-body"
					v-model="form.body"
					class="compose-dialog__body"
					rows="8"
					:placeholder="t('dossiq', 'Enter your message…')" />
				<small class="compose-dialog__char-count">
					{{ form.body.length }} {{ t('dossiq', 'characters') }}
				</small>
				<p v-if="errors.body" class="form-error">
					{{ errors.body }}
				</p>
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'Bericht type') }}</label>
				<NcSelect
					v-model="form.berichtTypeCode"
					:options="typeCodes"
					:aria-label-combobox="t('dossiq', 'Bericht type')"
					label="label"
					trackBy="code" />
			</div>

			<div class="compose-dialog__actions">
				<NcButton variant="primary" :disabled="sending" @click="send">
					{{ sending ? t('dossiq', 'Sending…') : t('dossiq', 'Send') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="sendError" type="error">
				{{ sendError }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { sendMessage } from '../services/berichtenboxApi.js'

/**
 * Compose one letter to a citizen's digital post, from the case.
 *
 * 🔴 NOTHING OPENED THIS FILE UNTIL NOW. It was referenced nowhere in dossiq:
 * not by src/registry.js, not by src/manifest.json, not by another component.
 * The only occurrence of its name in the repository was its own `name:` line.
 * The CaseDetail `send-digital-post` header action opens it now, and
 * tests/vitest/registryOrphans.spec.js fails on a registered modal that no
 * manifest action names, so it cannot go dark again quietly.
 *
 * 🔴 A REFUSAL DOES NOT CLOSE THIS DIALOG. The send endpoint answers 400 with
 * the provider's own sentence when integriq refused, and `sent` is emitted
 * only on a send that carries a tracked message. A handler told which
 * credential is missing can ask for it; a handler told "sending failed", or
 * shown a dialog that closed, cannot.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
export default {
	name: 'BerichtenboxComposeDialog',
	components: { NcDialog, NcButton, NcTextField, NcSelect, NcNoteCard },
	props: {
		/**
		 * The case this letter is about.
		 *
		 * NOT `required`, and it may arrive as the unresolved `@objectId`
		 * token: `open-modal` forwards an action's props verbatim with no
		 * token resolution, exactly as it does for BeschikkingComposerDialog
		 * and DocumentMetadataDialog. `resolvedCaseId` reads the route in that
		 * case.
		 */
		caseId: { type: String, default: '' },
		bsn: { type: String, default: '' },

		/**
		 * Whether the dialog is showing.
		 *
		 * `open` is accepted beside it because that is the prop every other
		 * registry modal on this page is opened with, and an action declaring
		 * the wrong one of the two would mount a dialog that renders nothing.
		 */
		show: { type: Boolean, default: false },
		open: { type: Boolean, default: false },
	},

	emits: ['close', 'sent'],
	data() {
		return {
			form: { bsn: this.bsn, subject: '', body: '', berichtTypeCode: null },
			typeCodes: [
				{ code: 'decision', label: t('dossiq', 'Decision (Besluit)') },
				{ code: 'status', label: t('dossiq', 'Status update') },
				{ code: 'informatie', label: t('dossiq', 'Information') },
			],

			errors: {},
			sending: false,
			sendError: null,
		}
	},

	computed: {
		/**
		 * The case this letter is about, resolved the same defensive way as
		 * every other open-modal on the case page.
		 *
		 * @return {string} The case id, or ''.
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		/**
		 * Whether the dialog is on screen.
		 *
		 * @return {boolean} True when either prop says so.
		 */
		isOpen() {
			return this.show === true || this.open === true
		},
	},

	watch: {
		isOpen: {
			immediate: true,
			/**
			 * Read the recipient off the case the moment the dialog opens.
			 *
			 * @param {boolean} opened Whether the dialog is showing.
			 * @return {void}
			 * @spec openspec/specs/berichtenbox-integration/spec.md
			 */
			handler(opened) {
				if (opened === true && this.form.bsn === '') {
					this.loadRecipient()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Fill the BSN from the case's requester.
		 *
		 * The case carries the whole initiator projection, and
		 * `initiatorSourceId` is the identifying number the initiator card
		 * looks the party up by: the BSN for a person, the KvK number for a
		 * company. Only a person has a digital post box, so a company case
		 * leaves the field empty and the handler is not handed a KvK number to
		 * send a letter to.
		 *
		 * A read that fails leaves the field empty rather than guessing. The
		 * handler can type the number; a wrong one addressed a letter to
		 * somebody else.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		async loadRecipient() {
			if (this.resolvedCaseId === '') {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.resolvedCaseId)}`,
					),
				)
				if (String(data?.initiatorType || '') !== 'person') {
					return
				}
				this.form.bsn = String(data?.initiatorSourceId || '')
			} catch {
				this.form.bsn = ''
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		validate() {
			this.errors = {}
			if (!this.form.bsn) {
				this.errors.bsn = t(
					'dossiq',
					'BSN is required for Mijn Overheid messages',
				)
			}
			if (!this.form.subject) {
				this.errors.subject = t('dossiq', 'Subject is required')
			}
			if (!this.form.body) {
				this.errors.body = t('dossiq', 'Message body is required')
			}
			return Object.keys(this.errors).length === 0
		},

		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		async send() {
			if (!this.validate()) return
			this.sending = true
			this.sendError = null
			try {
				const answer = await sendMessage({
					caseId: this.resolvedCaseId,
					bsn: this.form.bsn,
					subject: this.form.subject,
					body: this.form.body,
					berichtTypeCode: this.form.berichtTypeCode?.code || '',
				})

				// 🔴 A 200 IS NOT A DELIVERY. The endpoint answers 400 with the
				// provider's sentence when integriq refused, which lands in the
				// catch below, but it can also answer 200 for a message that was
				// recorded without going out. Emitting `sent` on either would
				// close this dialog over a letter nobody received, which is the
				// whole failure this change is about, one layer up.
				if (answer?.success === false || answer?.message?.refused === true) {
					this.sendError =
						answer?.error
						|| answer?.message?.error
						|| t(
							'dossiq',
							'This letter was not sent, and no reason was given.',
						)
					return
				}

				this.$emit('sent')
			} catch (e) {
				// The provider's OWN words, whenever there are any. "Sending
				// failed" tells a handler nothing they can act on; "no
				// PKIoverheid certificate is configured" tells them who to ask.
				this.sendError =
					e.response?.data?.error || t('dossiq', 'Failed to send message')
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
