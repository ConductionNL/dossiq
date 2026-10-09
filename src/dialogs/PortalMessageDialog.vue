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
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

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
 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
 */
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
	},

	emits: ['close', 'sent'],
	data() {
		return {
			form: {
				subject: this.subject ? `Re: ${this.subject}` : '',
				content: '',
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
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return this.$route?.params?.id || ''
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
		 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
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
		 * Write the message to the applicant's inbox.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#5-the-handler-side
		 */
		async send() {
			this.error = ''
			if (this.form.content.trim() === '') {
				this.error = t('dossiq', 'Write a message first.')
				return
			}
			const recipient = String(this.kase?.portalSubject || '')
			if (recipient === '') {
				this.error = t(
					'dossiq',
					'This case has no portal account to write to. Nothing was sent.',
				)
				return
			}
			const user = getCurrentUser()
			const payload = {
				caseId: this.resolvedCaseId,
				caseReference: String(this.kase?.identifier || ''),
				recipientRef: recipient,
				senderRef: user?.uid || '',
				senderType: 'medewerker',
				senderName: user?.displayName || user?.uid || '',
				subject: this.form.subject.trim(),
				content: this.form.content.trim(),
				direction: 'handler_to_citizen',
				sentAt: new Date().toISOString(),
			}
			this.sending = true
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/openregister/api/objects/dossiq/portaalBericht',
					),
					payload,
				)
				this.$emit('sent', data)
				this.$emit('close')
			} catch {
				this.error = t(
					'dossiq',
					'The message could not be sent. Try again in a moment.',
				)
			} finally {
				this.sending = false
			}
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
