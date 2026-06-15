<template>
	<div class="share-tab">
		<h3>{{ t('procest', 'Partner shares') }}</h3>

		<!--
			Partner-organisation handovers only (zaak-domain). Public
			"track your case" token links live in OpenRegister's shares
			integration leaf (ADR-022) — minted/listed/revoked there, not
			in this tab. The bespoke token-share rows were removed by
			migrate-public-share-to-shares-leaf.
		-->
		<div v-if="loading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('procest', 'Loading shares...') }}
		</div>

		<div v-else-if="shares.length === 0" class="share-tab__empty">
			<p>{{ t('procest', 'This case has not been shared with a partner yet.') }}</p>
		</div>

		<ul v-else class="share-tab__list">
			<li v-for="share in shares" :key="share.id" class="share-tab__item">
				<div class="share-tab__item-header">
					<span class="share-tab__type-badge share-tab__type-badge--partner">
						{{ t('procest', 'Partner') }}
					</span>
					<span class="share-tab__label">{{ share.label || t('procest', 'Unnamed share') }}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{ permissionLabel(share.permissionLevel) }}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton type="error" @click="$emit('revoke', share.id)">
						{{ t('procest', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<!-- Create partner share -->
		<div class="share-tab__actions">
			<NcButton type="primary" @click="$emit('create-partner-share')">
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
	emits: ['revoke', 'create-partner-share'],
	methods: {
		/**
		 * @param level
		 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.2
		 */
		permissionLabel(level) {
			const labels = {
				bekijken: t('procest', 'View only'),
				bekijken_reageren: t('procest', 'View + Comment'),
				bekijken_bijdragen: t('procest', 'View + Contribute'),
			}
			return labels[level] || level
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
