<template>
	<NcDialog
		v-if="isOpen"
		:name="t('dossiq', 'Message the applicant')"
		size="normal"
		data-testid="portal-message-dialog"
		@close="$emit('close')">
		<div class="portal-message">
			<p class="portal-message__intro">
				{{
					t(
						'dossiq',
						'The applicant reads this in their portal inbox and on the case page.',
					)
				}}
			</p>
			<NcTextField
				:modelValue="form.subject"
				:label="t('dossiq', 'Subject')"
				data-testid="portal-message-subject"
				@update:modelValue="(v) => (form.subject = v)" />
			<NcTextArea
				:modelValue="form.content"
				:label="t('dossiq', 'Message')"
				:error="error !== '' && form.content.trim() === ''"
				rows="8"
				data-testid="portal-message-content"
				@update:modelValue="(v) => (form.content = v)" />
			<div v-if="error" role="alert">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>
			<div class="portal-message__actions">
				<NcButton
					variant="primary"
					:disabled="sending"
					data-testid="portal-message-send"
					@click="send">
					{{ sending ? t('dossiq', 'Sending…') : t('dossiq', 'Send') }}
				</NcButton>
				<NcButton @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { logContactMoment } from '../services/pipelinqCaseApi.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

/**
 * The handler writes to the applicant of a case, in the portal.
 *
 * 🔴 `recipientRef` IS THE CASE'S `portalSubject`, NEVER A USER ID. The
 * resident's inbox is scoped on `recipientRef`, so a message carrying the
 * handler's uid there reaches nobody, and one carrying another resident's
 * reference reaches the wrong person. The value is read off the case the
 * moment the dialog opens, and a case without one sends nothing.
 *
 * The message is a `portaalBericht` written straight into OpenRegister:
 * `PortalMessageTimelineListener` puts it on the case timeline as a public
 * entry, and portaliq shows it in the inbox. Opened from the timeline's Reply
 * on a resident's message, it emits `sent` so the timeline closes that
 * message's follow-up; a refusal keeps the dialog open with the reason.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
/**
 * The portal account a case's messages go to.
 *
 * @param {object|null} kase The case.
 * @return {string} The portal subject, or ''.
 */
function portalRecipient(kase) {
	return String(kase?.portalSubject || '')
}

/**
 * The sender fields for the current user.
 *
 * @param {object|null} user The current user.
 * @return {{senderRef: string, senderType: string, senderName: string}} The sender.
 */
function senderOf(user) {
	return {
		senderRef: user?.uid || '',
		senderType: 'medewerker',
		senderName: user?.displayName || user?.uid || '',
	}
}

export default {
	name: 'PortalMessageDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/**
		 * The case the message is about. May arrive as the unresolved
		 * `@objectId` token: `open-modal` forwards props verbatim, so
		 * `resolvedCaseId` reads the route then.
		 */
		caseId: { type: String, default: '' },
		/** Whether the dialog is showing. */
		open: { type: Boolean, default: false },
		/** The subject of the message being answered, or '' for a new message. */
		subject: { type: String, default: '' },
		/** A reply already written elsewhere (the case's conversation thread), to start the message with. */
		text: { type: String, default: '' },
		/** Also log the message as an outgoing contact moment on the case (the conversation's reply switch). */
		logContactMoment: { type: Boolean, default: false },
	},

	emits: ['close', 'sent'],
	data() {
		return {
			form: {
				subject: this.subject ? `Re: ${this.subject}` : '',
				content: this.text,
			},

			kase: null,
			sending: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The case id, from the prop or else from the route.
		 *
		 * @return {string} The case id, or ''.
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		resolvedCaseId() {
			return resolveCaseId(this.caseId, this.$route)
		},

		/**
		 * Whether the dialog is on screen.
		 *
		 * @return {boolean}
		 */
		isOpen() {
			return this.open === true
		},
	},

	watch: {
		isOpen: {
			immediate: true,
			/**
			 * Read the case the moment the dialog opens.
			 *
			 * @param {boolean} opened Whether the dialog is showing.
			 * @return {void}
			 * @spec openspec/specs/portal-contribution/spec.md
			 */
			handler(opened) {
				if (opened === true) {
					this.loadCase()
				}
			},
		},
	},

	methods: {
		t,
		/**
		 * Read the case: its portal subject and its reference.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async loadCase() {
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
			} catch {
				this.kase = null
			}
		},

		/**
		 * Log a sent message as an outgoing contact moment on the case,
		 * visible to the applicant, through the case's own contact route.
		 *
		 * @param {object} message The message as it was sent.
		 * @return {Promise<boolean>} Whether it was logged.
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async logAsContactMoment(message) {
			try {
				await logContactMoment(this.resolvedCaseId, {
					notificationChannel: 'webformulier',
					direction: 'outbound',
					startTime: message.sentAt,
					summary: message.content,
					visibleToApplicant: true,
				})
				return true
			} catch {
				return false
			}
		},

		/**
		 * Write the message to the applicant's inbox.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async send() {
			this.error = this.sendRefusal()
			if (this.error !== '') {
				return
			}
			await this.sendPayload(this.messagePayload())
		},

		/**
		 * Send the message while showing that it is being sent.
		 *
		 * @param {object} payload The message.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async sendPayload(payload) {
			this.sending = true
			try {
				await this.postMessage(payload)
			} catch {
				this.error = t(
					'dossiq',
					'The message could not be sent. Try again in a moment.',
				)
			} finally {
				this.sending = false
			}
		},

		/**
		 * Why nothing is sent, or '' when the message can go.
		 *
		 * @return {string} The sentence.
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		sendRefusal() {
			if (this.form.content.trim() === '') {
				return t('dossiq', 'Write a message first.')
			}
			if (portalRecipient(this.kase) === '') {
				return t(
					'dossiq',
					'This case has no portal account to write to. Nothing was sent.',
				)
			}
			return ''
		},

		/**
		 * The inbox message the form describes, sent now by the current user.
		 *
		 * @return {object} The message.
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		messagePayload() {
			return {
				caseId: this.resolvedCaseId,
				caseReference: String(this.kase?.identifier || ''),
				recipientRef: portalRecipient(this.kase),
				...senderOf(getCurrentUser()),
				subject: this.form.subject.trim(),
				content: this.form.content.trim(),
				direction: 'handler_to_citizen',
				sentAt: new Date().toISOString(),
			}
		},

		/**
		 * Write the message, then follow it up.
		 *
		 * @param {object} payload The message.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async postMessage(payload) {
			const { data } = await axios.post(
				generateUrl(
					'/apps/openregister/api/objects/dossiq/portaalBericht',
				),
				payload,
			)
			await this.afterSent(data, payload)
		},

		/**
		 * Tell the page a message was sent, log it when asked to, and close.
		 *
		 * @param {object} data What the write answered.
		 * @param {object} payload The message as it was sent.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-contribution/spec.md
		 */
		async afterSent(data, payload) {
			this.$emit('sent', data)
			// A reply saved as a draft on this case is now sent (the conversation widget clears it).
			emit('cn:conversation:reply-sent', { objectId: this.resolvedCaseId })
			if (this.logContactMoment === true && await this.logAsContactMoment(payload) === false) {
				this.error = t(
					'dossiq',
					'The message was sent, but it could not be logged as a contact moment.',
				)
				return
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.portal-message {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.portal-message__intro {
	color: var(--color-text-maxcontrast);
}

.portal-message__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
