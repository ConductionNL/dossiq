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
			<p>
				{{
					t('procest', 'This case has not been shared with a partner yet.')
				}}
			</p>
		</div>

		<ul v-else class="share-tab__list">
			<li v-for="share in shares" :key="share.id" class="share-tab__item">
				<div class="share-tab__item-header">
					<span
						class="share-tab__type-badge share-tab__type-badge--partner">
						{{ t('procest', 'Partner') }}
					</span>
					<span class="share-tab__label">{{
						share.label || t('procest', 'Unnamed share')
					}}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{ permissionLabel(share.permissionLevel) }}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton variant="error" @click="$emit('revoke', share.id)">
						{{ t('procest', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<!-- Create partner share / transfer -->
		<div class="share-tab__actions">
			<NcButton variant="primary" @click="$emit('create-partner-share')">
				{{ t('procest', 'Share with partner') }}
			</NcButton>
			<NcButton @click="$emit('transfer-case')">
				{{ t('procest', 'Transfer case') }}
			</NcButton>
		</div>

		<!--
			Federated (cross-instance) case shares — federated-case-collaboration.
			A federated share is always a redacted field/document snapshot,
			never the live case (see design.md §2); the remote org's write
			surface is the async activity stream, not the case itself.
		-->
		<h3 class="share-tab__federated-heading">
			{{ t('procest', 'Federated shares') }}
		</h3>

		<div v-if="federatedLoading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('procest', 'Loading federated shares...') }}
		</div>

		<div v-else-if="federatedShares.length === 0" class="share-tab__empty">
			<p>
				{{
					t(
						'procest',
						'This case has not been shared with a remote organisation yet.',
					)
				}}
			</p>
		</div>

		<ul v-else class="share-tab__list">
			<li
				v-for="share in federatedShares"
				:key="share.id"
				class="share-tab__item">
				<div class="share-tab__item-header">
					<span
						class="share-tab__type-badge share-tab__type-badge--federated">
						{{ t('procest', 'Federated') }}
					</span>
					<span class="share-tab__label">{{ share.remoteCloudId }}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{
						t('procest', 'Shared fields: {fields}', {
							fields: (share.sharedFields || []).join(', '),
						})
					}}</span>
					<span>{{
						t('procest', 'Status: {status}', { status: share.status })
					}}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton @click="$emit('open-activity', share.id)">
						{{ t('procest', 'Activity') }}
					</NcButton>
					<NcButton
						v-if="share.status !== 'revoked'"
						variant="error"
						@click="$emit('revoke-federated', share.id)">
						{{ t('procest', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<div class="share-tab__actions">
			<NcButton variant="primary" @click="$emit('create-federated-share')">
				{{ t('procest', 'Share with remote organisation') }}
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

		/** Federated (cross-instance) case shares — federated-case-collaboration. */
		federatedShares: {
			type: Array,
			default: () => [],
		},

		federatedLoading: {
			type: Boolean,
			default: false,
		},
	},

	emits: [
		'revoke',
		'create-partner-share',
		'transfer-case',
		'create-federated-share',
		'revoke-federated',
		'open-activity',
	],

	methods: {
		/**
		 * @param {string} level the permission level slug.
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

.share-tab__type-badge--federated {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.share-tab__federated-heading {
	margin-top: 24px;
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
