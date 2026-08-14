<template>
	<NcDialog
		:open="open"
		:name="t('procest', 'Federated activity')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="federated-activity-panel">
			<p class="federated-activity-panel__description">
				{{
					t(
						'procest',
						'Async collaboration on this shared case. Entries are append-only and visible to both organisations.',
					)
				}}
			</p>

			<div v-if="loading" class="federated-activity-panel__loading">
				<NcLoadingIcon :size="20" />
				{{ t('procest', 'Loading activity...') }}
			</div>

			<div
				v-else-if="entries.length === 0"
				class="federated-activity-panel__empty">
				<p>{{ t('procest', 'No activity yet.') }}</p>
			</div>

			<ul v-else class="federated-activity-panel__list">
				<li
					v-for="(entry, index) in entries"
					:key="index"
					class="federated-activity-panel__entry">
					<div class="federated-activity-panel__entry-header">
						<span
							class="federated-activity-panel__actor-badge"
							:class="`federated-activity-panel__actor-badge--${entry.actorType}`">
							{{
								entry.actorType === 'remote'
									? t('procest', 'Remote')
									: t('procest', 'Local')
							}}
						</span>
						<span class="federated-activity-panel__actor">{{
							entry.actor
						}}</span>
					</div>
					<p class="federated-activity-panel__message">
						{{ entry.message }}
					</p>
				</li>
			</ul>

			<div class="form-group">
				<label for="federated-activity-message">{{
					t('procest', 'Add a message')
				}}</label>
				<textarea
					id="federated-activity-message"
					v-model="message"
					rows="3"
					:placeholder="
						t('procest', 'Write a note visible to both organisations...')
					" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('procest', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!message.trim() || posting"
				@click="post">
				{{ posting ? t('procest', 'Posting...') : t('procest', 'Post') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'FederatedActivityPanel',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		federatedShareId: {
			type: String,
			required: true,
		},

		entries: {
			type: Array,
			default: () => [],
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'post'],
	data() {
		return {
			message: '',
			posting: false,
		}
	},

	methods: {
		/**
		 * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
		 */
		async post() {
			this.posting = true
			try {
				this.$emit('post', {
					federatedShareId: this.federatedShareId,
					message: this.message.trim(),
				})
				this.message = ''
			} finally {
				this.posting = false
			}
		},
	},
}
</script>

<style scoped>
.federated-activity-panel__description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.federated-activity-panel__loading,
.federated-activity-panel__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.federated-activity-panel__list {
	list-style: none;
	margin: 0 0 16px;
	padding: 0;
	max-height: 260px;
	overflow-y: auto;
}

.federated-activity-panel__entry {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.federated-activity-panel__entry-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.federated-activity-panel__actor-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}

.federated-activity-panel__actor-badge--local {
	background: var(--color-success-hover);
	color: var(--color-success-text);
}

.federated-activity-panel__actor-badge--remote {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.federated-activity-panel__actor {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.federated-activity-panel__message {
	margin: 0;
}

.federated-activity-panel .form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.federated-activity-panel textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
}
</style>
