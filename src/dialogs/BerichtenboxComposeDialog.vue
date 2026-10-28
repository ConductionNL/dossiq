<template>
	<CnDialog
		v-if="isOpen"
		:name="t('dossiq', 'Message to the Berichtenbox')"
		:eyebrow="eyebrow"
		:subtitle="t('dossiq', 'Digital post to the applicant, in MijnOverheid. Only when the applicant is a person.')"
		width="form"
		:noClose="sending"
		@closing="$emit('close')">
		<div class="compose-dialog">
			<div class="compose-dialog__row">
				<div
class="compose-dialog__field"
					@focusin="onBsnFocus"
					@focusout="onBsnBlur">
					<label class="compose-dialog__label" for="berichtenbox-compose-bsn">
						{{ t('dossiq', 'BSN') }}
						<span v-if="bsnFromCase" class="compose-dialog__optional" data-testid="compose-bsn-optional">{{ t('dossiq', '(optional)') }}</span>
					</label>
					<NcTextField
						id="berichtenbox-compose-bsn"
						:modelValue="shownBsn"
						:placeholder="bsnEditing && caseBsn ? maskedCaseBsn : ''"
						:label="t('dossiq', 'BSN')"
						:labelOutside="true"
						:error="!!errors.bsn"
						@update:modelValue="(v) => (form.bsn = v)" />
					<span v-if="errors.bsn" class="form-error">{{ errors.bsn }}</span>
					<span v-else-if="recipientName" class="compose-dialog__hint">
						{{ t('dossiq', '{name}, from the case. Not shown to anyone.', { name: recipientName }) }}
					</span>
				</div>
				<div class="compose-dialog__field">
					<label class="compose-dialog__label" for="berichtenbox-compose-type">
						{{ t('dossiq', 'Kind of message') }}
						<span class="compose-dialog__optional">{{ t('dossiq', '(optional)') }}</span>
					</label>
					<NcSelect
						v-model="form.berichtTypeCode"
						inputId="berichtenbox-compose-type"
						:options="typeCodes"
						:inputLabel="t('dossiq', 'Kind of message')"
						:labelOutside="true"
						label="label"
						trackBy="code" />
				</div>
			</div>

			<div class="compose-dialog__check">
				<NcCheckboxRadioSwitch
					:modelValue="form.isBesluit"
					type="checkbox"
					@update:modelValue="(v) => (form.isBesluit = v)">
					{{
						t(
							'dossiq',
							'This is a besluit (always sent, no unsubscribe link)',
						)
					}}
				</NcCheckboxRadioSwitch>
				<span class="compose-dialog__hint compose-dialog__check-note">
					{{ t('dossiq', 'On for the kind Decision. A resident who unsubscribed from messages still receives a decision.') }}
				</span>
			</div>

			<MessageTemplateSelect
				:caseId="resolvedCaseId"
				:caseTypeId="caseTypeId"
				:channels="templateChannels"
				inputId="berichtenbox-compose-template"
				@fill="onTemplateFill" />

			<div class="compose-dialog__field">
				<label class="compose-dialog__label" for="berichtenbox-compose-subject">{{ t('dossiq', 'Subject') }}</label>
				<NcTextField
					id="berichtenbox-compose-subject"
					:modelValue="form.subject"
					:label="t('dossiq', 'Subject')"
					:labelOutside="true"
					:error="!!errors.subject"
					@update:modelValue="(v) => (form.subject = v)" />
				<span v-if="errors.subject" class="form-error">{{ errors.subject }}</span>
			</div>

			<div class="compose-dialog__field">
				<label class="compose-dialog__label" for="berichtenbox-compose-body">{{
					t('dossiq', 'Message, plain text only')
				}}</label>
				<textarea
					id="berichtenbox-compose-body"
					v-model="form.body"
					class="compose-dialog__body"
					rows="8"
					:maxlength="maxBody"
					:placeholder="t('dossiq', 'Enter your message…')" />
				<span class="compose-dialog__hint compose-dialog__char-count">
					{{ t('dossiq', '{count} of {max} characters', { count: form.body.length, max: maxBody }) }}
				</span>
				<span v-if="errors.body" class="form-error">{{ errors.body }}</span>
			</div>

			<NcNoteCard type="info">
				<template #icon>
					<EmailFastOutline :size="20" />
				</template>
				{{ t('dossiq', 'The message goes through integriq to the Berichtenbox of MijnOverheid. Delivered and read come back on the case, under Contact and History.') }}
			</NcNoteCard>

			<div v-if="sendError" role="alert">
				<NcNoteCard type="error">
					{{ sendError }}
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="sending" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="sending" @click="send">
				<template #icon>
					<SendIcon :size="20" />
				</template>
				{{ sending ? t('dossiq', 'Sending…') : t('dossiq', 'Send the message') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import EmailFastOutline from 'vue-material-design-icons/EmailFastOutline.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import MessageTemplateSelect from '../components/case/MessageTemplateSelect.vue'
import { sendMessage } from '../services/berichtenboxApi.js'
import { caseEyebrow } from '../services/caseSummary.js'

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
	components: {
		CnDialog,
		EmailFastOutline,
		MessageTemplateSelect,
		SendIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcSelect,
		NcNoteCard,
	},

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
		const typeCodes = [
			{ code: 'decision', label: t('dossiq', 'Decision (Besluit)') },
			{ code: 'status', label: t('dossiq', 'Status update') },
			{ code: 'informatie', label: t('dossiq', 'Information') },
		]
		return {
			form: {
				bsn: this.bsn,
				subject: '',
				body: '',
				// A letter from a running case is most often a status update.
				berichtTypeCode: typeCodes.find((type) => type.code === 'status'),
				isBesluit: false,
			},

			typeCodes,

			kase: null,
			// The requester's BSN as the case holds it, and whether the field is
			// being typed in: the number is sent but never drawn in full.
			caseBsn: '',
			bsnEditing: false,
			maxBody: 4000,
			errors: {},
			sending: false,
			sendError: null,
		}
	},

	computed: {
		/**
		 * Whether the BSN is the case's requester's, so the handler need not type one.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		bsnFromCase() {
			return this.caseBsn !== '' && this.form.bsn === this.caseBsn
		},

		/**
		 * The case's BSN with all but its last four digits hidden: "*****7609".
		 *
		 * @return {string}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		maskedCaseBsn() {
			const bsn = this.caseBsn
			return bsn.length > 4 ? '*'.repeat(bsn.length - 4) + bsn.slice(-4) : bsn
		},

		/**
		 * What the BSN field shows: the case's number masked, or what the
		 * handler types.
		 *
		 * @return {string}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		shownBsn() {
			return this.bsnFromCase && !this.bsnEditing ? this.maskedCaseBsn : this.form.bsn
		},

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
		eyebrow() {
			return caseEyebrow(t('dossiq', 'Message'), this.kase)
		},

		/**
		 * The case's case type, whose message templates are offered.
		 *
		 * @return {string} Its id, or ''.
		 * @spec openspec/changes/compose-from-message-template/specs/case-messages/spec.md#requirement-choosing-a-message-template-fills-the-message
		 */
		caseTypeId() {
			const ref = this.kase?.caseType
			return String((ref && typeof ref === 'object' ? ref.id : ref) || '')
		},

		/**
		 * The channels a Berichtenbox letter goes out on, for the template choice.
		 *
		 * @return {Array<string>}
		 */
		templateChannels() {
			return ['mailbox', 'mailbox-then-email']
		},

		recipientName() {
			return String(this.kase?.initiatorDisplayName || '')
		},

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
		 * Put a chosen template, filled for this case, in the subject and the
		 * message. Both stay editable.
		 *
		 * @param {{subject: string, body: string}} draft The filled template.
		 * @return {void}
		 * @spec openspec/changes/compose-from-message-template/specs/case-messages/spec.md#requirement-choosing-a-message-template-fills-the-message
		 */
		onTemplateFill({ subject, body }) {
			this.form.subject = subject
			this.form.body = body
		},

		/**
		 * Focus on the BSN field: a masked number cannot be edited, so the field
		 * empties for another number, the masked one as its placeholder.
		 *
		 * @return {void}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		onBsnFocus() {
			if (this.bsnFromCase) {
				this.bsnEditing = true
				this.form.bsn = ''
			}
		},

		/**
		 * Leaving the BSN field empty goes back to the case's number.
		 *
		 * @return {void}
		 * @spec openspec/specs/berichtenbox-integration/spec.md
		 */
		onBsnBlur() {
			if (this.bsnEditing && this.form.bsn.trim() === '') {
				this.form.bsn = this.caseBsn
			}
			this.bsnEditing = false
		},

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
				this.kase = data || null
				if (String(data?.initiatorType || '') !== 'person') {
					return
				}
				this.caseBsn = String(data?.initiatorSourceId || '')
				if (this.form.bsn === '') {
					this.form.bsn = this.caseBsn
				}
			} catch {
				this.form.bsn = ''
			}
		},

		/**
		 * The sentence a handler reads for an opt-out refusal, or null.
		 *
		 * @param {string|undefined} code integriq's refusal code.
		 * @return {string|null} The sentence.
		 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-digital-post-carries-a-category-to-integriq-req-coo-004
		 */
		refusalText(code) {
			if (code === 'opted-out') {
				return t(
					'dossiq',
					'This person asked not to receive updates about this case. Nothing was sent.',
				)
			}
			if (code === 'authority-unavailable') {
				return t(
					'dossiq',
					'integriq is not available, so dossiq cannot check whether this person may be messaged. Nothing was sent.',
				)
			}
			return null
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
					// The category decides, not the channel: a besluit is always
					// delivered, a case update respects the citizen's opt-out.
					category: this.form.isBesluit ? 'besluit' : 'case-update',
				})

				// 🔴 A 200 IS NOT A DELIVERY. The endpoint answers 400 with the
				// provider's sentence when integriq refused, which lands in the
				// catch below, but it can also answer 200 for a message that was
				// recorded without going out. Emitting `sent` on either would
				// close this dialog over a letter nobody received, which is the
				// whole failure this change is about, one layer up.
				if (answer?.success === false || answer?.message?.refused === true) {
					this.sendError =
						this.refusalText(answer?.code || answer?.message?.code)
						|| answer?.error
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
					this.refusalText(e.response?.data?.code)
					|| e.response?.data?.error
					|| t('dossiq', 'Failed to send message')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.compose-dialog {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.compose-dialog__row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
}

.compose-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.compose-dialog__label {
	font-size: 14px;
	font-weight: 600;
	line-height: normal;
}

.compose-dialog__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.compose-dialog__hint {
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}

/* The board's fields sit on the form's 18px gap alone: the server's 3px
   input margin, NcTextField's own margin and the note card's 15px margin
   come off, as DqBerichtVersturen draws them. */
.compose-dialog :deep(.input-field) {
	margin-block: -2px;
}

.compose-dialog > :deep(.notecard) {
	margin: 0;
}

.compose-dialog textarea.compose-dialog__body {
	margin: 0;
}

/* A field takes the board's 40px in the flow: NcTextField and NcSelect hold
   2px of focus-ring room above and below the box, which put every row 4px
   lower than DqBerichtVersturen; NcSelect's own 6px/4px margins came on top. */
.compose-dialog__field .v-select.select {
	margin: -2px 0;
}

.compose-dialog__check {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

/* The board draws the decision box as a plain 15px line with its box, the
   note 4px under it (DqBerichtVersturen): NcCheckboxRadioSwitch pads its row
   to 34px and sets the label at 500 on the body's 22.5px leading, and the
   server's input rule gives the hidden input the clickable height. */
.compose-dialog__check :deep(.checkbox-content) {
	padding-block: 0;
	min-height: 0;
}

.compose-dialog__check :deep(.checkbox-content__icon) {
	margin-block: 0;
}

.compose-dialog__check :deep(.checkbox-radio-switch),
.compose-dialog__check :deep(.checkbox-content__wrapper),
.compose-dialog__check :deep(.checkbox-radio-switch__text) {
	font-weight: 400;
	line-height: normal;
}

.compose-dialog__check :deep(.checkbox-radio-switch__input) {
	min-height: 0;
	height: 20px;
	margin: 0;
}

.compose-dialog__check-note {
	padding-inline-start: var(--cn-dialog-check-label-inset, 44px);
	line-height: normal;
}

.compose-dialog__body {
	box-sizing: border-box;
	width: 100%;
	min-height: 0;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	font: inherit;
	font-size: 15px;
	line-height: 1.2;
	resize: vertical;
}

.form-error {
	font-size: 13px;
	color: var(--color-error-text, var(--color-error));
}
</style>
