<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="unlinked-queue">
		<h2>{{ t('procest', 'Unlinked inbound emails') }}</h2>
		<p>{{ t('procest', 'Emails that could not be automatically linked to a case.') }}</p>

		<div v-if="loading" class="unlinked-queue__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="items.length === 0" class="unlinked-queue__empty">
			{{ t('procest', 'No unlinked emails in the queue.') }}
		</div>

		<div v-else class="unlinked-queue__list">
			<div v-for="item in items" :key="item.id || item.uuid" class="unlinked-queue__item">
				<div class="unlinked-queue__item-header">
					<strong>{{ item.subject }}</strong>
					<span class="unlinked-queue__date">{{ formatDate(item.receivedAt) }}</span>
				</div>
				<div class="unlinked-queue__meta">
					<span>{{ t('procest', 'From: {from}', { from: item.from }) }}</span>
				</div>
				<div class="unlinked-queue__body">
					{{ truncate(item.body, 200) }}
				</div>

				<div class="unlinked-queue__actions">
					<NcSelect
						v-model="selectedCase[item.id || item.uuid]"
						:options="caseOptions"
						:placeholder="t('procest', 'Search case to link...')"
						label="label"
						track-by="id"
						:searchable="true"
						:input-label="t('procest', 'Case')"
						class="unlinked-queue__case-select" />
					<NcButton
						type="primary"
						:disabled="!selectedCase[item.id || item.uuid]"
						@click="linkEmail(item)">
						{{ t('procest', 'Link') }}
					</NcButton>
					<NcButton
						type="error"
						@click="discardEmail(item)">
						{{ t('procest', 'Discard') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'UnlinkedQueueView',
	components: { NcButton, NcLoadingIcon, NcSelect },
	data() {
		return {
			items: [],
			caseOptions: [],
			selectedCase: {},
			loading: false,
		}
	},
	mounted() {
		this.loadQueue()
	},
	methods: {
		async loadQueue() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/emails/unlinked')
				const { data } = await axios.get(url)
				this.items = data.results || []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to load unlinked queue', e)
			} finally {
				this.loading = false
			}
		},
		async linkEmail(item) {
			const selected = this.selectedCase[item.id || item.uuid]
			if (!selected) return
			try {
				const url = generateUrl('/apps/procest/api/emails/unlinked/' + encodeURIComponent(item.id || item.uuid) + '/link')
				await axios.post(url, { caseId: selected.id })
				await this.loadQueue()
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to link email', e)
			}
		},
		async discardEmail(item) {
			try {
				const url = generateUrl('/apps/procest/api/emails/unlinked/' + encodeURIComponent(item.id || item.uuid) + '/discard')
				await axios.post(url)
				await this.loadQueue()
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to discard email', e)
			}
		},
		truncate(text, len) {
			if (!text || text.length <= len) return text || ''
			return text.substring(0, len) + '...'
		},
		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped>
.unlinked-queue__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.unlinked-queue__item-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 4px;
}

.unlinked-queue__date {
	font-size: 0.75rem;
	color: var(--color-text-lighter);
}

.unlinked-queue__meta {
	font-size: 0.875rem;
	color: var(--color-text-lighter);
	margin-bottom: 8px;
}

.unlinked-queue__body {
	font-size: 0.875rem;
	margin-bottom: 12px;
	white-space: pre-line;
}

.unlinked-queue__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.unlinked-queue__case-select {
	flex: 1;
}
</style>
