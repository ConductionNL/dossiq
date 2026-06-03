<template>
	<div class="share-tab">
		<h3>{{ t('procest', 'Shares') }}</h3>

		<!-- Active shares list -->
		<div v-if="loading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('procest', 'Loading shares...') }}
		</div>

		<div v-else-if="shares.length === 0" class="share-tab__empty">
			<p>{{ t('procest', 'This case has not been shared yet.') }}</p>
		</div>

		<ul v-else class="share-tab__list">
			<li v-for="share in shares" :key="share.id" class="share-tab__item">
				<div class="share-tab__item-header">
					<span class="share-tab__type-badge" :class="`share-tab__type-badge--${share.shareType}`">
						{{ share.shareType === 'token' ? t('procest', 'Link') : t('procest', 'Partner') }}
					</span>
					<span class="share-tab__label">{{ share.label || t('procest', 'Unnamed share') }}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{ permissionLabel(share.permissionLevel) }}</span>
					<span v-if="share.expiresAt" class="share-tab__expires">
						{{ t('procest', 'Expires: {date}', { date: formatDate(share.expiresAt) }) }}
					</span>
					<span v-if="share.lastAccessedAt" class="share-tab__accessed">
						{{ t('procest', 'Last accessed: {date}', { date: formatDate(share.lastAccessedAt) }) }}
					</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton type="error" @click="$emit('revoke', share.id)">
						{{ t('procest', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<!-- Create share buttons -->
		<div class="share-tab__actions">
			<NcButton type="primary" @click="$emit('create-token-share')">
				{{ t('procest', 'Create share link') }}
			</NcButton>
			<NcButton @click="$emit('create-partner-share')">
				{{ t('procest', 'Share with partner') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ShareTab',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		shares: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['revoke', 'create-token-share', 'create-partner-share'],
	methods: {
		/**
		 * @param level
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		permissionLabel(level) {
			const labels = {
				bekijken: t('procest', 'View only'),
				bekijken_reageren: t('procest', 'View + Comment'),
				bekijken_bijdragen: t('procest', 'View + Contribute'),
			}
			return labels[level] || level
		},
		/**
		 * @param dateString
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDate(dateString) {
			if (!dateString) return ''
			return new Date(dateString).toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.share-tab {
	padding: 12px;
}

.share-tab__list {
	list-style: none;
	padding: 0;
	margin: 0 0 16px;
}

.share-tab__item {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 8px;
}

.share-tab__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.share-tab__type-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: bold;
}

.share-tab__type-badge--token {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.share-tab__type-badge--partner {
	background: var(--color-success-hover);
	color: var(--color-success-text);
}

.share-tab__item-details {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.share-tab__item-actions {
	margin-top: 8px;
}

.share-tab__actions {
	display: flex;
	gap: 8px;
}

.share-tab__loading,
.share-tab__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
