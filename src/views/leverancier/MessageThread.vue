<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Per-case message thread — leverancier-zaakportaal chain member 11.
  -
  - Renders the chronological thread (`SupplierMessageService::getConversationHistory()`)
  - with inbound (light-bg) vs outbound (white-bg) bubbles, and a
  - composer that posts a new message via the new sendMessage()
  - endpoint. Attachment uploads are deferred — the composer accepts
  - body text only for now.
  -
  - @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
  -->
<template>
	<div class="lz-thread" data-testid="leverancier-message-thread">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'Bericht thread') }}</h1>
			<p v-if="!caseRef" class="lz-section-intro">
				{{ t('procest', 'Geef een caseRef op via ?caseRef=… om een gesprek te openen.') }}
			</p>
		</header>

		<div v-if="loading" data-testid="lz-loading" class="lz-state">
			<NcLoadingIcon :size="24" />
		</div>

		<div v-else-if="error" data-testid="lz-error" class="lz-state lz-state--error" role="alert">
			{{ error }}
		</div>

		<ol v-else-if="messages.length"
			class="lz-bubbles"
			data-testid="leverancier-message-bubbles">
			<li v-for="m in messages"
				:key="m.id || m.uuid || (m.timestamp + m.sentBy)"
				:class="['lz-bubble', isInbound(m) ? 'lz-bubble--inbound' : 'lz-bubble--outbound']"
				data-testid="leverancier-message-bubble">
				<header class="lz-bubble-header">
					<span class="lz-bubble-sender">{{ m.sentBy || t('procest', 'Onbekend') }}</span>
					<time class="lz-bubble-time">{{ m.timestamp || m.sentAt || '—' }}</time>
				</header>
				<p class="lz-bubble-body">{{ m.body }}</p>
				<ul v-if="m.attachments && m.attachments.length" class="lz-bubble-attachments">
					<li v-for="a in m.attachments" :key="a.ref || a.id">
						<a :href="a.url || '#'">{{ a.name || a.ref }}</a>
					</li>
				</ul>
			</li>
		</ol>

		<p v-else-if="caseRef" class="lz-empty" data-testid="lz-empty">
			{{ t('procest', 'Nog geen berichten in dit gesprek.') }}
		</p>

		<form v-if="caseRef"
			class="lz-composer"
			data-testid="leverancier-message-composer"
			@submit.prevent="onSend">
			<label for="lz-msg-body" class="lz-composer-label">{{ t('procest', 'Nieuw bericht') }}</label>
			<textarea id="lz-msg-body"
				v-model="composer.body"
				rows="3"
				maxlength="2000"
				required
				data-testid="leverancier-message-body"
				class="lz-input lz-textarea"
				:placeholder="t('procest', 'Typ je bericht…')" />
			<div class="lz-composer-actions">
				<button type="submit"
					class="lz-button lz-button--primary"
					data-testid="leverancier-message-submit"
					:disabled="sending || !composer.body.trim()">
					{{ sending ? t('procest', 'Bezig…') : t('procest', 'Verstuur') }}
				</button>
				<p v-if="sentMessage"
					class="lz-status lz-status--success"
					role="status"
					data-testid="leverancier-message-status">
					{{ sentMessage }}
				</p>
			</div>
		</form>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { listMessages } from '../../services/leverancierApi.js'

export default {
	name: 'MessageThread',
	components: { NcLoadingIcon },
	data() {
		return {
			messages: [],
			loading: false,
			error: null,
			composer: { body: '' },
			sending: false,
			sentMessage: '',
		}
	},
	computed: {
		supplierRef() { return (this.$route.query && this.$route.query.supplierRef) || '' },
		caseRef() { return (this.$route.query && this.$route.query.caseRef) || '' },
	},
	watch: {
		caseRef: 'reload',
	},
	mounted() { this.reload() },
	methods: {
		async reload() {
			if (!this.supplierRef || !this.caseRef) {
				this.messages = []
				return
			}
			this.loading = true
			this.error = null
			try {
				const r = await listMessages(this.supplierRef, this.caseRef)
				this.messages = (r && r.items) || []
			} catch (e) {
				this.error = this.t('procest', 'Kon berichten niet laden.')
			} finally {
				this.loading = false
			}
		},
		isInbound(m) {
			// Inbound = from supplier; outbound = from handler. Heuristic on sentBy.
			const sender = (m && m.sentBy) || ''
			return sender === 'supplier' || sender === this.supplierRef
		},
		async onSend() {
			if (!this.composer.body.trim() || !this.caseRef) { return }
			this.sending = true
			this.sentMessage = ''
			try {
				const url = generateUrl('/apps/procest/api/leverancier-portaal/messages')
				const r = await axios.post(url, {
					supplierRef: this.supplierRef,
					caseRef: this.caseRef,
					body: this.composer.body,
					attachments: [],
					sentBy: 'supplier',
				})
				if (r && r.data && r.data.ok) {
					this.sentMessage = r.data.message || this.t('procest', 'Bericht verstuurd')
					this.composer.body = ''
					await this.reload()
				}
			} catch (e) {
				this.error = e && e.response && e.response.data && e.response.data.error
					? String(e.response.data.error)
					: this.t('procest', 'Versturen mislukt.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.lz-thread { padding: 20px; max-width: 720px; margin: 0 auto; }
.lz-toolbar { margin-bottom: 16px; }
.lz-section-intro { margin: 0 0 12px 0; color: var(--color-text-maxcontrast, #555); font-size: 13px; }
.lz-state { padding: 40px 20px; text-align: center; }
.lz-state--error { color: var(--color-error, #c00); }
.lz-empty { padding: 24px 0; text-align: center; color: var(--color-text-maxcontrast, #555); }
.lz-bubbles { list-style: none; padding: 0; margin: 0 0 24px 0; display: flex; flex-direction: column; gap: 12px; }
.lz-bubble { padding: 12px 16px; border-radius: 8px; border: 1px solid var(--color-border, #ddd); max-width: 80%; }
.lz-bubble--inbound { background: var(--color-background-hover, #f0f5fb); align-self: flex-start; }
.lz-bubble--outbound { background: var(--color-main-background, #fff); align-self: flex-end; }
.lz-bubble-header { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 4px; font-size: 12px; color: var(--color-text-maxcontrast, #555); }
.lz-bubble-sender { font-weight: 600; }
.lz-bubble-body { margin: 0; white-space: pre-wrap; }
.lz-bubble-attachments { margin: 8px 0 0 0; padding-left: 16px; font-size: 13px; }
.lz-composer { display: flex; flex-direction: column; gap: 8px; padding: 16px; border: 1px solid var(--color-border, #ddd); border-radius: 8px; background: var(--color-main-background, #fff); }
.lz-composer-label { font-weight: 600; font-size: 13px; }
.lz-input { padding: 8px 10px; border: 1px solid var(--color-border-dark, #aaa); border-radius: 4px; font-family: inherit; }
.lz-textarea { resize: vertical; min-height: 60px; }
.lz-composer-actions { display: flex; align-items: center; gap: 12px; }
.lz-button { padding: 8px 16px; border: 1px solid var(--color-border-dark, #aaa); border-radius: 4px; background: var(--color-main-background, #fff); cursor: pointer; }
.lz-button--primary { background: var(--color-primary-element, #0082c9); color: #fff; border-color: var(--color-primary-element, #0082c9); }
.lz-button--primary:disabled { opacity: 0.6; cursor: not-allowed; }
.lz-status { margin: 0; font-size: 13px; }
.lz-status--success { color: var(--color-success, #46ba61); }
</style>
