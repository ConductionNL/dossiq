<template>
	<div class="berichtenbox-tab">
		<div class="berichtenbox-tab__header">
			<h4>{{ t('procest', 'Mijn Overheid Messages') }}</h4>
			<NcButton variant="primary" @click="showCompose = true">
				{{ t('procest', 'New message') }}
			</NcButton>
		</div>

		<div v-if="messages.length === 0" class="berichtenbox-tab__empty">
			{{ t('procest', 'No messages sent via Mijn Overheid.') }}
		</div>

		<div
			v-for="msg in messages"
			:key="msg.uuid || msg.id"
			class="berichtenbox-tab__message">
			<div class="berichtenbox-tab__message-info">
				<strong>{{ msg.subject }}</strong>
				<span
					class="berichtenbox-tab__status"
					:class="`status--${msg.status}`">
					{{ statusLabel(msg.status) }}
				</span>
			</div>
			<div class="berichtenbox-tab__message-meta">
				<small
					>{{ t('procest', 'Sent') }}: {{ formatDate(msg.sentAt) }}</small
				>
				<small v-if="msg.readAt"
					>{{ t('procest', 'Read') }}: {{ formatDate(msg.readAt) }}</small
				>
			</div>
		</div>

		<BerichtenboxComposeDialog
			v-if="showCompose"
			:caseId="caseId"
			:bsn="caseBsn"
			:show="showCompose"
			@close="showCompose = false"
			@sent="onSent" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import BerichtenboxComposeDialog from '../../../dialogs/BerichtenboxComposeDialog.vue'
import { listMessages } from '../../../services/berichtenboxApi.js'

export default {
	name: 'BerichtenboxTab',
	components: { NcButton, BerichtenboxComposeDialog },
	props: {
		caseId: { type: String, required: true },
		caseBsn: { type: String, default: '' },
	},

	data() {
		return { messages: [], showCompose: false }
	},

	async mounted() {
		await this.loadMessages()
	},

	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		async loadMessages() {
			try {
				const response = await listMessages(this.caseId)
				this.messages = response.messages || []
			} catch (e) {
				this.messages = []
			}
		},

		/**
		 * @param dt
		 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
		 */
		formatDate(dt) {
			if (!dt) return '-'
			return new Date(dt).toLocaleString('nl-NL', {
				dateStyle: 'short',
				timeStyle: 'short',
			})
		},

		/**
		 * @param status
		 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
		 */
		statusLabel(status) {
			const labels = {
				draft: t('procest', 'Draft'),
				sent: t('procest', 'Sent'),
				delivered: t('procest', 'Delivered'),
				read: t('procest', 'Read'),
				failed: t('procest', 'Failed'),
				unread_flagged: t('procest', 'Unread (>7 days)'),
			}
			return labels[status] || status
		},

		/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
		async onSent() {
			this.showCompose = false
			await this.loadMessages()
		},
	},
}
</script>

<style scoped>
.berichtenbox-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.berichtenbox-tab__message {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.berichtenbox-tab__status {
	margin-left: 8px;
	font-size: 12px;
	font-weight: 600;
}

.status--sent {
	color: var(--color-primary);
}

.status--read {
	color: var(--color-success);
}

.status--failed {
	color: var(--color-error);
}

.status--unread_flagged {
	color: var(--color-warning);
}

.berichtenbox-tab__message-meta small {
	display: block;
	color: var(--color-text-maxcontrast);
}

.berichtenbox-tab__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
