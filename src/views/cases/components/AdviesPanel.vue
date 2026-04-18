<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="advies-panel">
		<div class="advies-panel__header">
			<h3>{{ t('procest', 'Advice') }}</h3>
			<NcButton @click="showDialog = true">
				{{ t('procest', 'Request Advice') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="advies-panel__content">
			<CnEmptyState
				v-if="requests.length === 0"
				:name="t('procest', 'Advice Requests')"
				:description="t('procest', 'No advice requests made yet')"
				icon="CommentQuestion" />

			<div v-else class="advies-panel__list">
				<div
					v-for="request in requests"
					:key="request.id || request.uuid"
					class="advies-panel__item"
					:class="{ 'advies-panel__item--overdue': isOverdue(request) }">
					<div class="advies-panel__main">
						<div class="advies-panel__info">
							<span class="advies-panel__adviseur">{{ request.adviseur }}</span>
							<CnStatusBadge
								:label="getTypeLabel(request.type)"
								:status="request.type === 'intern' ? 'default' : 'info'" />
							<CnStatusBadge
								:label="getStatusLabel(request.status)"
								:status="getStatusColor(request.status)" />
							<span
								v-if="request.deadline"
								class="advies-panel__deadline"
								:class="{ 'advies-panel__deadline--overdue': isOverdue(request) }">
								<template v-if="isOverdue(request)">
									{{ t('procest', '{days} days overdue', { days: Math.abs(getDaysUntilDeadline(request)) }) }}
								</template>
								<template v-else>
									{{ t('procest', 'Due {date}', { date: formatDate(request.deadline) }) }}
								</template>
							</span>
						</div>
						<p v-if="request.onderwerp" class="advies-panel__subject">
							{{ request.onderwerp }}
						</p>
					</div>

					<CnRowActions>
						<NcActionLink
							v-if="request.status === 'aangevraagd'"
							icon="Send"
							@click="sendReminder(request)">
							{{ t('procest', 'Send Reminder') }}
						</NcActionLink>
						<NcActionLink
							v-if="request.status === 'aangevraagd' && request.adviesDocument"
							icon="Eye"
							@click="viewDocument(request)">
							{{ t('procest', 'Mark as Received') }}
						</NcActionLink>
						<NcActionLink
							v-if="request.status === 'ontvangen' && request.adviesDocument"
							icon="Download"
							@click="downloadDocument(request)">
							{{ t('procest', 'View Advice') }}
						</NcActionLink>
					</CnRowActions>
				</div>
			</div>
		</div>

		<AdviesAanvraagDialog
			v-if="showDialog"
			:case-id="caseId"
			@created="onAdviceCreated"
			@close="showDialog = false" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcActionLink } from '@conduction/nextcloud-vue'
import CnEmptyState from '../../../components/CnEmptyState.vue'
import CnStatusBadge from '../../../components/CnStatusBadge.vue'
import CnRowActions from '../../../components/CnRowActions.vue'
import AdviesAanvraagDialog from './AdviesAanvraagDialog.vue'
import { getAdviceForCase, sendReminder } from '../../../services/adviceApi'
import { useI18n } from 'vue-i18n'

export default {
	name: 'AdviesPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcActionLink,
		CnEmptyState,
		CnStatusBadge,
		CnRowActions,
		AdviesAanvraagDialog,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},
	setup() {
		const { t } = useI18n()
		return { t }
	},
	data() {
		return {
			requests: [],
			loading: false,
			showDialog: false,
		}
	},
	watch: {
		caseId: {
			handler: 'fetchAdvice',
			immediate: true,
		},
	},
	methods: {
		async fetchAdvice() {
			if (!this.caseId) {
				return
			}

			this.loading = true

			try {
				const response = await getAdviceForCase(this.caseId)
				this.requests = response.data || response || []
			} catch (error) {
				console.error('Failed to fetch advice:', error)
				this.requests = []
			} finally {
				this.loading = false
			}
		},

		onAdviceCreated(advice) {
			this.requests.push(advice)
			this.showDialog = false
		},

		isOverdue(request) {
			if (!request.deadline || request.status !== 'aangevraagd') {
				return false
			}
			return new Date(request.deadline) < new Date()
		},

		getDaysUntilDeadline(request) {
			if (!request.deadline) {
				return null
			}
			const deadline = new Date(request.deadline)
			const today = new Date()
			const diff = deadline - today
			return Math.ceil(diff / (1000 * 60 * 60 * 24))
		},

		getTypeLabel(type) {
			return type === 'intern' ? this.t('procest', 'Internal') : this.t('procest', 'External')
		},

		getStatusLabel(status) {
			const labels = {
				aangevraagd: this.t('procest', 'Requested'),
				ontvangen: this.t('procest', 'Received'),
				verlopen: this.t('procest', 'Expired'),
			}
			return labels[status] || status
		},

		getStatusColor(status) {
			const colors = {
				aangevraagd: 'info',
				ontvangen: 'success',
				verlopen: 'error',
			}
			return colors[status] || 'default'
		},

		formatDate(dateString) {
			try {
				const date = new Date(dateString)
				return date.toLocaleDateString(this.$i18n.locale, {
					year: 'numeric',
					month: 'long',
					day: 'numeric',
				})
			} catch {
				return dateString
			}
		},

		async sendReminder(request) {
			try {
				await sendReminder(request.id || request.uuid)
				this.$notify({
					title: this.t('procest', 'Reminder sent'),
					type: 'success',
				})
			} catch (error) {
				console.error('Failed to send reminder:', error)
				this.$notify({
					title: this.t('procest', 'Failed to send reminder'),
					type: 'error',
				})
			}
		},

		viewDocument(request) {
			// Placeholder for opening document viewer
			console.log('View document:', request.adviesDocument)
		},

		downloadDocument(request) {
			// Placeholder for downloading document
			console.log('Download document:', request.adviesDocument)
		},
	},
}
</script>

<style scoped lang="scss">
.advies-panel {
	padding: 20px;
	background: var(--color-background-secondary);
	border-radius: 8px;
	margin-bottom: 20px;

	&__header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 20px;

		h3 {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
		}
	}

	&__content {
		min-height: 200px;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__item {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		padding: 12px;
		background: var(--color-background-primary);
		border: 1px solid var(--color-border);
		border-radius: 6px;
		transition: all 0.2s;

		&:hover {
			background: var(--color-background-hover);
		}

		&--overdue {
			border-color: var(--color-error);
			background: rgba(255, 0, 0, 0.03);
		}
	}

	&__main {
		flex: 1;
	}

	&__info {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		flex-wrap: wrap;
	}

	&__adviseur {
		font-weight: 600;
		color: var(--color-text-primary);
	}

	&__subject {
		margin: 8px 0 0;
		color: var(--color-text-secondary);
		font-size: 13px;
		line-height: 1.4;
	}

	&__deadline {
		font-size: 12px;
		color: var(--color-text-secondary);
		padding: 2px 6px;
		background: var(--color-background-secondary);
		border-radius: 3px;

		&--overdue {
			color: var(--color-error);
			background: rgba(255, 0, 0, 0.1);
		}
	}
}
</style>
