<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="email-tab">
		<div class="email-tab__header">
			<h4>
				{{ t('procest', 'Email') }}
				<span v-if="totalCount > 0" class="email-tab__count">{{ totalCount }}</span>
			</h4>
			<NcButton
				type="primary"
				:disabled="isFinal"
				@click="openComposer">
				{{ t('procest', 'Verstuur email') }}
			</NcButton>
		</div>

		<div v-if="loading" class="email-tab__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="threads.length === 0" class="email-tab__empty">
			{{ t('procest', 'No email messages for this case.') }}
		</div>

		<div v-else class="email-tab__threads">
			<div
				v-for="thread in threads"
				:key="thread.id || thread.uuid"
				class="email-tab__thread">
				<div
					class="email-tab__thread-header"
					@click="toggleThread(thread.id || thread.uuid)">
					<strong>{{ thread.subject }}</strong>
					<span class="email-tab__badge">{{ thread.messageCount || (thread.messages && thread.messages.length) || 0 }}</span>
					<span class="email-tab__thread-date">{{ formatDate(thread.lastMessageAt) }}</span>
				</div>

				<div v-if="openThreads.includes(thread.id || thread.uuid)" class="email-tab__thread-messages">
					<EmailThread
						:messages="thread.messages || []"
						:case-id="caseId"
						@reply="onReply" />
				</div>
			</div>
		</div>

		<div
			v-if="showComposer"
			class="email-tab__composer-overlay"
			@click.self="showComposer = false">
			<div class="email-tab__composer-panel">
				<EmailComposer
					:case-id="caseId"
					:case-data="object"
					:templates="templates"
					:default-to="defaultRecipient"
					:in-reply-to="replyToMessageId"
					@send="onSend"
					@cancel="showComposer = false" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import EmailThread from '../../views/cases/components/EmailThread.vue'
import EmailComposer from '../../views/cases/components/EmailComposer.vue'

export default {
	name: 'EmailTab',
	components: { NcButton, NcLoadingIcon, EmailThread, EmailComposer },
	props: {
		object: { type: Object, default: () => ({}) },
	},
	data() {
		return {
			threads: [],
			templates: [],
			loading: false,
			showComposer: false,
			openThreads: [],
			replyToMessageId: null,
		}
	},
	computed: {
		caseId() {
			return this.object?.id || this.object?.uuid || ''
		},
		isFinal() {
			const finalStatuses = ['gesloten', 'closed', 'afgehandeld', 'archived']
			return finalStatuses.includes((this.object?.status || '').toLowerCase())
		},
		totalCount() {
			return this.threads.reduce((sum, t) => sum + (t.messageCount || (t.messages && t.messages.length) || 0), 0)
		},
		defaultRecipient() {
			return this.object?.contactEmail || this.object?.email || ''
		},
	},
	mounted() {
		if (this.caseId) {
			this.loadEmails()
			this.loadTemplates()
		}
	},
	methods: {
		async loadEmails() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/emails')
				const { data } = await axios.get(url)
				this.threads = data.threads || []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to load emails', e)
			} finally {
				this.loading = false
			}
		},
		async loadTemplates() {
			if (!this.object?.caseType) return
			try {
				const url = generateUrl('/apps/procest/api/casetypes/' + encodeURIComponent(this.object.caseType) + '/email-templates')
				const { data } = await axios.get(url)
				this.templates = data.results || []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to load email templates', e)
			}
		},
		openComposer() {
			this.replyToMessageId = null
			this.showComposer = true
		},
		onReply(messageId) {
			this.replyToMessageId = messageId
			this.showComposer = true
		},
		async onSend(payload) {
			try {
				const url = generateUrl('/apps/procest/api/cases/' + encodeURIComponent(this.caseId) + '/emails')
				await axios.post(url, payload)
				this.showComposer = false
				await this.loadEmails()
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to send email', e)
			}
		},
		toggleThread(threadId) {
			const idx = this.openThreads.indexOf(threadId)
			if (idx === -1) {
				this.openThreads.push(threadId)
			} else {
				this.openThreads.splice(idx, 1)
			}
		},
		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
.email-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.email-tab__count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-radius: 12px;
	padding: 0 8px;
	font-size: 0.75rem;
	margin-left: 6px;
	min-width: 20px;
	height: 20px;
}

.email-tab__thread {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.email-tab__thread-header {
	display: flex;
	align-items: center;
	padding: 8px 12px;
	cursor: pointer;
	gap: 8px;
}

.email-tab__thread-header:hover {
	background: var(--color-background-hover);
}

.email-tab__badge {
	background: var(--color-background-dark);
	border-radius: 10px;
	padding: 0 6px;
	font-size: 0.75rem;
}

.email-tab__thread-date {
	margin-left: auto;
	font-size: 0.75rem;
	color: var(--color-text-lighter);
}

.email-tab__thread-messages {
	border-top: 1px solid var(--color-border);
	padding: 8px 12px;
}

.email-tab__composer-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 200;
}

.email-tab__composer-panel {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 680px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
}
</style>
