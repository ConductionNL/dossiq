<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Citizen ↔ case-handler messaging thread for the "Mijn gemeente" portal
  - (zaakportaal-mijngemeente, REQ-POR-007). Loads the thread for a single case
  - from the IDOR-safe GET /api/portaal/messages?caseId=… endpoint and posts new
  - messages via POST /api/portaal/messages. The sender's identity is never sent
  - from the client — the backend derives the pseudonymous senderRef from the
  - session. All validation + body shaping is delegated to the pure helpers in
  - utils/portaalForms.js.
-->
<template>
	<section class="zp-messages" data-testid="portaal-messaging-widget">
		<h3>{{ t('procest', 'Messages') }}</h3>

		<div v-if="loading" class="zp-messages__state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="loadError"
			class="zp-messages__state zp-messages__state--error"
			role="alert"
			data-testid="portaal-messaging-error">
			{{ loadError }}
		</div>

		<ol v-else-if="messages.length" class="zp-messages__list" data-testid="portaal-messaging-list">
			<li v-for="(m, i) in messages"
				:key="m.id || m.uuid || i"
				:class="['zp-messages__bubble', isOutbound(m) ? 'zp-messages__bubble--out' : 'zp-messages__bubble--in']"
				data-testid="portaal-messaging-bubble">
				<header class="zp-messages__bubble-head">
					<span class="zp-messages__sender">{{ senderLabel(m) }}</span>
					<time class="zp-messages__time">{{ m.sentAt || m.verzondenOp || '—' }}</time>
				</header>
				<p class="zp-messages__body">
					{{ m.content || m.inhoud }}
				</p>
			</li>
		</ol>

		<p v-else class="zp-messages__empty" data-testid="portaal-messaging-empty">
			{{ t('procest', 'No messages yet. Send a message to your case handler below.') }}
		</p>

		<form class="zp-messages__composer" data-testid="portaal-messaging-form" @submit.prevent="onSend">
			<label for="zp-msg-body" class="zp-messages__label">
				{{ t('procest', 'New message') }}
			</label>
			<textarea id="zp-msg-body"
				v-model="content"
				rows="3"
				:maxlength="MAX_MESSAGE_LENGTH"
				class="zp-messages__textarea"
				data-testid="portaal-messaging-input"
				:placeholder="t('procest', 'Type your message…')" />

			<p v-if="fieldError"
				class="zp-messages__field-error"
				role="alert"
				data-testid="portaal-messaging-validation">
				{{ fieldError }}
			</p>

			<div class="zp-messages__actions">
				<NcButton type="primary"
					native-type="submit"
					:disabled="sending || !content.trim()"
					data-testid="portaal-messaging-submit">
					{{ sending ? t('procest', 'Sending…') : t('procest', 'Send message') }}
				</NcButton>
				<p v-if="sentMessage"
					class="zp-messages__status"
					role="status"
					data-testid="portaal-messaging-status">
					{{ sentMessage }}
				</p>
			</div>
		</form>
	</section>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { validateMessage, buildMessagePayload, MAX_MESSAGE_LENGTH } from '../../../utils/portaalForms.js'

export default {
	name: 'MessagingWidget',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		/** The case id the thread belongs to. */
		caseId: {
			type: String,
			required: true,
		},
		/** The human-readable case reference (kenmerk), used on the new message. */
		caseReference: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			MAX_MESSAGE_LENGTH,
			messages: [],
			loading: false,
			loadError: '',
			content: '',
			fieldError: '',
			sending: false,
			sentMessage: '',
		}
	},
	watch: {
		caseId: 'loadThread',
	},
	mounted() {
		this.loadThread()
	},
	methods: {
		/**
		 * Load the message thread for the current case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		async loadThread() {
			if (!this.caseId) {
				this.messages = []
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const url = generateUrl('/apps/procest/api/portaal/messages')
				const { data } = await axios.get(url, { params: { caseId: this.caseId } })
				this.messages = (data && data.results) || []
			} catch (e) {
				this.loadError = this.t('procest', 'Could not load messages for this case.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Whether a message was sent by the citizen (outbound).
		 *
		 * @param {object} m The message.
		 * @return {boolean}
		 */
		isOutbound(m) {
			return (m && m.direction) === 'citizen_to_handler'
		},
		/**
		 * Display label for a message sender.
		 *
		 * @param {object} m The message.
		 * @return {string}
		 */
		senderLabel(m) {
			return this.isOutbound(m) ? this.t('procest', 'You') : (m.senderName || this.t('procest', 'Case handler'))
		},
		/**
		 * Validate and submit a new message (REQ-POR-007).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
		 */
		async onSend() {
			this.fieldError = ''
			this.sentMessage = ''
			const { valid, errors } = validateMessage({ caseId: this.caseId, content: this.content })
			if (!valid) {
				this.fieldError = this.translateError(errors.content || errors.caseId)
				return
			}
			this.sending = true
			try {
				const url = generateUrl('/apps/procest/api/portaal/messages')
				const payload = buildMessagePayload({
					caseId: this.caseId,
					caseReference: this.caseReference,
					content: this.content,
				})
				await axios.post(url, payload)
				this.sentMessage = this.t('procest', 'Your message has been sent.')
				this.content = ''
				await this.loadThread()
			} catch (e) {
				this.fieldError = (e && e.response && e.response.data && e.response.data.error)
					? String(e.response.data.error)
					: this.t('procest', 'Could not send your message. Please try again.')
			} finally {
				this.sending = false
			}
		},
		/**
		 * Translate an English error key emitted by the validator.
		 *
		 * @param {string} key The English source string.
		 * @return {string}
		 */
		translateError(key) {
			return this.t('procest', key)
		},
	},
}
</script>

<style scoped>
.zp-messages {
	margin-top: 24px;
}

.zp-messages__state {
	padding: 24px;
	text-align: center;
}

.zp-messages__state--error {
	color: var(--color-error, #c4341f);
}

.zp-messages__list {
	list-style: none;
	padding: 0;
	margin: 0 0 16px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.zp-messages__bubble {
	padding: 12px 16px;
	border-radius: 8px;
	border: 1px solid var(--color-border, #d0d0d0);
	max-width: 80%;
}

.zp-messages__bubble--in {
	background: var(--color-background-hover, #f0f5fb);
	align-self: flex-start;
}

.zp-messages__bubble--out {
	background: var(--color-main-background, #fff);
	align-self: flex-end;
}

.zp-messages__bubble-head {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.zp-messages__sender {
	font-weight: 600;
}

.zp-messages__body {
	margin: 0;
	white-space: pre-wrap;
}

.zp-messages__empty {
	color: var(--color-text-maxcontrast, #6b6b6b);
	padding: 8px 0 16px;
}

.zp-messages__composer {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.zp-messages__label {
	font-weight: 600;
	font-size: 13px;
}

.zp-messages__textarea {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #aaa);
	border-radius: var(--border-radius, 4px);
	font-family: inherit;
	resize: vertical;
	min-height: 60px;
}

.zp-messages__field-error {
	margin: 0;
	color: var(--color-error, #c4341f);
	font-size: 13px;
}

.zp-messages__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.zp-messages__status {
	margin: 0;
	color: var(--color-success, #46ba61);
	font-size: 13px;
}
</style>
