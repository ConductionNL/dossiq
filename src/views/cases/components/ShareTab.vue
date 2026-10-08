<template>
	<div class="share-tab">
		<!--
			Links to people with no account. Each one is an OpenRegister
			access link (#3817): OpenRegister owns the address, the expiry,
			the password and the revoke, and records every use on the case
			as `link:<uuid>`.
		-->
		<h3>{{ t('dossiq', 'Links') }}</h3>

		<div v-if="linksLoading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Loading links') }}
		</div>

		<div v-else-if="links.length === 0" class="share-tab__empty">
			<p>{{ t('dossiq', 'Nobody outside can open this case yet.') }}</p>
		</div>

		<ul v-else class="share-tab__list">
			<li v-for="link in links" :key="link.id" class="share-tab__item">
				<div class="share-tab__item-header">
					<span
						class="share-tab__type-badge"
						:class="`share-tab__type-badge--${link.state}`">
						{{ stateLabel(link.state) }}
					</span>
					<span class="share-tab__label">{{
						link.label || t('dossiq', 'Unnamed link')
					}}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{ capabilityLabel(link.capabilities) }}</span>
					<span>{{
						t('dossiq', 'Created by {who}', { who: link.createdBy })
					}}</span>
					<span v-if="link.expiresAt">{{
						t('dossiq', 'Stops working on {date}', {
							date: link.expiresAt,
						})
					}}</span>
					<span v-if="link.advisoryBody">{{
						t('dossiq', 'Asked for advice from {body}', {
							body: link.advisoryBody,
						})
					}}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton @click="$emit('previewLink', link)">
						{{ t('dossiq', 'See what they see') }}
					</NcButton>
					<NcButton
						v-if="link.state === 'live' || link.state === 'paused'"
						@click="$emit('pauseLink', link)">
						{{
							link.state === 'paused'
								? t('dossiq', 'Switch back on')
								: t('dossiq', 'Switch off')
						}}
					</NcButton>
					<NcButton
						v-if="link.state !== 'revoked'"
						variant="error"
						@click="$emit('revokeLink', link)">
						{{ t('dossiq', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<div class="share-tab__actions">
			<NcButton variant="primary" @click="$emit('createLink')">
				{{ t('dossiq', 'Share by link') }}
			</NcButton>
		</div>

		<h3 class="share-tab__partner-heading">
			{{ t('dossiq', 'Partner shares') }}
		</h3>

		<!--
			Partner-organisation handovers only (zaak-domain). Public
			"track your case" token links live in OpenRegister's shares
			integration leaf (ADR-022) — minted/listed/revoked there, not
			in this tab. The bespoke token-share rows were removed by
			migrate-public-share-to-shares-leaf.
		-->
		<div v-if="loading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Loading shares...') }}
		</div>

		<div v-else-if="shares.length === 0" class="share-tab__empty">
			<p>
				{{
					t('dossiq', 'This case has not been shared with a partner yet.')
				}}
			</p>
		</div>

		<ul v-else class="share-tab__list">
			<li v-for="share in shares" :key="share.id" class="share-tab__item">
				<div class="share-tab__item-header">
					<span
						class="share-tab__type-badge share-tab__type-badge--partner">
						{{ t('dossiq', 'Partner') }}
					</span>
					<span class="share-tab__label">{{
						share.label || t('dossiq', 'Unnamed share')
					}}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{ permissionLabel(share.permissionLevel) }}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton type="error" @click="$emit('revoke', share.id)">
						{{ t('dossiq', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<!-- Create partner share / transfer -->
		<div class="share-tab__actions">
			<NcButton type="primary" @click="$emit('create-partner-share')">
				{{ t('dossiq', 'Share with partner') }}
			</NcButton>
			<NcButton @click="$emit('transfer-case')">
				{{ t('dossiq', 'Transfer case') }}
			</NcButton>
		</div>

		<!--
			Federated (cross-instance) case shares — federated-case-collaboration.
			A federated share is always a redacted field/document snapshot,
			never the live case (see design.md §2); the remote org's write
			surface is the async activity stream, not the case itself.
		-->
		<h3 class="share-tab__federated-heading">
			{{ t('dossiq', 'Federated shares') }}
		</h3>

		<div v-if="federatedLoading" class="share-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Loading federated shares...') }}
		</div>

		<div v-else-if="federatedShares.length === 0" class="share-tab__empty">
			<p>
				{{
					t(
						'dossiq',
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
						{{ t('dossiq', 'Federated') }}
					</span>
					<span class="share-tab__label">{{ share.remoteCloudId }}</span>
				</div>
				<div class="share-tab__item-details">
					<span>{{
						t('dossiq', 'Shared fields: {fields}', {
							fields: (share.sharedFields || []).join(', '),
						})
					}}</span>
					<span>{{
						t('dossiq', 'Status: {status}', { status: share.status })
					}}</span>
				</div>
				<div class="share-tab__item-actions">
					<NcButton @click="$emit('open-activity', share.id)">
						{{ t('dossiq', 'Activity') }}
					</NcButton>
					<NcButton
						v-if="share.status !== 'revoked'"
						type="error"
						@click="$emit('revoke-federated', share.id)">
						{{ t('dossiq', 'Revoke') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<div class="share-tab__actions">
			<NcButton type="primary" @click="$emit('create-federated-share')">
				{{ t('dossiq', 'Share with remote organisation') }}
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

		/** Access links on this case, each carrying its own `state`. */
		links: {
			type: Array,
			default: () => [],
		},

		linksLoading: {
			type: Boolean,
			default: false,
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
		'createLink',
		'revokeLink',
		'pauseLink',
		'previewLink',
		'create-partner-share',
		'transfer-case',
		'create-federated-share',
		'revoke-federated',
		'open-activity',
	],

	methods: {
		/**
		 * @param {string} state one of live, paused, expired, revoked.
		 * @return {string} what the handler reads on the badge.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
		 */
		stateLabel(state) {
			const labels = {
				live: t('dossiq', 'Open'),
				paused: t('dossiq', 'Switched off'),
				expired: t('dossiq', 'Expired'),
				revoked: t('dossiq', 'Revoked'),
			}
			return labels[state] || state
		},

		/**
		 * @param {string} capabilities the comma-separated capability list.
		 * @return {string} what the holder may do, in words.
		 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
		 */
		capabilityLabel(capabilities) {
			const labels = {
				read: t('dossiq', 'read'),
				comment: t('dossiq', 'comment'),
				upload: t('dossiq', 'add a document'),
			}
			const granted = String(capabilities || 'read')
				.split(',')
				.map((entry) => labels[entry.trim()] || entry.trim())
				.filter((entry) => entry !== '')
			return t('dossiq', 'The holder may {what}', {
				what: granted.join(', '),
			})
		},

		/**
		 * @param {string} level the permission level slug.
		 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.2
		 */
		permissionLabel(level) {
			const labels = {
				bekijken: t('dossiq', 'View only'),
				bekijken_reageren: t('dossiq', 'View + Comment'),
				bekijken_bijdragen: t('dossiq', 'View + Contribute'),
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

.share-tab__partner-heading {
	margin-top: 24px;
}

.share-tab__type-badge--live {
	background: var(--color-success-hover);
	color: var(--color-success-text);
}

.share-tab__type-badge--paused,
.share-tab__type-badge--expired {
	background: var(--color-warning-hover);
	color: var(--color-warning-text);
}

.share-tab__type-badge--revoked {
	background: var(--color-error-hover);
	color: var(--color-error-text);
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
